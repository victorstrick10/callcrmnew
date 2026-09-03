<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MultiloginClient;
use Illuminate\Console\Command;
use Throwable;

class RefreshMultiloginTokensCommand extends Command
{
    protected $signature = 'multilogin:refresh-tokens';

    protected $description = 'Check each company\'s Multilogin token status (live/expired). Never overwrites the stored token — workspace automation tokens are long-lived and must be preserved.';

    public function handle(MultiloginClient $multilogin): int
    {
        $live = 0;
        $down = 0;
        $skipped = 0;

        foreach (Company::query()->get() as $company) {
            // NEVER modify the stored token here. It is a long-lived workspace
            // automation token; overwriting it via /user/refresh_token can replace
            // a valid token with an invalid one and make it look "removed".
            if (! $company->getMultiloginToken()) {
                $skipped++;

                continue;
            }

            try {
                $client = $multilogin->forCompany($company);
                if ($client->simulation) {
                    $skipped++;

                    continue;
                }

                [$ok, $message] = $client->pingToken();
                $company->setServiceStatus('multilogin', $ok, $message);
                $ok ? $live++ : $down++;
            } catch (Throwable $e) {
                $down++;
                $company->setServiceStatus('multilogin', false, $e->getMessage());
                $this->warn("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Multilogin token status: {$live} live, {$down} down, {$skipped} skipped (tokens preserved, never overwritten).");

        return self::SUCCESS;
    }
}
