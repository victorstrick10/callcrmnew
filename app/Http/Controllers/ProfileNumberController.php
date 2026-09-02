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
            if (! $company || ! $company->getMultiloginToken()) {
                throw new RuntimeException(
                    'Company "'.($company?->name ?? 'unknown').'" has no Multilogin token configured.'
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
