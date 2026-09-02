<?php

namespace App\Services;

use App\Models\StaticProxy;
use RuntimeException;

class StaticProxyService
{
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
