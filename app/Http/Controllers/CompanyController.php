<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AppointmentSyncService;
use App\Services\CalendlyApiClient;
use App\Services\CompanyLeadApiClient;
use App\Services\IntegrationSettingsService;
use App\Services\LeadSyncService;
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
        $company->save();

        return redirect()->route('companies.edit', $company)->with('success', 'Company saved.');
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

            return back()->with('success', "Lead API OK — {$count} row(s) returned.");
        } catch (Throwable $e) {
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

            return back()->with('success', 'Calendly OK — user '.($me['user_uri'] ?? 'unknown').($org ? " · org {$org}" : ''));
        } catch (Throwable $e) {
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
            'slug' => ['required', 'string', 'max:80', 'alpha_dash'],
            'lead_api_url' => ['nullable', 'string', 'max:500'],
            'calendly_org_uri' => ['nullable', 'string', 'max:500'],
            'multilogin_base_url' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable'],
        ]);

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
