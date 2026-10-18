<?php

namespace App\Actions;

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Plugins\Enums\PluginOutput;
use App\Services\ImageGenerationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh data and render a device's active playlist items ahead of time, so
 * /api/display can answer from cache within the firmware's read timeout.
 * Never changes what the device is currently showing.
 *
 * All due data is fetched before any mashup is rendered: a member refreshed
 * after its mashup was drawn would make that render outdated straight away.
 */
class PrerenderDeviceScreens
{
    /**
     * @return int Number of images rendered
     */
    public function handle(Device $device): int
    {
        if ($device->mirror_device_id !== null) {
            return 0;
        }

        $leadMinutes = (int) config('services.trmnl.prerender_lead_minutes', 2);
        [$mashups, $singles] = $this->activeItems($device)->partition(fn (PlaylistItem $item): bool => $item->isMashup());

        $singlePlugins = $singles->map(fn (PlaylistItem $item) => $item->plugin)->filter()->unique('id');
        $memberPlugins = Plugin::whereIn('id', $mashups->flatMap(fn (PlaylistItem $item) => $item->getMashupPluginIds())->unique())
            ->whereNotIn('id', $singlePlugins->pluck('id'))
            ->get();

        $rendered = 0;

        foreach ($singlePlugins as $plugin) {
            $rendered += $this->attempt("plugin {$plugin->id}", fn (): int => $this->prerenderPlugin($device, $plugin, $leadMinutes));
        }

        foreach ($memberPlugins as $plugin) {
            $this->attempt("plugin {$plugin->id}", function () use ($plugin, $leadMinutes): int {
                if ($this->isDue($plugin, $leadMinutes)) {
                    $plugin->updateDataPayload();
                }

                return 0;
            });
        }

        foreach ($mashups as $item) {
            $rendered += $this->attempt("playlist item {$item->id}", fn (): int => $this->prerenderMashup($device, $item, $leadMinutes));
        }

        return $rendered;
    }

    /**
     * @return Collection<int, PlaylistItem>
     */
    private function activeItems(Device $device): Collection
    {
        return $device->playlists()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Playlist $playlist): bool => $playlist->isActiveNow())
            ->flatMap(fn (Playlist $playlist) => $playlist->items()->where('is_active', true)->get())
            ->values();
    }

    /**
     * Fetch, render and store a single plugin's data and image in one save, so the
     * plugin never sits without an image while it is re-rendered.
     */
    private function prerenderPlugin(Device $device, Plugin $plugin, int $leadMinutes): int
    {
        if (in_array($plugin->handler()?->output(), [PluginOutput::ProcessedImage, PluginOutput::Image], true)) {
            return 0;
        }

        ImageGenerationService::resetIfNotCacheable($plugin, $device);
        $plugin->refresh();

        $payload = $this->isDue($plugin, $leadMinutes) ? $plugin->fetchDataPayload() : null;
        $rerender = $payload !== null
            || $plugin->current_image === null
            || ($plugin->data_strategy !== 'polling' && $this->isDue($plugin, $leadMinutes, staticToo: true));

        if (! $rerender) {
            return 0;
        }

        if ($payload !== null) {
            $plugin->data_payload = $payload;
        }

        if (($plugin->data_payload['TRMNL_SKIP_DISPLAY'] ?? false) === true) {
            $plugin->forceFill(['data_payload_updated_at' => now()])->save();

            return 0;
        }

        $plugin->forceFill([
            'data_payload_updated_at' => now(),
            'current_image' => ImageGenerationService::generateImageForDevice($device, $plugin->render(device: $device), $plugin),
            'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
        ])->save();

        return 1;
    }

    private function prerenderMashup(Device $device, PlaylistItem $item, int $leadMinutes): int
    {
        if ($item->cachedMashupImage($device, $leadMinutes) !== null) {
            return 0;
        }

        $imageUuid = ImageGenerationService::generateImageForDevice($device, $item->render(device: $device), null, $item->current_image);
        $item->rememberMashupImage($device, $imageUuid);

        return 1;
    }

    /**
     * Whether the plugin's data goes stale within $leadMinutes. Static plugins
     * have no data to fetch, but with $staticToo they are re-rendered on the same
     * clock so date-dependent markup stays current.
     */
    private function isDue(Plugin $plugin, int $leadMinutes, bool $staticToo = false): bool
    {
        $polls = $plugin->data_strategy === 'polling' && $plugin->polling_url;
        if (! $polls && ! $staticToo) {
            return false;
        }

        $staleMinutes = $plugin->data_stale_minutes ?: ($polls ? null : (int) config('services.trmnl.mashup_cache_minutes', 15));
        if (! $staleMinutes) {
            return false;
        }

        return $plugin->data_payload_updated_at === null
            || $plugin->data_payload_updated_at->addMinutes(max(0, $staleMinutes - $leadMinutes))->isPast();
    }

    private function attempt(string $what, callable $work): int
    {
        try {
            return $work();
        } catch (Throwable $e) {
            Log::warning("Prerender failed for {$what}: ".$e->getMessage());

            return 0;
        }
    }
}
