<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AppointmentSyncService;
use App\Services\CalendlyApiClient;
use App\Services\CompanyLeadApiClient;
use App\Services\IntegrationSettingsService;
use App\Services\LeadSyncService;
use App\Services\MultiloginClient;
use App\Services\ProfileNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class CompanyController extends Controller
{
    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    public function index(): View
    {
        $companies = Company::query()->orderBy('name')->get();
        $masked = fn (?string $value) => $this->settings->masked($value);

        return view('companies.index', compact('companies', 'masked'));
    }

    public function create(): View
    {
        return view('companies.edit', [
            'company' => new Company([
                'enabled' => true,
                'multilogin_base_url' => 'https://api.multilogin.com',
            ]),
            'masked' => fn (?string $value) => $this->settings->masked($value),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $company = Company::create($data);
        $this->applySecrets($company, $request);
        $this->applyMultiloginConfig($company, $request);
        $company->save();

        return redirect()->route('companies.edit', $company)->with('success', 'Company created.');
    }

    public function edit(Company $company): View
    {
        return view('companies.edit', [
            'company' => $company,
            'masked' => fn (?string $value) => $this->settings->masked($value),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $company->fill($this->validated($request));
        $this->applySecrets($company, $request);
        $this->applyMultiloginConfig($company, $request);
        $company->save();

        return redirect()->route('companies.edit', $company)->with('success', 'Company saved.');
    }

    /**
     * Connect to this company's Multilogin workspace, discover folders/profiles,
     * store the results on the company, and mark its profile-number pool.
     */
    public function connectMultilogin(
        Company $company,
        MultiloginClient $multilogin,
        ProfileNumberService $numbers
    ): RedirectResponse {
        try {
            $client = $multilogin->forCompany($company);
            $cfg = $company->multiloginConfig();
            $result = $client->discover((string) ($cfg['workspace_id'] ?? ''));

            $cfg['discovery_cache'] = $result;
            $cfg['workspace_id'] = $result['selected_workspace_id'] ?? ($cfg['workspace_id'] ?? '');
            foreach (($result['endpoints'] ?? []) as $k => $v) {
                if ($v) {
                    $cfg[$k] = $v;
                }
            }
            $company->multilogin_config = $cfg;
            $company->save();

            $numbers->syncFromProfiles($company->id, $result['profiles'] ?? [], ! $client->simulation);

            $company->setServiceStatus('multilogin', true, $result['message'] ?? 'Connected.');

            return back()->with('success', 'Multilogin: '.($result['message'] ?? 'Connected.'));
        } catch (Throwable $e) {
            $company->setServiceStatus('multilogin', false, $e->getMessage());

            return back()->with('danger', 'Multilogin connect failed: '.$e->getMessage());
        }
    }

    /**
     * Run every service check (Lead API, Calendly, Multilogin) for all companies
     * and update their green/red status lights.
     */
    public function runAllChecks(
        Request $request,
        CompanyLeadApiClient $lead,
        CalendlyApiClient $calendly,
        MultiloginClient $multilogin
    ): RedirectResponse|\Illuminate\Http\JsonResponse {
        @set_time_limit(180);
        $log = [];

        foreach (Company::query()->orderBy('name')->get() as $company) {
            // Lead API
            if ($company->lead_api_url) {
                try {
                    $rows = $lead->fetchAll($company);
                    $company->setServiceStatus('lead', true, count($rows).' rows');
                    $log[] = "✓ {$company->name} · Lead API (".count($rows).' rows)';
                } catch (Throwable $e) {
                    $company->setServiceStatus('lead', false, $e->getMessage());
                    $log[] = "✗ {$company->name} · Lead API: ".$e->getMessage();
                }
            } else {
                $company->setServiceStatus('lead', false, 'No Lead API URL');
                $log[] = "• {$company->name} · Lead API not configured";
            }

            // Calendly
            if ($company->getCalendlyApiToken()) {
                try {
                    $me = $calendly->testToken($company);
                    $company->setServiceStatus('calendly', true, 'OK — '.($me['user_uri'] ?? 'user'));
                    $log[] = "✓ {$company->name} · Calendly";
                } catch (Throwable $e) {
                    $company->setServiceStatus('calendly', false, $e->getMessage());
                    $log[] = "✗ {$company->name} · Calendly: ".$e->getMessage();
                }
            } else {
                $company->setServiceStatus('calendly', false, 'No Calendly token');
                $log[] = "• {$company->name} · Calendly not configured";
            }

            // Multilogin token
            [$ok, $msg] = $multilogin->forCompany($company)->pingToken();
            $company->setServiceStatus('multilogin', $ok, $msg);
            $log[] = ($ok ? '✓' : '✗')." {$company->name} · Multilogin: {$msg}";
        }

        $message = 'System checks complete for all companies.';
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'log' => $log, 'created' => []]);
        }

        return back()->with('success', $message);
    }

    /**
     * Ping the company's Multilogin token and update the live/expired status light.
     */
    public function testMultilogin(Company $company, MultiloginClient $multilogin): RedirectResponse
    {
        [$ok, $message] = $multilogin->forCompany($company)->pingToken();
        $company->setServiceStatus('multilogin', $ok, $message);

        return back()->with($ok ? 'success' : 'danger', 'Multilogin token: '.$message);
    }

    /**
     * Persist the advanced Multilogin settings for this company (single source of truth).
     */
    private function applyMultiloginConfig(Company $company, Request $request): void
    {
        if (! $request->hasAny([
            'ml_workspace_id', 'ml_geo_folder_id', 'ml_static_folder_id', 'ml_template_profile_id',
            'ml_simulation_mode', 'ml_multilogin_proxy_protocol', 'ml_multilogin_proxy_session_type',
        ])) {
            return;
        }

        $cfg = $company->multiloginConfig();

        foreach ([
            'workspace_id', 'geo_folder_id', 'static_folder_id', 'template_profile_id',
            'template_name_filter', 'multilogin_proxy_type', 'multilogin_proxy_protocol',
            'multilogin_proxy_session_type', 'multilogin_proxy_ip_ttl', 'proxy_generate_endpoint',
            'browser_type', 'os_type',
        ] as $key) {
            if ($request->has('ml_'.$key)) {
                $cfg[$key] = (string) $request->input('ml_'.$key);
            }
        }

        $cfg['simulation_mode'] = $request->boolean('ml_simulation_mode') ? 'true' : 'false';
        $cfg['multilogin_proxy_strict_mode'] = $request->boolean('ml_strict_mode') ? 'true' : 'false';
        $cfg['base_url'] = $company->multilogin_base_url ?: 'https://api.multilogin.com';

        $company->multilogin_config = $cfg;
    }

    public function destroy(Company $company): RedirectResponse
    {
        $name = $company->name;
        $company->delete();

        return redirect()->route('companies.index')->with('success', "Deleted company {$name}.");
    }

    public function testLeadApi(Company $company, CompanyLeadApiClient $client): RedirectResponse
    {
        try {
            $rows = $client->fetchAll($company);
            $count = count($rows);
            $company->setServiceStatus('lead', true, "OK — {$count} row(s) returned.");

            return back()->with('success', "Lead API OK — {$count} row(s) returned.");
        } catch (Throwable $e) {
            $company->setServiceStatus('lead', false, $e->getMessage());

            return back()->with('danger', 'Lead API failed: '.$e->getMessage());
        }
    }

    public function testCalendly(Company $company, CalendlyApiClient $client): RedirectResponse
    {
        try {
            $me = $client->testToken($company);
            $org = $me['organization_uri'] ?? null;
            if ($org && ! preg_match('#^https://api\.calendly\.com/organizations/[A-Za-z0-9\-]+$#', (string) $company->calendly_org_uri)) {
                $company->calendly_org_uri = $org;
                $company->save();
            }

            $company->setServiceStatus('calendly', true, 'OK — user '.($me['user_uri'] ?? 'unknown'));

            return back()->with('success', 'Calendly OK — user '.($me['user_uri'] ?? 'unknown').($org ? " · org {$org}" : ''));
        } catch (Throwable $e) {
            $company->setServiceStatus('calendly', false, $e->getMessage());

            return back()->with('danger', 'Calendly failed: '.$e->getMessage());
        }
    }

    public function sync(
        Company $company,
        LeadSyncService $leads,
        AppointmentSyncService $appointments
    ): RedirectResponse {
        @set_time_limit(600);
        ini_set('max_execution_time', '600');

        try {
            $leadStats = $leads->syncCompany($company);

            if (! $company->getCalendlyApiToken()) {
                return back()->with(
                    'warning',
                    sprintf(
                        'API leads (c:%d u:%d). Calendly skipped — paste a Calendly personal token on Edit, then Sync again for call times.',
                        $leadStats['created'],
                        $leadStats['updated']
                    )
                );
            }

            $apptStats = $appointments->syncCompany($company);

            return back()->with(
                'success',
                sprintf(
                    'API leads (c:%d u:%d). Calendly: %d events → appointments (c:%d u:%d), leads matched %d, leads created from Calendly %d, skipped %d.',
                    $leadStats['created'],
                    $leadStats['updated'],
                    $apptStats['events'] ?? 0,
                    $apptStats['created'],
                    $apptStats['updated'],
                    $apptStats['leads_matched'] ?? 0,
                    $apptStats['leads_created'] ?? 0,
                    $apptStats['skipped']
                )
            );
        } catch (Throwable $e) {
            return back()->with('danger', 'Sync failed: '.$e->getMessage());
        }
    }

    /**
     * @return array{name:string,slug:string,lead_api_url:string,calendly_org_uri:string,multilogin_base_url:string,enabled:bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash'],
            'lead_api_url' => ['nullable', 'string', 'max:500'],
            'calendly_org_uri' => ['nullable', 'string', 'max:500'],
            'multilogin_base_url' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable'],
        ]);

        $data['short_name'] = trim((string) ($data['short_name'] ?? ''));
        $data['lead_api_url'] = $data['lead_api_url'] ?? '';
        $data['calendly_org_uri'] = $this->normalizeCalendlyOrgUri($data['calendly_org_uri'] ?? '');
        $data['multilogin_base_url'] = $data['multilogin_base_url'] ?: 'https://api.multilogin.com';
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }

    private function normalizeCalendlyOrgUri(?string $value): string
    {
        $value = trim((string) $value, " \t\n\r\0\x0B\"'");
        if ($value === '') {
            return '';
        }

        if (preg_match('#(https://api\.calendly\.com/organizations/[A-Za-z0-9\-]+)#', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    private function applySecrets(Company $company, Request $request): void
    {
        if ($request->filled('lead_api_key')) {
            $company->setLeadApiKey($request->input('lead_api_key'));
        }
        if ($request->filled('calendly_api_token')) {
            $company->setCalendlyApiToken($request->input('calendly_api_token'));
        }
        if ($request->filled('calendly_webhook_signing_key')) {
            $company->setCalendlyWebhookSigningKey($request->input('calendly_webhook_signing_key'));
        }
        if ($request->filled('multilogin_token')) {
            $company->setMultiloginToken($request->input('multilogin_token'));
        }
    }
}
