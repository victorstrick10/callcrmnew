<?php

namespace Tests\Feature;

use App\Models\StaticProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StaticProxyAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_credentials_downloads_csv_for_both_providers(): void
    {
        StaticProxy::create([
            'provider' => 'mobilehop', 'network_type' => 'mobile', 'label' => 'mh-1',
            'host' => 'mh.example.com', 'port' => 1000, 'username' => 'mhuser', 'password' => 'mhpass',
            'protocol' => 'http', 'enabled' => true,
        ]);
        StaticProxy::create([
            'provider' => 'proxycheap', 'network_type' => 'mobile', 'label' => 'pc-1',
            'host' => 'pc.example.com', 'port' => 2000, 'username' => 'pcuser', 'password' => 'pcpass',
            'protocol' => 'socks5', 'enabled' => true,
        ]);

        $res = $this->get(route('static-proxies.export-credentials'));
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $res->streamedContent();
        $this->assertStringContainsString('mh.example.com', $csv);
        $this->assertStringContainsString('mhuser', $csv);
        $this->assertStringContainsString('mhpass', $csv);
        $this->assertStringContainsString('pc.example.com', $csv);
        $this->assertStringContainsString('pcuser', $csv);
        $this->assertStringContainsString('pcpass', $csv);
    }

    public function test_soft_reset_mobilehop_clears_and_reverifies_only_mobilehop(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'query' => '9.9.9.9', 'countryCode' => 'US', 'isp' => 'Carrier'], 200)]);

        $mh = StaticProxy::create([
            'provider' => 'mobilehop', 'network_type' => 'mobile', 'label' => 'mh', 'host' => 'mh.com', 'port' => 1,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down', 'exit_ip' => '1.1.1.1', 'exit_city' => 'Old',
        ]);
        $pc = StaticProxy::create([
            'provider' => 'proxycheap', 'network_type' => 'mobile', 'label' => 'pc', 'host' => 'pc.com', 'port' => 2,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down', 'exit_ip' => '2.2.2.2',
        ]);

        $this->post(route('static-proxies.soft-reset-mobilehop'))->assertRedirect();

        // MobileHop re-verified to the faked exit IP; ProxyCheap left untouched.
        $this->assertSame('up', $mh->fresh()->last_check_status);
        $this->assertSame('9.9.9.9', $mh->fresh()->exit_ip);
        $this->assertSame('down', $pc->fresh()->last_check_status);
        $this->assertSame('2.2.2.2', $pc->fresh()->exit_ip);
    }

    public function test_hard_reset_all_reverifies_every_provider(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'query' => '5.5.5.5', 'countryCode' => 'GB', 'isp' => 'Carrier'], 200)]);

        $mh = StaticProxy::create([
            'provider' => 'mobilehop', 'network_type' => 'mobile', 'label' => 'mh', 'host' => 'mh.com', 'port' => 1,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down',
        ]);
        $pc = StaticProxy::create([
            'provider' => 'proxycheap', 'network_type' => 'mobile', 'label' => 'pc', 'host' => 'pc.com', 'port' => 2,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down',
        ]);

        $this->post(route('static-proxies.hard-reset-all'))->assertRedirect();

        $this->assertSame('up', $mh->fresh()->last_check_status);
        $this->assertSame('up', $pc->fresh()->last_check_status);
        $this->assertSame('5.5.5.5', $pc->fresh()->exit_ip);
    }

    public function test_change_ip_proxycheap_only_touches_proxycheap(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'query' => '7.7.7.7', 'countryCode' => 'FR', 'isp' => 'Carrier'], 200)]);

        $pc = StaticProxy::create([
            'provider' => 'proxycheap', 'network_type' => 'mobile', 'label' => 'pc', 'host' => 'pc.com', 'port' => 2,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down', 'exit_ip' => '2.2.2.2',
        ]);
        $mh = StaticProxy::create([
            'provider' => 'mobilehop', 'network_type' => 'mobile', 'label' => 'mh', 'host' => 'mh.com', 'port' => 1,
            'protocol' => 'http', 'enabled' => true, 'last_check_status' => 'down', 'exit_ip' => '1.1.1.1',
        ]);

        $this->post(route('static-proxies.change-ip-proxycheap'))->assertRedirect();

        $this->assertSame('7.7.7.7', $pc->fresh()->exit_ip);
        $this->assertSame('1.1.1.1', $mh->fresh()->exit_ip);
        $this->assertSame('down', $mh->fresh()->last_check_status);
    }
}
