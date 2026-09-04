<?php

namespace Tests\Feature;

use App\Services\IpInfoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IpApiGeolocationTest extends TestCase
{
    public function test_lookup_maps_ip_api_fields_to_the_app_geo_shape(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'country' => 'United States',
                'countryCode' => 'US',
                'region' => 'CO',
                'regionName' => 'Colorado',
                'city' => 'Denver',
                'zip' => '80202',
                'lat' => 39.7392,
                'lon' => -104.9903,
                'timezone' => 'America/Denver',
                'isp' => 'Comcast Cable Communications, LLC',
                'org' => 'Comcast',
                'as' => 'AS7922 Comcast Cable Communications, LLC',
                'asname' => 'COMCAST-7922',
                'mobile' => false,
                'proxy' => false,
                'hosting' => false,
                'query' => '73.1.2.3',
            ], 200),
        ]);

        $result = app(IpInfoService::class)->lookup('73.1.2.3');

        // The Region shown to users must be the full name (regionName), not "CO".
        $this->assertSame('Colorado', $result['region']);
        $this->assertSame('CO', $result['region_code']);
        $this->assertSame('United States', $result['country']);
        $this->assertSame('US', $result['country_code']);
        $this->assertSame('Denver', $result['city']);
        $this->assertSame('Comcast Cable Communications, LLC', $result['isp']);
        $this->assertSame('AS7922', $result['asn']);
        $this->assertSame('73.1.2.3', $result['ip']);
        $this->assertSame('America/Denver', $result['timezone']);
    }

    public function test_lookup_throws_on_failed_status(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'fail', 'message' => 'invalid query', 'query' => 'bogus'], 200),
        ]);

        $this->expectExceptionMessage('ip-api.com lookup failed');
        app(IpInfoService::class)->lookup('bogus');
    }

    public function test_requests_use_the_ip_api_endpoint(): void
    {
        Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'query' => '1.1.1.1', 'countryCode' => 'AU', 'regionName' => 'Queensland', 'city' => 'Brisbane', 'isp' => 'Cloudflare'], 200)]);

        app(IpInfoService::class)->lookup('1.1.1.1');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'ip-api.com/json/'));
    }
}
