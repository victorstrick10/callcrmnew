<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BrowserProfileController;
use App\Http\Controllers\CalendlyWebhookController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileNumberController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaticProxyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response('ok', 200);
});

// Temporary read-only diagnostic (no secrets) — remove after debugging.
Route::get('/debug/ml/{k}', function (string $k) {
    abort_unless($k === 'zx9k', 404);
    $out = [];
    foreach (\App\Models\Company::query()->get() as $c) {
        $cfg = $c->multiloginConfig();
        $client = app(\App\Services\MultiloginClient::class)->forCompany($c);
        [$ok, $msg] = $client->pingToken();
        $out[] = [
            'company' => $c->name,
            'col_base_url' => $c->multilogin_base_url,
            'cfg_base_url' => $cfg['base_url'] ?? null,
            'effective_base_url' => $client->base_url,
            'workspace_id' => $cfg['workspace_id'] ?? null,
            'simulation' => $client->simulation,
            'token_present' => $client->token !== '',
            'ping_ok' => $ok,
            'ping_msg' => $msg,
        ];
    }

    return response()->json($out);
});

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
Route::post('/appointments/{appointment}/enrich', [AppointmentController::class, 'enrich'])->name('appointments.enrich');
Route::post('/appointments/{appointment}/proxy/get', [AppointmentController::class, 'getProxy'])->name('appointments.proxy.get');
Route::post('/appointments/{appointment}/proxy/select/{candidateId}', [AppointmentController::class, 'selectProxy'])->name('appointments.proxy.select');
Route::post('/appointments/{appointment}/profiles/{mode}', [AppointmentController::class, 'createProfiles'])->name('appointments.profiles');

Route::post('/browser-profiles/{browserProfile}/retry', [BrowserProfileController::class, 'retry'])->name('browser-profiles.retry');

Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clients/export', [ClientController::class, 'export'])->name('clients.export');
Route::post('/clients/create-missing-profiles', [ClientController::class, 'createMissingProfiles'])
    ->name('clients.create-missing-profiles');
Route::post('/clients/enrich-geo', [ClientController::class, 'enrichGeo'])->name('clients.enrich-geo');

Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
Route::post('/companies/{company}/test-lead-api', [CompanyController::class, 'testLeadApi'])->name('companies.test-lead-api');
Route::post('/companies/{company}/test-calendly', [CompanyController::class, 'testCalendly'])->name('companies.test-calendly');
Route::post('/companies/{company}/multilogin/connect', [CompanyController::class, 'connectMultilogin'])->name('companies.multilogin.connect');
Route::post('/companies/{company}/multilogin/test', [CompanyController::class, 'testMultilogin'])->name('companies.multilogin.test');
Route::post('/companies/{company}/sync', [CompanyController::class, 'sync'])->name('companies.sync');

Route::get('/numbers', [ProfileNumberController::class, 'index'])->name('numbers.index');
Route::post('/numbers/sync', [ProfileNumberController::class, 'sync'])->name('numbers.sync');
Route::post('/numbers/sync-all', [ProfileNumberController::class, 'syncAll'])->name('numbers.sync-all');
Route::put('/numbers/{profileNumber}', [ProfileNumberController::class, 'update'])->name('numbers.update');

Route::get('/static-proxies', [StaticProxyController::class, 'index'])->name('static-proxies.index');
Route::post('/static-proxies', [StaticProxyController::class, 'store'])->name('static-proxies.store');
Route::post('/static-proxies/import', [StaticProxyController::class, 'import'])->name('static-proxies.import');
Route::post('/static-proxies/check-all', [StaticProxyController::class, 'checkAll'])->name('static-proxies.check-all');
Route::post('/static-proxies/proxycheap/sync', [StaticProxyController::class, 'syncProxyCheap'])->name('static-proxies.proxycheap.sync');
Route::post('/static-proxies/{staticProxy}/check', [StaticProxyController::class, 'check'])->name('static-proxies.check');
Route::put('/static-proxies/{staticProxy}', [StaticProxyController::class, 'update'])->name('static-proxies.update');
Route::delete('/static-proxies/{staticProxy}', [StaticProxyController::class, 'destroy'])->name('static-proxies.destroy');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/settings', [SettingsController::class, 'store'])->name('settings.store');
Route::post('/settings/multilogin/connect', [SettingsController::class, 'connectMultilogin'])->name('settings.multilogin.connect');
Route::post('/settings/test/{provider}', [SettingsController::class, 'test'])->name('settings.test');

Route::get('/logs', [AuditLogController::class, 'index'])->name('logs.index');

Route::post('/webhooks/calendly/{company?}', CalendlyWebhookController::class)->name('webhooks.calendly');

Route::post('/demo/appointment', [AppointmentController::class, 'demo'])->name('demo.appointment');
