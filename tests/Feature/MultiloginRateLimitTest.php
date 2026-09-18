<?php

namespace Tests\Feature;

use App\Services\MultiloginClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MultiloginRateLimitTest extends TestCase
{
    public function test_http_429_surfaces_a_clear_rate_limit_error_without_cascading(): void
    {
        // Cloudflare 1015 rate-limit response on the profile search endpoint.
        Http::fake([
            '*' => Http::response('error code: 1015', 429, ['Retry-After' => '30']),
        ]);

        $client = new MultiloginClient('tok', 'https://api.multilogin.com');

        try {
            $client->get_profiles('ws-1');
            $this->fail('Expected a rate-limit RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('rate limit', $e->getMessage());
            $this->assertStringContainsString('30s', $e->getMessage());
        }

        // Must NOT cascade into the other (wrong) fallback endpoints on a 429.
        Http::assertSentCount(1);
    }
}
