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
    public function pickForLocation(?string $city, ?string $region, ?string $country): StaticProxy
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

        $haystack = fn (StaticProxy $p) => mb_strtolower(trim(($p->location ?? '').' '.($p->label ?? '')));

        foreach ([$city, $region] as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle === '') {
                continue;
            }
            $hit = $enabled->first(fn (StaticProxy $p) => str_contains($haystack($p), $needle));
            if ($hit) {
                return $hit;
            }
        }

        $country = mb_strtolower(trim((string) $country));
        if ($country !== '') {
            $hit = $enabled->first(fn (StaticProxy $p) => str_contains(mb_strtolower((string) $p->location), $country));
            if ($hit) {
                return $hit;
            }
        }

        return $enabled->random();
    }

    /**
     * Describe how well a proxy matches a client location:
     * city_region | city | region | country | random (no match).
     */
    public function matchLevel(StaticProxy $proxy, ?string $city, ?string $region, ?string $country): string
    {
        $hay = mb_strtolower(trim(($proxy->location ?? '').' '.($proxy->label ?? '')));
        $has = fn (?string $n) => ($n = mb_strtolower(trim((string) $n))) !== '' && str_contains($hay, $n);

        $c = $has($city);
        $r = $has($region);
        $cc = $has($country);

        return match (true) {
            $c && $r => 'city_region',
            $c => 'city',
            $r => 'region',
            $cc => 'country',
            default => 'random',
        };
    }
}
