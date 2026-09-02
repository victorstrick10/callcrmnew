<?php

namespace App\Console\Commands;

use App\Services\AppointmentService;
use Illuminate\Console\Command;

class PrepareGeoProxiesCommand extends Command
{
    protected $signature = 'proxies:prepare-geo {--limit=20}';

    protected $description = 'Auto-generate Multilogin GEO proxies for upcoming scheduled calls so they are ready';

    public function handle(AppointmentService $service): int
    {
        $result = $service->prepareGeoProxies((int) $this->option('limit'));

        $this->info("GEO proxy prep: {$result['ready']} ready, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
