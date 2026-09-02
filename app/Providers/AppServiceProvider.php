<?php

namespace App\Providers;

use App\Services\AuditService;
use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use App\Services\ProfileNumberService;
use App\Services\SystemStatusService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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

        $this->app->singleton(SystemStatusService::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')
            || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        View::composer('partials.sidebar', function ($view) {
            $view->with('systemStatus', app(SystemStatusService::class)->snapshot());
        });
    }
}
