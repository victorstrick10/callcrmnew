<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MultiloginClient;
use Illuminate\Console\Command;
use Throwable;

class RefreshMultiloginTokensCommand extends Command
{
    protected $signature = 'multilogin:refresh-tokens';

    protected $description = 'Every 10 min: keep each company\'s Multilogin token fresh (safe refresh) and update the live/expired status light.';

    public function handle(MultiloginClient $multilogin): int
    {
        $refreshed = 0;
        $live = 0;
        $down = 0;
        $skipped = 0;

        foreach (Company::query()->get() as $company) {
            $current = (string) $company->getMultiloginToken();
            if ($current === '') {
                $skipped++;

                continue;
            }

            try {
                $client = $multilogin->forCompany($company);
                if ($client->simulation) {
                    $skipped++;

                    continue;
                }

                // Attempt a refresh, but ONLY persist the new token when it is a
                // valid JWT AND it actually authenticates. This guarantees a good
                // token is never overwritten with an invalid one.
                $new = $client->refresh_token();
                if ($this->isUsableJwt($new) && $new !== $current) {
                    $probe = new MultiloginClient($new, (string) ($company->multilogin_base_url ?: ''));
                    [$newOk] = $probe->pingToken();
                    if ($newOk) {
                        $company->setMultiloginToken($new);
                        $company->save();
                        $company->setServiceStatus('multilogin', true, 'Token refreshed & live.');
                        $refreshed++;
                        $live++;
                        $this->info("Refreshed Multilogin token for {$company->slug}.");

                        continue;
                    }
                }

                // No safe refresh available — just report the current token's status.
                [$ok, $message] = $multilogin->forCompany($company->fresh())->pingToken();
                $company->setServiceStatus('multilogin', $ok, $message);
                $ok ? $live++ : $down++;
            } catch (Throwable $e) {
                $down++;
                $company->setServiceStatus('multilogin', false, $e->getMessage());
                $this->warn("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Multilogin tokens: {$refreshed} refreshed, {$live} live, {$down} down, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** A usable Multilogin token is a JWT: three dot-separated parts, non-trivial length. */
    private function isUsableJwt(?string $token): bool
    {
        return is_string($token) && strlen($token) > 40 && substr_count($token, '.') === 2;
    }
}
