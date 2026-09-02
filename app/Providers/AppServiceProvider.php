<?php

namespace App\Providers;

use App\Services\AuditService;
use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use App\Services\ProfileNumberService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MultiloginClient::class, function ($app) {
            return new MultiloginClient(
                '',
                '',
                $app->make(IntegrationSettingsService::class),
                $app->make(AuditService::class),
                $app->make(ProfileNumberService::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->environment('production')
            || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
