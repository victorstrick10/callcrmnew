<?php

namespace Tests\Unit;

use App\Services\MultiloginClient;
use PHPUnit\Framework\TestCase;

/**
 * Port of the assertions in
 * calendly_multilogin_crm_rebuild_v2_5/self_test.py.
 *
 * These exercise pure/static helpers only (no database, no HTTP), matching
 * the scope of the original self_test.py script.
 */
class MultiloginClientTest extends TestCase
{
    /**
     * @return array<string, array{0: array}>
     */
    public static function connectionUrlSamplesProvider(): array
    {
        return [
            'bare colon-delimited string' => [
                ['data' => 'gate.multilogin.com:8080:user:pass'],
            ],
            'colon-delimited string inside a list' => [
                ['data' => ['gate.multilogin.com:8080:user:pass']],
            ],
            'structured proxy object nested under result.proxy' => [
                ['result' => ['proxy' => [
                    'host' => 'gate.multilogin.com',
                    'port' => 8080,
                    'username' => 'user',
                    'password' => 'pass',
                ]]],
            ],
            'connection_url nested under payload' => [
                ['payload' => ['connection_url' => 'http://user:pass@gate.multilogin.com:8080']],
            ],
        ];
    }

    /**
     * @dataProvider connectionUrlSamplesProvider
     */
    public function test_parse_multilogin_connection_url_handles_known_response_shapes(array $sample): void
    {
        $parsed = MultiloginClient::_parse_multilogin_connection_url($sample);

        $this->assertSame('gate.multilogin.com', $parsed['host']);
        $this->assertSame(8080, $parsed['port']);
    }

    public function test_snake_case_location_normalizes_accents_and_case(): void
    {
        $this->assertSame('sao_paulo', MultiloginClient::_snake_case_location('São Paulo'));
        $this->assertSame('sao_paulo', MultiloginClient::_snake_case_location('SÀo Paulo'));
        $this->assertSame('sao_paulo', MultiloginClient::_snake_case_location('Sao Paulo'));
    }

    public function test_proxy_location_attempts_with_full_location_returns_three_attempts(): void
    {
        $attempts = MultiloginClient::proxy_location_attempts([
            'country' => 'BR',
            'region' => 'São Paulo',
            'city' => 'São Paulo',
        ]);

        $this->assertCount(3, $attempts);
        $this->assertSame([
            'country' => 'br',
            'region' => 'sao_paulo',
            'city' => 'sao_paulo',
        ], $attempts[0]);
        $this->assertSame([
            'country' => 'br',
            'region' => 'sao_paulo',
            'city' => '',
        ], $attempts[1]);
        $this->assertSame([
            'country' => 'br',
            'region' => '',
            'city' => '',
        ], $attempts[2]);
    }

    public function test_proxy_location_attempts_with_only_country_returns_one_attempt(): void
    {
        $attempts = MultiloginClient::proxy_location_attempts([
            'country' => 'US',
            'region' => '',
            'city' => '',
        ]);

        $this->assertCount(1, $attempts);
        $this->assertSame([
            'country' => 'us',
            'region' => '',
            'city' => '',
        ], $attempts[0]);
    }

    public function test_proxy_location_attempts_with_region_and_country_returns_two_attempts(): void
    {
        $attempts = MultiloginClient::proxy_location_attempts([
            'country' => 'DE',
            'region' => 'Berlin',
            'city' => '',
        ]);

        $this->assertCount(2, $attempts);
        $this->assertSame([
            'country' => 'de',
            'region' => 'berlin',
            'city' => '',
        ], $attempts[0]);
        $this->assertSame([
            'country' => 'de',
            'region' => '',
            'city' => '',
        ], $attempts[1]);
    }

    public function test_geo_proxy_attempts_are_mobile_with_isp_then_graceful_fallback(): void
    {
        $attempts = MultiloginClient::geo_proxy_attempts(
            ['country' => 'US', 'region' => 'New York', 'city' => 'New York'],
            'Verizon',
            'mobile'
        );

        // 1) mobile + full location + ISP
        $this->assertSame(
            ['country' => 'us', 'region' => 'new_york', 'city' => 'new_york', 'isp' => 'Verizon', 'connection' => 'mobile'],
            $attempts[0]
        );
        // 2) mobile + full location (ISP dropped)
        $this->assertSame(
            ['country' => 'us', 'region' => 'new_york', 'city' => 'new_york', 'isp' => '', 'connection' => 'mobile'],
            $attempts[1]
        );
        // 3) mobile + country + region (city dropped)
        $this->assertSame(
            ['country' => 'us', 'region' => 'new_york', 'city' => '', 'isp' => '', 'connection' => 'mobile'],
            $attempts[2]
        );
        // 4) mobile + country only
        $this->assertSame(
            ['country' => 'us', 'region' => '', 'city' => '', 'isp' => '', 'connection' => 'mobile'],
            $attempts[3]
        );
        // 5) safety net: country only, no connection type
        $this->assertSame(
            ['country' => 'us', 'region' => '', 'city' => '', 'isp' => '', 'connection' => ''],
            $attempts[4]
        );
    }

    public function test_geo_proxy_attempts_without_isp_skip_isp_attempt(): void
    {
        $attempts = MultiloginClient::geo_proxy_attempts(
            ['country' => 'US', 'region' => 'New York', 'city' => 'New York'],
            '',
            'mobile'
        );

        // No ISP -> first attempt is the full-location mobile attempt (no ISP key value).
        $this->assertSame('', $attempts[0]['isp']);
        $this->assertSame('mobile', $attempts[0]['connection']);
    }

    public function test_extract_profile_id_from_ids_array(): void
    {
        $id = MultiloginClient::_extract_profile_id([
            'data' => ['ids' => ['5f6f1ca2-3afc-4af6-98d1-376936d53718']],
        ]);

        $this->assertSame('5f6f1ca2-3afc-4af6-98d1-376936d53718', $id);
    }
}
