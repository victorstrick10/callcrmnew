<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ProfileNumber;
use App\Services\MultiloginClient;
use App\Services\ProfileNumberService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProfileNumberController extends Controller
{
    public function index(Request $request, ProfileNumberService $numbers): View
    {
        $companies = Company::query()->orderBy('name')->get();
        $companyId = $request->integer('company_id') ?: null;
        $company = $companyId
            ? $companies->firstWhere('id', $companyId)
            : null;

        $used = collect();
        $availableCount = 0;
        $nextRows = collect();
        $nextNumbersLabel = '—';

        if ($company) {
            $numbers->initializeForCompany($company->id);
            $used = ProfileNumber::query()
                ->where('company_id', $company->id)
                ->where('status', '!=', 'available')
                ->orderBy('number')
                ->get();
            $availableCount = ProfileNumber::query()
                ->where('company_id', $company->id)
                ->where('status', 'available')
                ->count();
            $nextRows = ProfileNumber::query()
                ->where('company_id', $company->id)
                ->where('status', 'available')
                ->orderBy('number')
                ->limit(10)
                ->get();
            $nextNumbersLabel = $nextRows->take(2)
                ->map(fn ($row) => $numbers->formatNumber($row->number))
                ->implode(', ') ?: '—';
        }

        return view('numbers.index', [
            'companies' => $companies,
            'company' => $company,
            'companyId' => $company?->id,
            'used' => $used,
            'availableCount' => $availableCount,
            'nextRows' => $nextRows,
            'nextNumbersLabel' => $nextNumbersLabel,
            'formatNumber' => fn (int $n) => $numbers->formatNumber($n),
        ]);
    }

    public function sync(Request $request, SettingsService $settings): RedirectResponse
    {
        $company = Company::query()->find($request->integer('company_id'));
        if (! $company) {
            return redirect()->route('numbers.index')->with('danger', 'Select a company before syncing Multilogin profiles.');
        }

        try {
            $result = $settings->syncNumbers($company);

            return redirect()
                ->route('numbers.index', ['company_id' => $company->id])
                ->with(
                    'success',
                    "Synchronization complete for {$company->name}: {$result['numbers_marked']} numbers marked from {$result['profiles_seen']} profiles."
                );
        } catch (Throwable $e) {
            return redirect()
                ->route('numbers.index', ['company_id' => $company->id])
                ->with('danger', 'Synchronization failed: '.$e->getMessage());
        }
    }

    /** Sync Multilogin profile numbers for every company at once. */
    public function syncAll(Request $request, SettingsService $settings, MultiloginClient $multilogin): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        @set_time_limit(180);
        $done = 0;
        $skipped = 0;
        $log = [];

        foreach (Company::query()->orderBy('name')->get() as $company) {
            if (! $multilogin->isConfiguredFor($company)) {
                $skipped++;
                $log[] = "{$company->name}: skipped (no Multilogin token)";

                continue;
            }
            try {
                $result = $settings->syncNumbers($company);
                $done++;
                $log[] = "✓ {$company->name}: {$result['numbers_marked']} numbers marked from {$result['profiles_seen']} profiles";
            } catch (Throwable $e) {
                $log[] = "✗ {$company->name}: {$e->getMessage()}";
            }
        }

        $message = "Synced profile numbers for {$done} company(ies)".($skipped ? ", {$skipped} skipped" : '').'.';

        if ($request->wantsJson()) {
            return response()->json(['ok' => $done > 0, 'message' => $message, 'log' => $log, 'created' => []]);
        }

        return back()->with($done > 0 ? 'success' : 'warning', $message);
    }

    public function update(
        Request $request,
        ProfileNumber $profileNumber,
        MultiloginClient $multilogin,
        ProfileNumberService $numbers
    ): RedirectResponse {
        $data = $request->validate([
            'profile_name' => ['required', 'string', 'max:500'],
        ]);

        $company = $profileNumber->company;
        $redirect = redirect()->route('numbers.index', ['company_id' => $profileNumber->company_id]);

        try {
            if (! $multilogin->isConfiguredFor($company)) {
                throw new RuntimeException(
                    'No Multilogin token available for "'.($company?->name ?? 'unknown').'". '
                    .'Configure it on the company or in Integrations → Multilogin.'
                );
            }

            $updated = $numbers->renameProfile(
                $profileNumber,
                $data['profile_name'],
                $multilogin->forCompany($company)
            );

            $message = $updated->number === $profileNumber->number
                ? 'Profile renamed to “'.$updated->profile_name.'” (Multilogin + CRM).'
                : 'Profile remapped '.$numbers->formatNumber((int) $profileNumber->number)
                    .' → '.$numbers->formatNumber((int) $updated->number)
                    .' and renamed on Multilogin + CRM.';

            return redirect()
                ->route('numbers.index', ['company_id' => $updated->company_id])
                ->with('success', $message);
        } catch (Throwable $e) {
            return $redirect->with('danger', 'Rename failed: '.$e->getMessage());
        }
    }
}
