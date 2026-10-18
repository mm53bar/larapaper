<?php

use App\Models\Device;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Plugin;
use App\Services\ImageGenerationService;
use Bnussbau\EpaperPipeline\EpaperPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    EpaperPipeline::fake();
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('/images/generated');
});

function prerenderDevice(array $attributes = []): Device
{
    return Device::factory()->create([
        'mac_address' => '00:11:22:33:44:55',
        'api_key' => 'test-api-key',
        'proxy_cloud' => false,
        'current_screen_image' => null,
        ...$attributes,
    ]);
}

function prerenderPlaylist(Device $device): Playlist
{
    return Playlist::factory()->create([
        'device_id' => $device->id,
        'is_active' => true,
        'weekdays' => null,
        'active_from' => null,
        'active_until' => null,
    ]);
}

function prerenderPlugin(array $attributes = []): Plugin
{
    return Plugin::factory()->create([
        'data_strategy' => 'static',
        'polling_url' => null,
        'data_stale_minutes' => 15,
        'data_payload' => ['value' => 'cached'],
        'data_payload_updated_at' => now(),
        'render_markup_view' => 'trmnl',
        ...$attributes,
    ]);
}

function requestDisplay(Device $device): void
{
    test()->withHeaders([
        'id' => $device->mac_address,
        'access-token' => $device->api_key,
        'fw-version' => '1.0.0',
    ])->get('/api/display')->assertOk();
}

test('display serves a current cached mashup image without rendering', function (): void {
    $device = prerenderDevice();
    $plugins = collect([prerenderPlugin(), prerenderPlugin()]);
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', $plugins->pluck('id')->all(), 'Mashup', 1);
    Storage::disk('public')->put('images/generated/cached-mashup.png', 'png');
    $item->rememberMashupImage($device, 'cached-mashup');

    requestDisplay($device);

    expect($device->fresh()->current_screen_image)->toBe('cached-mashup')
        ->and($item->fresh()->last_displayed_at)->not->toBeNull();
});

test('display re-renders a cached mashup when a member plugin changes', function (): void {
    $device = prerenderDevice();
    $plugins = collect([prerenderPlugin(), prerenderPlugin()]);
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', $plugins->pluck('id')->all(), 'Mashup', 1);
    $item->rememberMashupImage($device, 'cached-mashup');

    $plugins->first()->update(['data_payload' => ['value' => 'changed']]);

    requestDisplay($device);

    $item->refresh();
    expect($device->fresh()->current_screen_image)->not->toBe('cached-mashup')
        ->and($item->current_image)->toBe($device->fresh()->current_screen_image)
        ->and($item->render_fingerprint)->toBe($item->mashupRenderFingerprint($device->fresh()));
});

test('display re-renders a cached mashup older than its freshest member allows', function (): void {
    $device = prerenderDevice();
    $plugins = collect([prerenderPlugin(['data_stale_minutes' => 15]), prerenderPlugin(['data_stale_minutes' => 60])]);
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', $plugins->pluck('id')->all(), 'Mashup', 1);
    $item->rememberMashupImage($device, 'cached-mashup');
    $item->update(['rendered_at' => now()->subMinutes(16)]);

    requestDisplay($device);

    expect($device->fresh()->current_screen_image)->not->toBe('cached-mashup');
});

test('image cleanup keeps cached mashup images', function (): void {
    $device = prerenderDevice();
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [prerenderPlugin()->id, prerenderPlugin()->id], 'Mashup', 1);
    $item->rememberMashupImage($device, 'cached-mashup');
    Storage::disk('public')->put('images/generated/cached-mashup.png', 'png');
    Storage::disk('public')->put('images/generated/orphan.png', 'png');

    ImageGenerationService::cleanupFolder();

    expect(Storage::disk('public')->exists('images/generated/cached-mashup.png'))->toBeTrue()
        ->and(Storage::disk('public')->exists('images/generated/orphan.png'))->toBeFalse();
});

test('prerender renders mashups without changing what the device shows', function (): void {
    $device = prerenderDevice(['current_screen_image' => 'on-screen']);
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [prerenderPlugin()->id, prerenderPlugin()->id], 'Mashup', 1);

    $this->artisan('trmnl:screens:prerender')->assertSuccessful();

    $item->refresh();
    expect($item->current_image)->not->toBeNull()
        ->and($item->cachedMashupImage($device->fresh()))->toBe($item->current_image)
        ->and($device->fresh()->current_screen_image)->toBe('on-screen');
});

test('prerender leaves a current mashup cache alone', function (): void {
    $device = prerenderDevice();
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [prerenderPlugin()->id, prerenderPlugin()->id], 'Mashup', 1);
    $item->rememberMashupImage($device, 'cached-mashup');

    $this->artisan('trmnl:screens:prerender')->expectsOutputToContain('Prerendered 0 screen(s)');

    expect($item->fresh()->current_image)->toBe('cached-mashup');
});

test('prerender fetches polled data shortly before it goes stale', function (): void {
    Http::fake(['example.com/*' => Http::response(['value' => 'fetched'])]);
    config(['services.trmnl.prerender_lead_minutes' => 2]);

    $device = prerenderDevice();
    $due = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/due', 'polling_verb' => 'get', 'data_payload_updated_at' => now()->subMinutes(14)]);
    $fresh = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/fresh', 'polling_verb' => 'get', 'data_payload_updated_at' => now()->subMinutes(5)]);
    PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [$due->id, $fresh->id], 'Mashup', 1);

    $this->artisan('trmnl:screens:prerender')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/due');
    expect($due->fresh()->data_payload)->toBe(['value' => 'fetched'])
        ->and($fresh->fresh()->data_payload)->toBe(['value' => 'cached']);
});

