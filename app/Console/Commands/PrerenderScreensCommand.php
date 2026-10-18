<?php

namespace App\Console\Commands;

use App\Actions\PrerenderDeviceScreens;
use App\Models\Device;
use App\Services\ImageGenerationService;
use Illuminate\Console\Command;

class PrerenderScreensCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trmnl:screens:prerender {--device=* : Only these device ids}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh plugin data and render playlist screens ahead of device requests';

    /**
     * Execute the console command.
     */
    public function handle(PrerenderDeviceScreens $prerender): int
    {
        $devices = Device::query()
            ->when($this->option('device'), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->get();

        $rendered = $devices->sum(fn (Device $device): int => $prerender->handle($device));

        if ($rendered > 0) {
            ImageGenerationService::cleanupFolder();
        }

        $this->info("Prerendered {$rendered} screen(s) for {$devices->count()} device(s).");

        return self::SUCCESS;
    }
}
