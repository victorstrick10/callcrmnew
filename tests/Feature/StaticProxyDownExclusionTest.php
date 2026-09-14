<?php

namespace Tests\Feature;

use App\Models\StaticProxy;
use App\Services\StaticProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticProxyDownExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_down_proxy_is_not_suggested_for_creation(): void
    {
        // A DOWN proxy that matches the location perfectly...
        $down = StaticProxy::create([
            'label' => 'down-ny', 'provider' => 'mobilehop', 'network_type' => 'mobile',
            'host' => 'down.com', 'port' => 1, 'protocol' => 'http', 'enabled' => true,
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York',
            'last_check_status' => 'down',
        ]);
        // ...and a LIVE proxy that also matches.
        $up = StaticProxy::create([
            'label' => 'up-ny', 'provider' => 'mobilehop', 'network_type' => 'mobile',
            'host' => 'up.com', 'port' => 2, 'protocol' => 'http', 'enabled' => true,
            'exit_country' => 'US', 'exit_region' => 'New York', 'exit_city' => 'New York',
            'last_check_status' => 'up',
        ]);

        $picked = app(StaticProxyService::class)->pickForLocation('New York', 'New York', 'US');

        $this->assertSame($up->id, $picked->id, 'A down proxy must never be suggested');
    }

    public function test_pick_throws_when_only_down_proxies_exist(): void
    {
        StaticProxy::create([
            'label' => 'down-only', 'provider' => 'mobilehop', 'network_type' => 'mobile',
            'host' => 'd.com', 'port' => 1, 'protocol' => 'http', 'enabled' => true,
            'last_check_status' => 'down',
        ]);

        $this->expectExceptionMessage('No live static proxies available');
        app(StaticProxyService::class)->pickForLocation('X', 'Y', 'US');
    }

    public function test_index_shows_live_and_down_counts_and_is_sortable(): void
    {
        StaticProxy::create(['label' => 'a', 'provider' => 'mobilehop', 'network_type' => 'mobile', 'host' => 'a.com', 'port' => 1, 'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'up']);
        StaticProxy::create(['label' => 'b', 'provider' => 'proxycheap', 'network_type' => 'mobile', 'host' => 'b.com', 'port' => 2, 'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down']);
        StaticProxy::create(['label' => 'c', 'provider' => 'mobilehop', 'network_type' => 'mobile', 'host' => 'c.com', 'port' => 3, 'protocol' => 'http', 'enabled' => true, 'last_check_status' => '']);

        $res = $this->get(route('static-proxies.index', ['type' => 'all']))->assertOk();
        $res->assertSee('Live');
        $res->assertSee('Down');
        $res->assertSee('px-dot up', false);
        $res->assertSee('px-dot down', false);
        $res->assertSee('sort-link', false);

        // Sorting by label desc should not error and should return 200.
        $this->get(route('static-proxies.index', ['type' => 'all', 'sort' => 'label', 'dir' => 'desc']))->assertOk();
        $this->get(route('static-proxies.index', ['type' => 'all', 'sort' => 'status', 'dir' => 'asc']))->assertOk();
    }
}
