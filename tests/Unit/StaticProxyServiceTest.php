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
}
