<?php

namespace App\Services;

use App\Models\StaticProxy;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class StaticProxyService
{
    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    /**
     * Probe a proxy through ipinfo.io and store its live status + verified
     * exit geolocation (country/region/city/ISP).
     *
     * @return array{ok:bool,ip?:string,error?:string}
     */
    public function check(StaticProxy $proxy): array
    {
        $scheme = strtolower((string) $proxy->protocol) === 'socks5' ? 'socks5h' : 'http';
        $auth = '';
        if (trim((string) $proxy->username) !== '') {
            $auth = rawurlencode($proxy->username).':'.rawurlencode((string) $proxy->password).'@';
        }
        $proxyUrl = "{$scheme}://{$auth}{$proxy->host}:{$proxy->port}";
        $token = $this->settings->getSettings('ipinfo')['api_token'] ?? '';

        try {
            $response = Http::withOptions(['proxy' => $proxyUrl])
                ->timeout(20)
                ->get('https://ipinfo.io/json', array_filter(['token' => $token]));

            if ($response->successful()) {
                $j = is_array($response->json()) ? $response->json() : [];
                $org = (string) ($j['org'] ?? '');
                $isp = trim(preg_replace('/^AS\d+\s+/i', '', $org) ?? $org);
                $proxy->forceFill([
                    'last_check_status' => 'up',
                    'exit_ip' => (string) ($j['ip'] ?? ''),
                    'exit_country' => (string) ($j['country'] ?? ''),
                    'exit_region' => (string) ($j['region'] ?? ''),
                    'exit_city' => (string) ($j['city'] ?? ''),
                    'exit_isp' => $isp,
                    'last_checked_at' => now(),
                ])->save();

                return ['ok' => true, 'ip' => (string) ($j['ip'] ?? '')];
            }

            $proxy->forceFill(['last_check_status' => 'down', 'last_checked_at' => now()])->save();

            return ['ok' => false, 'error' => 'HTTP '.$response->status()];
        } catch (Throwable $e) {
            $proxy->forceFill(['last_check_status' => 'down', 'last_checked_at' => now()])->save();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function randomEnabled(): StaticProxy
    {
        $proxy = StaticProxy::query()->enabled()->inRandomOrder()->first();
        if (! $proxy) {
            throw new RuntimeException('No enabled static proxies configured. Add at least one in Static Proxies.');
        }

        return $proxy;
    }

    /**
     * Pick the best enabled static proxy for a client's location:
     *   1) a proxy whose location/label matches the client's city or region
     *      (e.g. a New York mobile proxy for a New York client),
     *   2) otherwise a proxy matching the country,
     *   3) otherwise a random enabled proxy.
     */
    public function pickForLocation(?string $city, ?string $region, ?string $country, ?string $isp = ''): StaticProxy
    {
        $enabled = StaticProxy::query()->enabled()->get();
        if ($enabled->isEmpty()) {
            throw new RuntimeException('No enabled static proxies configured. Add at least one in Static Proxies.');
        }

        // Mobile proxies are used for STATIC creation; fall back to any enabled
        // only if no mobile proxy is available.
        $mobile = $enabled->where('network_type', 'mobile');
        if ($mobile->isNotEmpty()) {
            $enabled = $mobile->values();
        }

        $rank = ['city_region' => 5, 'city' => 4, 'region' => 3, 'isp' => 2.5, 'country' => 2, 'random' => 0];
        $best = null;
        $bestScore = -1;
        foreach ($enabled as $p) {
            $level = $this->matchLevel($p, $city, $region, $country, $isp);
            $score = $rank[$level] + ($p->network_type === 'mobile' ? 0.5 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $p;
            }
        }

        return $best ?: $enabled->random();
    }

    /**
     * Describe how well a proxy matches a client, using the proxy's ipinfo-verified
     * exit geo (exact 2-letter country code, real region/city, fuzzy ISP):
     * city_region | city | region | isp | country | random (no match).
     */
    public function matchLevel(StaticProxy $proxy, ?string $city, ?string $region, ?string $country, ?string $isp = ''): string
    {
        $city = mb_strtolower(trim((string) $city));
        $region = mb_strtolower(trim((string) $region));
        // Client country may arrive as a 2-letter code or a full name; use the code.
        $countryCode = mb_strtolower(trim((string) $country));

        $pCity = mb_strtolower(trim((string) $proxy->exit_city));
        $pRegion = mb_strtolower(trim((string) $proxy->exit_region));
        $pCountry = mb_strtolower(trim((string) $proxy->exit_country));
        $pText = mb_strtolower(trim(($proxy->exit_isp ?? '').' '.($proxy->location ?? '').' '.($proxy->label ?? '')));

        $sub = fn (string $a, string $b) => $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));

        $c = $sub($pCity, $city);
        $r = $sub($pRegion, $region);
        // Country matches only on exact 2-letter code equality (no loose substring).
        $cc = strlen($countryCode) === 2 && $countryCode === $pCountry;
        $ispMatch = self::ispMatches($pText, $isp);

        return match (true) {
            $c && $r => 'city_region',
            $c => 'city',
            $r => 'region',
            $ispMatch => 'isp',
            $cc => 'country',
            default => 'random',
        };
    }

    /** Fuzzy ISP/provider match: any significant word of the client ISP present in the proxy text. */
    public static function ispMatches(string $haystack, ?string $isp): bool
    {
        $isp = mb_strtolower(trim((string) $isp));
        if ($isp === '') {
            return false;
        }
        foreach (preg_split('/[^a-z0-9]+/', $isp) ?: [] as $word) {
            if (strlen($word) >= 4 && ! in_array($word, ['inc', 'llc', 'corp', 'communications', 'network', 'networks', 'telecom'], true) && str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
