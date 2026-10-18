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
        $rendered = 0;

        foreach ($this->activeItems($device) as $item) {
            try {
                $rendered += $item->isMashup()
                    ? $this->prerenderMashup($device, $item, $leadMinutes)
                    : $this->prerenderPlugin($device, $item, $leadMinutes);
            } catch (Throwable $e) {
                Log::warning("Prerender failed for playlist item {$item->id}: ".$e->getMessage());
            }
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
            ->flatMap(fn (Playlist $playlist) => $playlist->items()->where('is_active', true)->get());
    }

    private function prerenderPlugin(Device $device, PlaylistItem $item, int $leadMinutes): int
    {
        $plugin = $item->plugin;
        if (! $plugin || in_array($plugin->handler()?->output(), [PluginOutput::ProcessedImage, PluginOutput::Image], true)) {
            return 0;
        }

        $refreshed = $this->refreshIfDue($plugin, $leadMinutes);

        ImageGenerationService::resetIfNotCacheable($plugin, $device);
        $plugin->refresh();

        if (! $refreshed && $plugin->current_image !== null) {
            return 0;
        }

        if (($plugin->data_payload['TRMNL_SKIP_DISPLAY'] ?? false) === true) {
            return 0;
        }

        $imageUuid = ImageGenerationService::generateImageForDevice($device, $plugin->render(device: $device), $plugin, $plugin->current_image);
        $plugin->update([
            'current_image' => $imageUuid,
            'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
        ]);

        return 1;
    }

    private function prerenderMashup(Device $device, PlaylistItem $item, int $leadMinutes): int
    {
        foreach (Plugin::whereIn('id', $item->getMashupPluginIds())->get() as $plugin) {
            $this->refreshIfDue($plugin, $leadMinutes);
        }

        if ($item->cachedMashupImage($device, $leadMinutes) !== null) {
            return 0;
        }

        $imageUuid = ImageGenerationService::generateImageForDevice($device, $item->render(device: $device), null, $item->current_image);
        $item->rememberMashupImage($device, $imageUuid);

        return 1;
    }

    /**
     * Fetch polled data shortly before it would go stale, so requests never have to.
     */
    private function refreshIfDue(Plugin $plugin, int $leadMinutes): bool
    {
        if ($plugin->data_strategy !== 'polling' || ! $plugin->polling_url || ! $plugin->data_stale_minutes) {
            return false;
        }

        $dueAt = $plugin->data_payload_updated_at?->addMinutes(max(0, $plugin->data_stale_minutes - $leadMinutes));
        if ($dueAt !== null && $dueAt->isFuture()) {
            return false;
        }

        $plugin->updateDataPayload();
        $plugin->refresh();

        return true;
    }
}
