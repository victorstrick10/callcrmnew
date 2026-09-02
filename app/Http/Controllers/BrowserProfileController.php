<?php

namespace App\Http\Controllers;

use App\Models\BrowserProfile;
use App\Services\AppointmentService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class BrowserProfileController extends Controller
{
    public function retry(BrowserProfile $browserProfile, AppointmentService $service): RedirectResponse
    {
        try {
            $service->retryProfile($browserProfile);

            return redirect()
                ->route('appointments.show', $browserProfile->appointment_id)
                ->with('success', 'Profile retry succeeded.');
        } catch (Throwable $e) {
            return redirect()
                ->route('appointments.show', $browserProfile->appointment_id)
                ->with('danger', 'Retry failed: '.$e->getMessage());
        }
    }
}
