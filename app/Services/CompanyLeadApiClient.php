<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CompanyLeadApiClient
{
    public function fetchAll(Company $company): array
    {
        return $this->request($company, ['all' => '1']);
    }

    public function fetchByEmail(Company $company, string $email): ?array
    {
        $rows = $this->request($company, ['email' => $email]);

        return $rows[0] ?? null;
    }

    private function request(Company $company, array $query): array
    {
        $url = trim((string) $company->lead_api_url);
        $key = $company->getLeadApiKey();
        if ($url === '') {
            throw new RuntimeException("Company {$company->slug} missing lead API URL");
        }
        if (! $key) {
            throw new RuntimeException(
                "Company {$company->slug} missing lead API key (not set, or unreadable — re-enter key if APP_KEY changed)"
            );
        }

        $query['key'] = $key;

        // Company lead hosts (e.g. Diligent) often use incomplete SSL chains.
        // Skip verify so local/dev and production CRM can still call them.
        $response = Http::timeout(30)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->get($url, $query)
            ->throw()
            ->json();

        if (! is_array($response)) {
            return [];
        }

        if (isset($response['email'])) {
            return [$response];
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        if (isset($response['leads']) && is_array($response['leads'])) {
            return $response['leads'];
        }
        if (array_is_list($response)) {
            return $response;
        }

        return [];
    }
}
