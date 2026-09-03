<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MultiloginClient;
use Illuminate\Console\Command;
use Throwable;

class RefreshMultiloginTokensCommand extends Command
{
    protected $signature = 'multilogin:refresh-tokens';

    protected $description = 'Every 10 min: keep each company\'s Multilogin token valid — sign in with saved credentials when the token is missing/expiring/failing — and update the live/expired status light.';

    /** Mint a fresh token this many seconds before the current one expires. */
    private const REFRESH_BEFORE = 12 * 3600;

    public function handle(MultiloginClient $multilogin): int
    {
        $signed = 0;
        $refreshed = 0;
        $live = 0;
        $down = 0;
        $skipped = 0;

        foreach (Company::query()->get() as $company) {
            $current = (string) $company->getMultiloginToken();
            $hasCreds = $company->hasMultiloginCredentials();
            $baseUrl = (string) ($company->multilogin_base_url ?: '');

            try {
                $client = $multilogin->forCompany($company);
                if ($client->simulation) {
                    $skipped++;

                    continue;
                }

                if ($current === '' && ! $hasCreds) {
                    $skipped++;

                    continue;
                }

                // Is a fresh token needed? (missing, expiring soon, or not authenticating)
                $needFresh = $current === '';
                $message = 'No token.';
                if ($current !== '') {
                    $secondsLeft = $client->tokenSecondsLeft();
                    [$ok, $message] = $client->pingToken();
                    if (! $ok || ($secondsLeft !== null && $secondsLeft < self::REFRESH_BEFORE)) {
                        $needFresh = true;
                    }
                }

                if (! $needFresh) {
                    $company->setServiceStatus('multilogin', true, $message);
                    $live++;

                    continue;
                }

                // Prefer signing in with credentials (works even after expiry);
                // fall back to /user/refresh_token when the token is still valid.
                $new = null;
                if ($hasCreds) {
                    $new = (new MultiloginClient('', $baseUrl))
                        ->signin((string) $company->getMultiloginEmail(), (string) $company->getMultiloginPassword());
                }
                $viaSignin = $this->isUsableJwt($new);
                if (! $viaSignin && $current !== '') {
                    $new = $client->refresh_token();
                }

                if ($this->isUsableJwt($new)) {
                    [$newOk] = (new MultiloginClient($new, $baseUrl))->pingToken();
                    if ($newOk) {
                        $company->setMultiloginToken($new);
                        $company->save();
                        $company->setServiceStatus('multilogin', true, $viaSignin ? 'Token refreshed via sign-in.' : 'Token refreshed.');
                        $viaSignin ? $signed++ : $refreshed++;
                        $live++;
                        $this->info("Refreshed Multilogin token for {$company->slug}.");

                        continue;
                    }
                }

                $down++;
                $company->setServiceStatus(
                    'multilogin',
                    false,
                    $hasCreds
                        ? 'Auto sign-in failed — check Multilogin email/password on the company.'
                        : 'Token expired — add Multilogin email/password on the company to auto-refresh.'
                );
                $this->warn("{$company->slug}: could not obtain a valid token.");
            } catch (Throwable $e) {
                $down++;
                $company->setServiceStatus('multilogin', false, $e->getMessage());
                $this->warn("{$company->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Multilogin tokens: {$signed} signed-in, {$refreshed} refreshed, {$live} live, {$down} down, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** A usable Multilogin token is a JWT: three dot-separated parts, non-trivial length. */
    private function isUsableJwt(?string $token): bool
    {
        return is_string($token) && strlen($token) > 40 && substr_count($token, '.') === 2;
    }
}
