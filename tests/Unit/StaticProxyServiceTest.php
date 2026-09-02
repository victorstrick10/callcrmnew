<?php

namespace Tests\Unit;

use App\Models\StaticProxy;
use App\Services\StaticProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StaticProxyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_only_enabled(): void
    {
        StaticProxy::create(['label' => 'off', 'host' => 'a.com', 'port' => 1, 'enabled' => false, 'protocol' => 'http']);
        $on = StaticProxy::create(['label' => 'on', 'host' => 'b.com', 'port' => 2, 'enabled' => true, 'protocol' => 'http']);

        $picked = app(StaticProxyService::class)->randomEnabled();
        $this->assertSame($on->id, $picked->id);
    }

    public function test_throws_when_none_enabled(): void
    {
        $this->expectException(RuntimeException::class);
        app(StaticProxyService::class)->randomEnabled();
    }

    public function test_pick_for_provider_matches_location_within_provider(): void
    {
        // MobileHop proxy in New York (verified exit geo).
        $ny = StaticProxy::create([
            'label' => 'mhop-ny', 'host' => 'ny.mhop.com', 'port' => 1, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'mobilehop', 'network_type' => 'mobile',
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York', 'last_check_status' => 'up',
        ]);
        // Another MobileHop proxy elsewhere.
        StaticProxy::create([
            'label' => 'mhop-tx', 'host' => 'tx.mhop.com', 'port' => 2, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'mobilehop', 'network_type' => 'mobile',
            'exit_country' => 'US', 'exit_region' => 'Texas', 'exit_city' => 'Houston', 'last_check_status' => 'up',
        ]);
        // A ProxyCheap proxy that also matches NY — must NOT be chosen by the MobileHop picker.
        StaticProxy::create([
            'label' => 'pc-ny', 'host' => 'ny.pc.com', 'port' => 3, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'proxycheap', 'network_type' => 'mobile',
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York', 'last_check_status' => 'up',
        ]);

        $picked = app(StaticProxyService::class)->pickForProvider('mobilehop', 'New York', 'New York', 'US');

        $this->assertSame($ny->id, $picked->id);
        $this->assertSame('mobilehop', $picked->provider);
    }

    public function test_pick_for_provider_falls_back_to_random_within_provider_when_no_match(): void
    {
        // Only MobileHop proxies, none matching the client's location.
        StaticProxy::create([
            'label' => 'mhop-de', 'host' => 'de.mhop.com', 'port' => 1, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'mobilehop', 'network_type' => 'mobile',
            'exit_country' => 'DE', 'exit_region' => 'Berlin', 'exit_city' => 'Berlin', 'last_check_status' => 'up',
        ]);
        // A ProxyCheap proxy matching the client's location — must never be returned.
        StaticProxy::create([
            'label' => 'pc-jp', 'host' => 'jp.pc.com', 'port' => 2, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'proxycheap', 'network_type' => 'mobile',
            'exit_country' => 'JP', 'exit_region' => 'Tokyo', 'exit_city' => 'Tokyo', 'last_check_status' => 'up',
        ]);

        $picked = app(StaticProxyService::class)->pickForProvider('mobilehop', 'Tokyo', 'Tokyo', 'JP');

        $this->assertSame('mobilehop', $picked->provider);
    }

    public function test_pick_for_provider_throws_when_provider_pool_empty(): void
    {
        StaticProxy::create([
            'label' => 'pc', 'host' => 'pc.com', 'port' => 1, 'enabled' => true, 'protocol' => 'http',
            'provider' => 'proxycheap', 'network_type' => 'mobile',
        ]);

        $this->expectException(RuntimeException::class);
        app(StaticProxyService::class)->pickForProvider('mobilehop', 'X', 'Y', 'US');
    }
}
