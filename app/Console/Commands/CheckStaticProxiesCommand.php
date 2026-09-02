<?php

namespace App\Console\Commands;

use App\Models\StaticProxy;
use App\Services\StaticProxyService;
use Illuminate\Console\Command;

class CheckStaticProxiesCommand extends Command
{
    protected $signature = 'proxies:check {--limit=100}';

    protected $description = 'Check enabled static proxies via ipinfo (liveness + exit geo)';

    public function handle(StaticProxyService $service): int
    {
        $up = 0;
        $down = 0;

        foreach (StaticProxy::query()->enabled()->limit((int) $this->option('limit'))->get() as $proxy) {
            $service->check($proxy)['ok'] ? $up++ : $down++;
        }

        $this->info("Static proxy check: {$up} live, {$down} down.");

        return self::SUCCESS;
    }
}
