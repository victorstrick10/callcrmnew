<?php

namespace App\Console\Commands;

use App\Services\AppointmentService;
use Illuminate\Console\Command;

class EnrichAppointmentsCommand extends Command
{
    protected $signature = 'ipinfo:enrich {--limit=300}';

    protected $description = 'Bulk-enrich appointment geolocation from ip-api.com for leads that have a captured IP';

    public function handle(AppointmentService $service): int
    {
        $result = $service->enrichPending((int) $this->option('limit'));

        $this->info(sprintf(
            'Geo enrich (ip-api.com): %d enriched, %d failed, %d remaining.',
            $result['enriched'],
            $result['failed'],
            $result['remaining']
        ));

        return self::SUCCESS;
    }
}