test('prerender renders single plugins and records the device they were rendered for', function (): void {
    $device = prerenderDevice(['current_screen_image' => 'on-screen']);
    $plugin = prerenderPlugin(['current_image' => null]);
    PlaylistItem::factory()->create(['playlist_id' => prerenderPlaylist($device)->id, 'plugin_id' => $plugin->id, 'is_active' => true, 'mashup' => null]);

    $this->artisan('trmnl:screens:prerender')->assertSuccessful();

    $plugin->refresh();
    expect($plugin->current_image)->not->toBeNull()
        ->and(ImageGenerationService::imageMetadataMatches($plugin->current_image_metadata, $device->fresh()))->toBeTrue()
        ->and($device->fresh()->current_screen_image)->toBe('on-screen');
});

test('prerender skips mirrored devices', function (): void {
    $source = prerenderDevice();
    $mirror = prerenderDevice(['mac_address' => '66:77:88:99:AA:BB', 'api_key' => 'mirror-key', 'mirror_device_id' => $source->id]);
    $item = PlaylistItem::createMashup(prerenderPlaylist($mirror), '1Lx1R', [prerenderPlugin()->id, prerenderPlugin()->id], 'Mashup', 1);

    $this->artisan('trmnl:screens:prerender', ['--device' => [$mirror->id]])->assertSuccessful();

    expect($item->fresh()->current_image)->toBeNull();
});

test('with prerendering on, display serves an outdated mashup image instead of rendering', function (): void {
    Http::fake(['example.com/*' => Http::response(['value' => 'fetched'])]);
    config(['services.trmnl.prerender_enabled' => true]);

    $device = prerenderDevice();
    $polled = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/a', 'polling_verb' => 'get', 'data_payload_updated_at' => now()->subMinutes(20)]);
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [$polled->id, prerenderPlugin()->id], 'Mashup', 1);
    Storage::disk('public')->put('images/generated/cached-mashup.png', 'png');
    $item->rememberMashupImage($device, 'cached-mashup');
    $polled->update(['data_payload' => ['value' => 'changed since render']]);

    requestDisplay($device);

    Http::assertNothingSent();
    expect($device->fresh()->current_screen_image)->toBe('cached-mashup');
});

test('with prerendering on, display still re-renders a mashup cached for another screen format', function (): void {
    config(['services.trmnl.prerender_enabled' => true]);

    $device = prerenderDevice();
    $item = PlaylistItem::createMashup(prerenderPlaylist($device), '1Lx1R', [prerenderPlugin()->id, prerenderPlugin()->id], 'Mashup', 1);
    $item->rememberMashupImage($device, 'cached-mashup');
    $item->update(['render_fingerprint' => 'other-format:'.explode(':', $item->render_fingerprint)[1]]);

    requestDisplay($device);

    expect($device->fresh()->current_screen_image)->not->toBe('cached-mashup');
});

test('with prerendering on, display serves a single plugin image without refetching recently stale data', function (): void {
    Http::fake(['example.com/*' => Http::response(['value' => 'fetched'])]);
    config(['services.trmnl.prerender_enabled' => true]);

    $device = prerenderDevice();
    $plugin = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/a', 'polling_verb' => 'get']);
    $plugin->forceFill([
        'current_image' => 'cached-plugin',
        'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
        'data_payload_updated_at' => now()->subMinutes(20),
    ])->save();
    Storage::disk('public')->put('images/generated/cached-plugin.png', 'png');
    PlaylistItem::factory()->create(['playlist_id' => prerenderPlaylist($device)->id, 'plugin_id' => $plugin->id, 'is_active' => true, 'mashup' => null]);

    requestDisplay($device);

    Http::assertNothingSent();
    expect($device->fresh()->current_screen_image)->toBe('cached-plugin');
});

test('prerender stores refreshed data and the new image together', function (): void {
    Http::fake(['example.com/*' => Http::response(['value' => 'fetched'])]);

    $device = prerenderDevice();
    $plugin = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/a', 'polling_verb' => 'get']);
    $plugin->forceFill([
        'current_image' => 'old-image',
        'current_image_metadata' => ImageGenerationService::buildImageMetadataFromDevice($device),
        'data_payload_updated_at' => now()->subMinutes(14),
    ])->save();
    PlaylistItem::factory()->create(['playlist_id' => prerenderPlaylist($device)->id, 'plugin_id' => $plugin->id, 'is_active' => true, 'mashup' => null]);

    $this->artisan('trmnl:screens:prerender')->assertSuccessful();

    $plugin->refresh();
    expect($plugin->data_payload)->toBe(['value' => 'fetched'])
        ->and($plugin->current_image)->not->toBeNull()
        ->and($plugin->current_image)->not->toBe('old-image');
});

test('prerender fetches every due plugin before rendering mashups that share it', function (): void {
    Http::fake(['example.com/*' => Http::response(['value' => 'fetched'])]);

    $device = prerenderDevice();
    $playlist = prerenderPlaylist($device);
    $shared = prerenderPlugin(['data_strategy' => 'polling', 'polling_url' => 'https://example.com/shared', 'polling_verb' => 'get', 'data_payload_updated_at' => now()->subMinutes(14)]);
    $first = PlaylistItem::createMashup($playlist, '1Lx1R', [prerenderPlugin()->id, $shared->id], 'First', 1);
    $second = PlaylistItem::createMashup($playlist, '1Lx1R', [$shared->id, prerenderPlugin()->id], 'Second', 2);

    $this->artisan('trmnl:screens:prerender')->assertSuccessful();

    Http::assertSentCount(1);
    expect($first->fresh()->cachedMashupImage($device->fresh()))->not->toBeNull()
        ->and($second->fresh()->cachedMashupImage($device->fresh()))->not->toBeNull();
});
