<?php

namespace App\Providers;

use App\Services\AuditService;
use App\Services\IntegrationSettingsService;
use App\Services\MultiloginClient;
use App\Services\ProfileNumberService;
use App\Services\SystemStatusService;
use App\Services\TreeContext;
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
        $this->app->singleton(TreeContext::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')
            || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        View::composer('partials.sidebar', function ($view) {
            $tree = app(TreeContext::class);
            $view->with('systemStatus', app(SystemStatusService::class)->snapshot());
            $view->with('workspaceTrees', $tree->trees());
            $view->with('activeTree', $tree->active());
        });
    }
}
