<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal ProxyCheap REST API client. Auth uses X-Api-Key / X-Api-Secret
 * headers. See the Proxy-Cheap API Postman collection: GET /proxies returns
 * the list of active proxies.
 */
class ProxyCheapClient
{
    public function __construct(private IntegrationSettingsService $settings)
    {
    }

    private function config(): array
    {
        $cfg = $this->settings->getSettings('proxycheap');
        $key = trim((string) ($cfg['api_key'] ?? ''));
        $secret = trim((string) ($cfg['api_secret'] ?? ''));
        if ($key === '' || $secret === '') {
            throw new RuntimeException('ProxyCheap API key/secret are not configured.');
        }

        return [
            'key' => $key,
            'secret' => $secret,
            'base' => rtrim((string) ($cfg['base_url'] ?? 'https://api.proxy-cheap.com'), '/'),
        ];
    }

    /**
     * @return list<array<string,mixed>> Active proxies from ProxyCheap.
     */
    public function listProxies(): array
    {
        $cfg = $this->config();

        $response = Http::withHeaders([
            'X-Api-Key' => $cfg['key'],
            'X-Api-Secret' => $cfg['secret'],
            'Accept' => 'application/json',
        ])->timeout(30)->get($cfg['base'].'/proxies');

        $response->throw();
        $data = $response->json();

        // The endpoint returns {"proxies":[...]}; tolerate a bare array or {data:[...]} too.
        foreach (['proxies', 'data'] as $key) {
            if (is_array($data) && isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * Normalize a ProxyCheap proxy record into our static-proxy shape.
     *
     * @param  array<string,mixed>  $p
     * @return array{host:string,port:int,protocol:string,username:string,password:string,location:string,label:string,network_type:string,status:string}|null
     */
    public function normalize(array $p): ?array
    {
        $conn = is_array($p['connection'] ?? null) ? $p['connection'] : [];
        $auth = is_array($p['authentication'] ?? null) ? $p['authentication'] : [];
        $meta = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];

        $host = trim((string) ($conn['connectIp'] ?? $conn['publicIp'] ?? ''));

        [$port, $protocol] = match (true) {
            ! empty($conn['httpPort']) => [(int) $conn['httpPort'], 'http'],
            ! empty($conn['httpsPort']) => [(int) $conn['httpsPort'], 'http'],
            ! empty($conn['socks5Port']) => [(int) $conn['socks5Port'], 'socks5'],
            default => [0, 'http'],
        };

        if ($host === '' || $port < 1) {
            return null;
        }

        $isp = trim((string) ($meta['ispName'] ?? ''));
        $country = trim((string) ($p['countryCode'] ?? ''));
        $networkType = strtoupper((string) ($p['networkType'] ?? ''));

        return [
            'host' => $host,
            'port' => $port,
            'protocol' => $protocol,
            'username' => (string) ($auth['username'] ?? ''),
            'password' => (string) ($auth['password'] ?? ''),
            'location' => trim($country.($isp ? ' · '.$isp : '')),
            'label' => 'PC-'.(string) ($p['id'] ?? $host).($networkType ? ' '.$networkType : ''),
            'network_type' => $networkType,
            'status' => strtoupper((string) ($p['status'] ?? '')),
        ];
    }
}
