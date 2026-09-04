<?php

namespace Tests\Feature;

use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreateGeoProfileTest extends TestCase
{
    use RefreshDatabase;

    private function configureMultilogin(): void
    {
        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'false',
            'automation_token' => 'test-automation-token',
            'base_url' => 'https://api.multilogin.com',
            'workspace_id' => 'ws-1',
            'geo_folder_id' => 'geo-folder-1',
            'profile_create_endpoint' => '/profile/create',
            'browser_type' => 'mimic',
            'os_type' => 'windows',
        ]);
    }

    public function test_create_geo_profile_uses_custom_start_url(): void
    {
        $this->configureMultilogin();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/profile/create')) {
                return Http::response(['data' => ['id' => 'geo-1']]);
            }

            if (str_contains($request->url(), '/bookmarks/import')) {
                return Http::response(['status' => ['http_code' => 200, 'message' => 'ok']]);
            }

            return Http::response('unexpected url', 404);
        });

        $appointment = (object) [
            'city' => 'Houston',
            'region' => 'Texas',
            'country' => 'US',
            'country_code' => 'US',
            'timezone' => 'America/Chicago',
            'proxy_status' => 'ready',
            'proxy_host' => 'gate.multilogin.com',
            'proxy_port' => 8080,
            'proxy_username' => 'geo-user',
            'proxy_password' => 'geo-pass',
            'proxy_protocol' => 'http',
            'proxy_country' => 'US',
            'proxy_region' => 'Texas',
            'proxy_city' => 'Houston',
        ];

        $client = app(MultiloginClient::class);
        $profileId = $client->create_geo_profile('001 - Client - GEO', $appointment);

        $this->assertSame('geo-1', $profileId);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/profile/create')) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return ($body['name'] ?? null) === '001 - Client - GEO'
                && ($body['folder_id'] ?? null) === 'geo-folder-1'
                && ($body['parameters']['flags']['startup_behavior'] ?? null) === 'custom'
                && ($body['parameters']['custom_start_urls'] ?? null) === ['http://ip-api.com/json/']
                && ($body['parameters']['proxy']['host'] ?? null) === 'gate.multilogin.com';
        });
    }
}
