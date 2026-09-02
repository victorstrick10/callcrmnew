<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class IpInfoService
{
    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    public function lookup(string $ip): array
    {
        $cfg = $this->settings->getSettings('ipinfo');
        $token = $cfg['api_token'] ?? '';
        if (! $token) {
            throw new RuntimeException('IPinfo API token is not configured.');
        }

        $response = Http::timeout(15)->get("https://ipinfo.io/{$ip}/json", [
            'token' => $token,
        ]);
        $response->throw();
        $data = $response->json();

        $lat = $lon = null;
        if (! empty($data['loc'])) {
            $parts = explode(',', $data['loc'], 2);
            if (count($parts) === 2) {
                $lat = (float) $parts[0];
                $lon = (float) $parts[1];
            }
        }

        return [
            'city' => $data['city'] ?? '',
            'region' => $data['region'] ?? '',
            'country_code' => $data['country'] ?? '',
            'country' => $data['country'] ?? '',
            'postal' => $data['postal'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'latitude' => $lat,
            'longitude' => $lon,
            'ip' => $data['ip'] ?? $ip,
            'org' => $data['org'] ?? '',
            'isp' => is_array($data['company'] ?? null) ? ($data['company']['name'] ?? '') : '',
            'asn' => is_array($data['asn'] ?? null) ? ($data['asn']['asn'] ?? '') : '',
            'raw' => $data,
        ];
    }
}
