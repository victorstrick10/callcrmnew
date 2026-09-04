<?php

namespace Tests\Feature;

use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxyOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(array $attrs = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'country_code' => 'AE',
            'country' => 'AE',
            'region' => 'Dubai',
            'city' => 'Dubai',
            'timezone' => 'Asia/Dubai',
            'client_isp' => 'Zscaler Switzerland GmbH',
            'client_org' => 'Zscaler Switzerland GmbH',
        ], $attrs);
    }

    private function bootSettings(): void
    {
        $settings = app(IntegrationSettingsService::class);
        $settings->saveSettings('multilogin', [
            'automation_token' => 't',
            'base_url' => 'https://api.multilogin.com',
            'simulation_mode' => 'false',
        ]);
    }

    public function test_manual_overrides_reach_the_multilogin_proxy_payload(): void
    {
        $this->bootSettings();

        Http::fake([
            'profile-proxy.multilogin.com/*' => Http::response(['data' => 'gate.multilogin.com:8080:user:pass'], 200),
            'ip-api.com/*' => Http::response(['status' => 'success', 'query' => '1.2.3.4', 'city' => 'Geneva', 'regionName' => 'Geneva', 'countryCode' => 'CH', 'isp' => 'Sunrise', 'as' => 'AS1234 Sunrise'], 200),
        ]);

        $client = new MultiloginClient('t', 'https://api.multilogin.com');

        $proxy = $client->generate_multilogin_proxy($this->appointment(), [
            'connection' => 'mobile',
            'country' => 'CH',
            'region' => 'Geneva',
            'city' => 'Geneva',
            'isp' => 'Sunrise',
            'protocol' => 'socks5',
        ]);

        $this->assertSame('socks5', $proxy['protocol']);
        $this->assertSame('sunrise', $proxy['requested_isp']);

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'profile-proxy.multilogin.com')) {
                return false;
            }
            $d = $req->data();

            return ($d['country'] ?? '') === 'ch'
                && ($d['region'] ?? '') === 'geneva'
                && ($d['city'] ?? '') === 'geneva'
                && ($d['connectionType'] ?? '') === 'mobile'
                && ($d['protocol'] ?? '') === 'socks5'
                && ($d['isp'] ?? '') === 'sunrise';
        });
    }

    public function test_client_isp_is_never_shown_as_the_proxy_isp(): void
    {
        $this->bootSettings();

        // Proxy generates fine, but exit inspection fails (CONNECT unreachable),
        // and the winning attempt carried no ISP -> the displayed ISP must be blank,
        // never the client's "Zscaler" ISP.
        Http::fake([
            'profile-proxy.multilogin.com/*' => Http::response(['data' => 'gate.multilogin.com:8080:user:pass'], 200),
            'ip-api.com/*' => Http::response('', 500),
        ]);

        $client = new MultiloginClient('t', 'https://api.multilogin.com');

        // No override ISP; the client ISP (Zscaler) must NOT leak into the result.
        $candidates = $client->generate_proxy_candidates($this->appointment(), 1, [
            'connection' => 'mobile',
            'country' => 'AE',
            'region' => 'Dubai',
            'city' => 'Dubai',
            'isp' => '',
        ]);

        $this->assertNotEmpty($candidates);
        $this->assertStringNotContainsStringIgnoringCase('zscaler', (string) $candidates[0]['isp']);
        $this->assertSame('', $candidates[0]['isp']);
    }
}
