<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * IP geolocation via ip-api.com (no API key required).
 *
 * Docs: https://ip-api.com/docs/api:json
 *  - Base: http://ip-api.com/json/{query}
 *  - Free tier is HTTP only and limited to 45 requests/min per IP.
 *
 * The returned array keeps the same normalized keys the rest of the app has
 * always relied on (city/region/country/country_code/org/isp/asn/…) so callers
 * did not need to change when we moved off ipinfo.io.
 */
class IpInfoService
{
    /** Named response fields we request from ip-api. */
    private const FIELDS = 'status,message,continent,continentCode,country,countryCode,region,regionName,city,district,zip,lat,lon,timezone,offset,currency,isp,org,as,asname,mobile,proxy,hosting,query';

    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    /**
     * Look up a single IP (or domain) via ip-api.com and normalize the result.
     *
     * @return array<string,mixed>
     */
    public function lookup(string $ip): array
    {
        $response = Http::timeout(15)->get('http://ip-api.com/json/'.rawurlencode($ip), [
            'fields' => self::FIELDS,
            'lang' => 'en',
        ]);

        if ($response->status() === 429) {
            throw new RuntimeException('ip-api.com rate limit reached (45 req/min). Try again shortly.');
        }
        $response->throw();

        $data = is_array($response->json()) ? $response->json() : [];
        if (($data['status'] ?? '') !== 'success') {
            $message = (string) ($data['message'] ?? 'unknown error');
            throw new RuntimeException("ip-api.com lookup failed for {$ip}: {$message}");
        }

        return $this->normalize($data, $ip);
    }

    /**
     * Map ip-api's response onto the app's normalized geo shape.
     *
     * @param  array<string,mixed>  $d
     * @return array<string,mixed>
     */
    private function normalize(array $d, string $ip): array
    {
        $as = trim((string) ($d['as'] ?? ''));
        $asn = '';
        if ($as !== '' && preg_match('/^(AS\d+)/i', $as, $m)) {
            $asn = strtoupper($m[1]);
        }

        // Prefer the human ISP name; fall back to org / AS name.
        $isp = trim((string) ($d['isp'] ?? ''));
        if ($isp === '') {
            $isp = trim((string) ($d['org'] ?? '')) ?: trim((string) ($d['asname'] ?? ''));
        }

        return [
            'ip' => (string) ($d['query'] ?? $ip),
            'hostname' => '',
            'city' => (string) ($d['city'] ?? ''),
            // ip-api: regionName = full name (Quebec), region = short code (QC).
            'region' => (string) ($d['regionName'] ?? ''),
            'region_code' => (string) ($d['region'] ?? ''),
            'country' => (string) ($d['country'] ?? ''),
            'country_code' => (string) ($d['countryCode'] ?? ''),
            'continent' => (string) ($d['continent'] ?? ''),
            'postal' => (string) ($d['zip'] ?? ''),
            'timezone' => (string) ($d['timezone'] ?? ''),
            'latitude' => isset($d['lat']) ? (float) $d['lat'] : null,
            'longitude' => isset($d['lon']) ? (float) $d['lon'] : null,
            'asn' => $asn,
            'asn_name' => (string) ($d['asname'] ?? $d['org'] ?? ''),
            'asn_domain' => '',
            'asn_type' => '',
            'org' => $as !== '' ? $as : (string) ($d['org'] ?? ''),
            'isp' => $isp,
            'is_hosting' => (bool) ($d['hosting'] ?? false),
            'is_mobile' => (bool) ($d['mobile'] ?? false),
            'is_anonymous' => (bool) ($d['proxy'] ?? false),
            'is_anycast' => false,
            'is_satellite' => false,
            'raw' => $d,
        ];
    }
}
