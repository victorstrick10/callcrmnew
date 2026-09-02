<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CalendlyWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendlyWebhookController extends Controller
{
    public function __invoke(Request $request, CalendlyWebhookService $service, ?string $company = null): JsonResponse
    {
        $resolved = null;
        if ($company) {
            $resolved = Company::query()->where('slug', $company)->first();
            if (! $resolved) {
                return response()->json(['ok' => false, 'error' => 'Unknown company'], 404);
            }
        }

        $result = $service->handle($request->all(), $request, $resolved);
        $status = $result['http'] ?? (($result['ok'] ?? false) ? 200 : 400);
        unset($result['http']);

        return response()->json($result, $status);
    }
}
