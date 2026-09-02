<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MultiloginClient;
use Illuminate\Console\Command;
use Throwable;

class RefreshMultiloginTokensCommand extends Command
{
    protected $signature = 'multilogin:refresh-tokens';

    protected $description = 'Refresh each company\'s Multilogin token to prevent expiry';

    public function handle(MultiloginClient $multilogin): int
    {
        $refreshed = 0;
        $skipped = 0;

        foreach (Company::query()->get() as $company) {
            $current = $company->getMultiloginToken();
            if (! $current) {
                $skipped++;

                continue;
            }

            try {
                $client = $multilogin->forCompany($company);
                if ($client->simulation) {
                    $skipped++;

                    continue;
                }

                $new = $client->refresh_token();
                if ($new && $new !== $current) {
                    $company->setMultiloginToken($new);
                    $company->save();
                    $refreshed++;
                    $this->info("Refreshed Multilogin token for {$company->slug}.");
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $this->warn("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Multilogin token refresh: {$refreshed} refreshed, {$skipped} unchanged/skipped.");

        return self::SUCCESS;
    }
}
