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

        try {
            // Route through the proxy to ip-api.com (no query = the proxy's exit
            // IP). ip-api needs no key and is HTTP, which is more reliable through
            // proxies than an HTTPS CONNECT tunnel.
            $response = Http::withOptions(['proxy' => $proxyUrl])
                ->timeout(20)
                ->get('http://ip-api.com/json/', [
                    'fields' => 'status,message,countryCode,region,regionName,zip,city,isp,org,as,query',
                    'lang' => 'en',
                ]);

            $j = is_array($response->json()) ? $response->json() : [];

            if ($response->successful() && ($j['status'] ?? '') === 'success') {
                $isp = trim((string) ($j['isp'] ?? ''));
                if ($isp === '') {
                    $isp = trim((string) ($j['org'] ?? '')) ?: trim(preg_replace('/^AS\d+\s+/i', '', (string) ($j['as'] ?? '')) ?? '');
                }
                $proxy->forceFill([
                    'last_check_status' => 'up',
                    'exit_ip' => (string) ($j['query'] ?? ''),
                    'exit_country' => (string) ($j['countryCode'] ?? ''),
                    'exit_region' => (string) ($j['regionName'] ?? ''),
                    'exit_region_code' => (string) ($j['region'] ?? ''),
                    'exit_zip' => (string) ($j['zip'] ?? ''),
                    'exit_city' => (string) ($j['city'] ?? ''),
                    'exit_isp' => $isp,
                    'last_checked_at' => now(),
                ])->save();

                return ['ok' => true, 'ip' => (string) ($j['query'] ?? '')];
            }

            $proxy->forceFill(['last_check_status' => 'down', 'last_checked_at' => now()])->save();
            $err = ($j['message'] ?? '') !== '' ? (string) $j['message'] : 'HTTP '.$response->status();

            return ['ok' => false, 'error' => $err];
        } catch (Throwable $e) {
            $proxy->forceFill(['last_check_status' => 'down', 'last_checked_at' => now()])->save();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** An enabled proxy by id, or null. */
    public function findEnabled(int $id): ?StaticProxy
    {
        return StaticProxy::query()->enabled()->find($id);
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
     * Pick a proxy from a single provider only (e.g. MobileHop). Matches the
     * client's country/region/city; if none match, returns a random enabled
     * proxy from that provider. ISP is intentionally ignored here.
     */
    public function pickForProvider(string $provider, ?string $city, ?string $region, ?string $country): StaticProxy
    {
        $pool = StaticProxy::query()->enabled()->where('provider', $provider)->get();
        if ($pool->isEmpty()) {
            throw new RuntimeException(
                'No enabled '.$provider.' proxies configured. Add at least one MobileHop proxy in Static Proxies.'
            );
        }

        $rank = ['city_region' => 5, 'city' => 4, 'region' => 3, 'country' => 2];
        $best = null;
        $bestScore = 0;
        foreach ($pool as $p) {
            $level = $this->matchLevel($p, $city, $region, $country, '');
            if (isset($rank[$level]) && $rank[$level] > $bestScore) {
                $bestScore = $rank[$level];
                $best = $p;
            }
        }

        // No country/region/city match — use a random proxy from this provider.
        return $best ?: $pool->random();
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

        $loc = mb_strtolower(trim(($proxy->location ?? '').' '.($proxy->label ?? '')));
        $pCity = mb_strtolower(trim((string) $proxy->exit_city));
        $pRegion = mb_strtolower(trim((string) $proxy->exit_region));
        $pCountry = mb_strtolower(self::proxyCountryCode($proxy));
        $pText = mb_strtolower(trim(($proxy->exit_isp ?? '').' '.($proxy->location ?? '').' '.($proxy->label ?? '')));

        $sub = fn (string $a, string $b) => $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));

        // City/region: match verified exit geo OR the proxy's declared location/label.
        $c = $sub($pCity, $city) || $sub($loc, $city);
        $r = $sub($pRegion, $region) || $sub($loc, $region);
        // Country: exact 2-letter code equality (verified exit or declared location code).
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

    /**
     * The proxy's country code: the verified ipinfo exit country if present,
     * else derived from the leading 2-letter code of the declared location
     * (e.g. ProxyCheap "GB · COLT" -> GB).
     */
    public static function proxyCountryCode(StaticProxy $proxy): string
    {
        $exit = strtoupper(trim((string) $proxy->exit_country));
        if (strlen($exit) === 2) {
            return $exit;
        }
        if (preg_match('/^([A-Za-z]{2})\b/', trim((string) $proxy->location), $m)) {
            return strtoupper($m[1]);
        }

        return '';
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
