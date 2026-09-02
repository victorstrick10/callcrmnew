<?php

namespace Tests\Feature;

use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CreateStaticProfileTest extends TestCase
{
    use RefreshDatabase;

    private function configureMultilogin(): void
    {
        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'false',
            'automation_token' => 'test-automation-token',
            'base_url' => 'https://api.multilogin.com',
            'workspace_id' => 'ws-1',
            'static_folder_id' => 'static-folder-1',
            'profile_create_endpoint' => '/profile/create',
            'browser_type' => 'mimic',
            'os_type' => 'windows',
        ]);
    }

    public function test_create_static_profile_posts_to_create_not_clone(): void
    {
        $this->configureMultilogin();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/profile/clone')) {
                return Http::response('clone should not be called', 500);
            }

            if (str_contains($request->url(), '/profile/create')) {
                return Http::response(['data' => ['id' => 'static-1']]);
            }

            if (str_contains($request->url(), '/bookmarks/import')) {
                return Http::response(['status' => ['http_code' => 200, 'message' => 'ok']]);
            }

            return Http::response('unexpected url', 404);
        });

        $proxy = [
            'protocol' => 'http',
            'host' => 'proxy.example.com',
            'port' => 8080,
            'username' => 'pool-user',
            'password' => 'pool-pass',
        ];

        $client = app(MultiloginClient::class);
        $profileId = $client->create_static_profile('001 - Client - STATIC', $proxy);

        $this->assertSame('static-1', $profileId);

        Http::assertSent(function ($request) use ($proxy) {
            if (! str_contains($request->url(), '/profile/create')) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return ($body['name'] ?? null) === '001 - Client - STATIC'
                && ($body['folder_id'] ?? null) === 'static-folder-1'
                && ($body['parameters']['proxy']['type'] ?? null) === 'http'
                && ($body['parameters']['proxy']['host'] ?? null) === $proxy['host']
                && ($body['parameters']['proxy']['port'] ?? null) === $proxy['port']
                && ($body['parameters']['flags']['proxy_masking'] ?? null) === 'custom'
                && ($body['parameters']['flags']['startup_behavior'] ?? null) === 'custom'
                && ($body['parameters']['custom_start_urls'] ?? null) === ['https://ipinfo.io/json']
                && ($body['parameters']['fingerprint'] ?? null) === [];
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '/profile/clone');
        });
    }

    public function test_validate_profile_proxy_payload_accepts_well_formed_static_payload(): void
    {
        $payload = [
            'name' => 'test',
            'parameters' => [
                'flags' => ['proxy_masking' => 'custom'],
                'proxy' => [
                    'type' => 'http',
                    'host' => 'proxy.example.com',
                    'port' => 8080,
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
        ];

        MultiloginClient::_validate_profile_proxy_payload($payload);

        $this->assertSame(8080, $payload['parameters']['proxy']['port']);
    }

    public function test_validate_profile_proxy_payload_rejects_missing_proxy_fields(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Profile proxy payload is incomplete');

        $payload = [
            'parameters' => [
                'flags' => ['proxy_masking' => 'custom'],
                'proxy' => [
                    'type' => 'http',
                    'host' => 'proxy.example.com',
                    'port' => 8080,
                ],
            ],
        ];

        MultiloginClient::_validate_profile_proxy_payload($payload);
    }

    public function test_create_static_profile_simulation_mode_returns_sim_prefix(): void
    {
        app(IntegrationSettingsService::class)->saveSettings('multilogin', [
            'simulation_mode' => 'true',
        ]);

        $client = app(MultiloginClient::class);
        $profileId = $client->create_static_profile('test', [
            'protocol' => 'http',
            'host' => 'unused',
            'port' => 1,
            'username' => 'u',
            'password' => 'p',
        ]);

        $this->assertStringStartsWith('sim-static-', $profileId);
        Http::assertNothingSent();
    }
}
