<?php

namespace App\Services;

use App\Models\StaticProxy;
use RuntimeException;

class StaticProxyService
{
    public function randomEnabled(): StaticProxy
    {
        $proxy = StaticProxy::query()->enabled()->inRandomOrder()->first();
        if (! $proxy) {
            throw new RuntimeException('No enabled static proxies configured. Add at least one in Static Proxies.');
        }

        return $proxy;
    }
}
