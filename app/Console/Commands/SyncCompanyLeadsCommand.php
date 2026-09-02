<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AppointmentSyncService;
use App\Services\LeadSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncCompanyLeadsCommand extends Command
{
    protected $signature = 'leads:sync {company? : Company slug} {--skip-calendly : Only sync lead API}';

    protected $description = 'Sync leads from company APIs and resolve all Calendly call times per lead';

    public function handle(LeadSyncService $leads, AppointmentSyncService $appointments): int
    {
        @set_time_limit(600);
        ini_set('max_execution_time', '600');

        $slug = $this->argument('company');
        $query = Company::query()->where('enabled', true);
        if ($slug) {
            $query->where('slug', $slug);
        }

        $companies = $query->get();
        if ($companies->isEmpty()) {
            $this->warn('No enabled companies found.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $this->info("Syncing leads: {$company->slug}");
            try {
                $leadStats = $leads->syncCompany($company);
                $this->line(json_encode($leadStats));
            } catch (Throwable $e) {
                $this->error("Lead sync failed for {$company->slug}: ".$e->getMessage());
                continue;
            }

            if ($this->option('skip-calendly')) {
                continue;
            }

            if (! $company->getCalendlyApiToken()) {
                $this->warn("Calendly skipped for {$company->slug}: missing API token (set it on Companies → Edit).");
                continue;
            }

            $this->info("Syncing Calendly appointments: {$company->slug}");
            try {
                $apptStats = $appointments->syncCompany($company);
                $this->line(json_encode($apptStats));
            } catch (Throwable $e) {
                $this->error("Calendly sync failed for {$company->slug}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
