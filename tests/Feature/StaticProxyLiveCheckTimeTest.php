<?php

namespace Tests\Feature;

use App\Models\StaticProxy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaticProxyLiveCheckTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_ip_column_shows_last_check_timeframe(): void
    {
        config(['app.timezone' => 'UTC', 'app.display_timezone' => 'Europe/Belgrade']);

        $checkedAt = Carbon::parse('2026-09-04 12:30:00', 'UTC');

        StaticProxy::create([
            'provider' => 'mobilehop',
            'network_type' => 'mobile',
            'label' => 'mh-live',
            'host' => 'mh.example.com',
            'port' => 8000,
            'protocol' => 'http',
            'enabled' => true,
            'last_check_status' => 'up',
            'exit_ip' => '203.0.113.10',
            'exit_country' => 'US',
            'exit_region' => 'New York',
            'exit_city' => 'New York',
            'exit_isp' => 'Example Carrier',
            'last_checked_at' => $checkedAt,
        ]);

        $html = $this->get(route('static-proxies.index'))->assertOk()->getContent();

        $this->assertStringContainsString('203.0.113.10', $html);
        $this->assertStringContainsString('Last check', $html);
        // Display timezone is Europe/Belgrade (GMT+1 / CEST) — 12:30 UTC → 14:30 local.
        $this->assertStringContainsString('04.09.2026 14:30', $html);
        $this->assertStringContainsString('GMT+1', $html);
    }

    public function test_unchecked_proxy_shows_not_checked_yet(): void
    {
        StaticProxy::create([
            'provider' => 'proxycheap',
            'network_type' => 'mobile',
            'label' => 'pc-new',
            'host' => 'pc.example.com',
            'port' => 9000,
            'protocol' => 'http',
            'enabled' => true,
            'last_check_status' => '',
            'last_checked_at' => null,
        ]);

        $html = $this->get(route('static-proxies.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Not checked yet', $html);
    }
}
