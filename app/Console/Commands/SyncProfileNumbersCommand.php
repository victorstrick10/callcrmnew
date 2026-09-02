<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MultiloginClient;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Throwable;

class SyncProfileNumbersCommand extends Command
{
    protected $signature = 'profiles:sync-numbers';

    protected $description = 'Sync each company\'s Multilogin profile-number inventory (non-destructive in simulation)';

    public function handle(SettingsService $settings, MultiloginClient $multilogin): int
    {
        $companies = Company::query()->get();
        $done = 0;
        $skipped = 0;

        foreach ($companies as $company) {
            if (! $multilogin->isConfiguredFor($company)) {
                $skipped++;

                continue;
            }

            try {
                $result = $settings->syncNumbers($company);
                $done++;
                $this->info(sprintf(
                    '%s: %d numbers marked from %d profiles.',
                    $company->slug,
                    $result['numbers_marked'] ?? 0,
                    $result['profiles_seen'] ?? 0
                ));
            } catch (Throwable $e) {
                $this->warn("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Profile-number sync complete: {$done} company(ies) synced, {$skipped} without Multilogin.");

        return self::SUCCESS;
    }
}
