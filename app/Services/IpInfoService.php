<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IpInfoService
{
    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    /**
     * Look up an IP using the IPinfo Core API (nested geo/as + network flags),
     * falling back to the legacy flat endpoint for older tokens. Returns a
     * normalized array with both the flat fields the app already relies on and
     * the richer Core fields (region_code, ASN name/type, hosting/mobile flags).
     */
    public function lookup(string $ip): array
    {
        $token = $this->token();

        // IPinfo Core: https://api.ipinfo.io/lookup/{ip}?token=...
        $response = Http::timeout(15)->get("https://api.ipinfo.io/lookup/{$ip}", [
            'token' => $token,
        ]);

        if ($response->successful() && is_array($response->json()) && isset($response->json()['geo'])) {
            return $this->normalizeCore($response->json(), $ip);
        }

        // Fallback: legacy flat endpoint.
        $legacy = Http::timeout(15)->get("https://ipinfo.io/{$ip}/json", ['token' => $token]);
        $legacy->throw();

        return $this->normalizeLegacy($legacy->json(), $ip);
    }

    private function token(): string
    {
        $token = $this->settings->getSettings('ipinfo')['api_token'] ?? '';
        if (! $token) {
            throw new RuntimeException('IPinfo API token is not configured.');
        }

        return $token;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function normalizeCore(array $data, string $ip): array
    {
        $geo = is_array($data['geo'] ?? null) ? $data['geo'] : [];
        $as = is_array($data['as'] ?? null) ? $data['as'] : [];

        return [
            'ip' => $data['ip'] ?? $ip,
            'hostname' => $data['hostname'] ?? '',
            'city' => $geo['city'] ?? '',
            'region' => $geo['region'] ?? '',
            'region_code' => $geo['region_code'] ?? '',
            'country' => $geo['country'] ?? '',
            'country_code' => $geo['country_code'] ?? '',
            'continent' => $geo['continent'] ?? '',
            'postal' => $geo['postal_code'] ?? '',
            'timezone' => $geo['timezone'] ?? '',
            'latitude' => isset($geo['latitude']) ? (float) $geo['latitude'] : null,
            'longitude' => isset($geo['longitude']) ? (float) $geo['longitude'] : null,
            'asn' => $as['asn'] ?? '',
            'asn_name' => $as['name'] ?? '',
            'asn_domain' => $as['domain'] ?? '',
            'asn_type' => $as['type'] ?? '',
            'org' => trim((string) (($as['asn'] ?? '').' '.($as['name'] ?? ''))),
            'isp' => $as['name'] ?? '',
            'is_hosting' => (bool) ($data['is_hosting'] ?? false),
            'is_mobile' => (bool) ($data['is_mobile'] ?? false),
            'is_anonymous' => (bool) ($data['is_anonymous'] ?? false),
            'is_anycast' => (bool) ($data['is_anycast'] ?? false),
            'is_satellite' => (bool) ($data['is_satellite'] ?? false),
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function normalizeLegacy(array $data, string $ip): array
    {
        $lat = $lon = null;
        if (! empty($data['loc'])) {
            $parts = explode(',', $data['loc'], 2);
            if (count($parts) === 2) {
                $lat = (float) $parts[0];
                $lon = (float) $parts[1];
            }
        }

        $org = (string) ($data['org'] ?? '');
        $isp = is_array($data['company'] ?? null) ? ($data['company']['name'] ?? '') : '';
        if (! $isp) {
            $isp = trim(preg_replace('/^AS\d+\s+/i', '', $org) ?? $org);
        }

        return [
            'ip' => $data['ip'] ?? $ip,
            'hostname' => $data['hostname'] ?? '',
            'city' => $data['city'] ?? '',
            'region' => $data['region'] ?? '',
            'region_code' => '',
            'country' => $data['country'] ?? '',
            'country_code' => $data['country'] ?? '',
            'continent' => '',
            'postal' => $data['postal'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'latitude' => $lat,
            'longitude' => $lon,
            'asn' => is_array($data['asn'] ?? null) ? ($data['asn']['asn'] ?? '') : '',
            'asn_name' => $isp,
            'asn_domain' => '',
            'asn_type' => '',
            'org' => $org,
            'isp' => $isp,
            'is_hosting' => false,
            'is_mobile' => false,
            'is_anonymous' => false,
            'is_anycast' => false,
            'is_satellite' => false,
            'raw' => $data,
        ];
    }
}
