<?php

namespace App\Http\Controllers;

use App\Models\StaticProxy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class StaticProxyController extends Controller
{
    /** Known providers shown as sub-menu tabs. */
    public const PROVIDERS = [
        'proxycheap' => 'ProxyCheap',
        'mobilehop' => 'MobileHop',
    ];

    public function index(Request $request): View
    {
        $provider = trim((string) $request->query('provider', ''));

        $all = StaticProxy::query()->orderBy('provider')->orderBy('label')->orderBy('host')->get();
        $proxies = $provider !== ''
            ? $all->where('provider', $provider)->values()
            : $all;

        $counts = $all->groupBy(fn ($p) => $p->provider ?: 'other')->map->count();

        return view('static-proxies.index', [
            'proxies' => $proxies,
            'all' => $all,
            'provider' => $provider,
            'providers' => self::PROVIDERS,
            'counts' => $counts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        StaticProxy::create($this->validated($request));

        return redirect()
            ->route('static-proxies.index', array_filter(['provider' => $request->input('provider', '')]))
            ->with('success', 'Static proxy added.');
    }

    /**
     * Bulk-import proxies pasted from a provider dashboard (ProxyCheap / MobileHop).
     * Accepts the "Name / Location / IP Address host:port / Username / Password" layout.
     */
    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40'],
            'protocol' => ['required', 'string', 'in:http,socks5'],
            'raw' => ['required', 'string'],
        ]);

        $records = $this->parseProxyBlock($data['raw']);
        $created = 0;
        $skipped = 0;

        foreach ($records as $r) {
            $exists = StaticProxy::query()
                ->where('host', $r['host'])
                ->where('port', $r['port'])
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            StaticProxy::create([
                'label' => $r['label'] ?? '',
                'provider' => $data['provider'],
                'location' => $r['location'] ?? '',
                'host' => $r['host'],
                'port' => $r['port'],
                'username' => $r['username'] ?? '',
                'password' => $r['password'] ?? '',
                'protocol' => $data['protocol'],
                'enabled' => true,
            ]);
            $created++;
        }

        $type = $created > 0 ? 'success' : 'warning';

        return redirect()
            ->route('static-proxies.index', ['provider' => $data['provider']])
            ->with($type, "Imported {$created} proxy(ies), skipped {$skipped} duplicate(s).");
    }

    /** Probe a single proxy against ipinfo.io to confirm it is live. */
    public function check(StaticProxy $staticProxy): RedirectResponse
    {
        $result = $this->probe($staticProxy);
        $type = $result['ok'] ? 'success' : 'danger';
        $msg = $result['ok']
            ? "Proxy {$staticProxy->host}:{$staticProxy->port} is LIVE (exit IP {$result['ip']})."
            : "Proxy {$staticProxy->host}:{$staticProxy->port} is DOWN: {$result['error']}";

        return back()->with($type, $msg);
    }

    /** Probe every proxy (bounded) and report how many are live. */
    public function checkAll(Request $request): RedirectResponse
    {
        @set_time_limit(180);
        $provider = trim((string) $request->input('provider', ''));
        $query = StaticProxy::query();
        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        $up = 0;
        $down = 0;
        foreach ($query->limit(100)->get() as $proxy) {
            $this->probe($proxy)['ok'] ? $up++ : $down++;
        }

        return redirect()
            ->route('static-proxies.index', array_filter(['provider' => $provider]))
            ->with($up > 0 ? 'success' : 'warning', "Proxy check: {$up} live, {$down} down (via ipinfo.io).");
    }

    /**
     * Route a request through the proxy to ipinfo.io and record the result.
     *
     * @return array{ok:bool,ip?:string,error?:string}
     */
    private function probe(StaticProxy $proxy): array
    {
        $scheme = strtolower((string) $proxy->protocol) === 'socks5' ? 'socks5h' : 'http';
        $auth = '';
        if (trim((string) $proxy->username) !== '') {
            $auth = rawurlencode($proxy->username).':'.rawurlencode((string) $proxy->password).'@';
        }
        $proxyUrl = "{$scheme}://{$auth}{$proxy->host}:{$proxy->port}";

        try {
            $response = Http::withOptions(['proxy' => $proxyUrl])
                ->timeout(20)
                ->get('https://ipinfo.io/json');

            if ($response->successful()) {
                $ip = (string) ($response->json()['ip'] ?? '');
                $proxy->forceFill([
                    'last_check_status' => 'up',
                    'exit_ip' => $ip,
                    'last_checked_at' => now(),
                ])->save();

                return ['ok' => true, 'ip' => $ip];
            }

            $proxy->forceFill([
                'last_check_status' => 'down',
                'exit_ip' => '',
                'last_checked_at' => now(),
            ])->save();

            return ['ok' => false, 'error' => 'HTTP '.$response->status()];
        } catch (Throwable $e) {
            $proxy->forceFill([
                'last_check_status' => 'down',
                'exit_ip' => '',
                'last_checked_at' => now(),
            ])->save();

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function update(Request $request, StaticProxy $staticProxy): RedirectResponse
    {
        $data = $this->validated($request);

        if (! $request->filled('password')) {
            unset($data['password']);
        }

        $staticProxy->update($data);

        return redirect()
            ->route('static-proxies.index', array_filter(['provider' => $staticProxy->provider]))
            ->with('success', 'Static proxy updated.');
    }

    public function destroy(StaticProxy $staticProxy): RedirectResponse
    {
        $label = $staticProxy->label ?: $staticProxy->host;
        $provider = $staticProxy->provider;
        $staticProxy->delete();

        return redirect()
            ->route('static-proxies.index', array_filter(['provider' => $provider]))
            ->with('success', "Deleted proxy {$label}.");
    }

    /**
     * Parse a pasted provider block into proxy records.
     *
     * @return list<array{label?:string,location?:string,host:string,port:int,username?:string,password?:string}>
     */
    private function parseProxyBlock(string $raw): array
    {
        $records = [];
        $cur = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            if (preg_match('/^Location:\s*(.+)$/i', $t, $m)) {
                $cur['location'] = trim($m[1]);
            } elseif (preg_match('/^IP\s*Address:\s*([0-9a-zA-Z.\-]+):(\d{2,5})/i', $t, $m)) {
                $cur['host'] = $m[1];
                $cur['port'] = (int) $m[2];
            } elseif (preg_match('/^Username:\s*(.+)$/i', $t, $m)) {
                $cur['username'] = trim($m[1]);
            } elseif (preg_match('/^Password:\s*(.+)$/i', $t, $m)) {
                $cur['password'] = trim($m[1]);
                if (! empty($cur['host']) && ! empty($cur['port'])) {
                    $records[] = $cur;
                }
                $cur = [];
            } elseif (! preg_match('/^(Name|Customer|Credentials|Location|Proxy|Search|Per Page|Server ID)\b/i', $t)) {
                // Treat a non-field line as the record name/header (e.g. "F4A2-2026-06-12\tmh_Kristi Duan").
                $cur['label'] = trim(preg_split('/\t|\s{2,}/', $t)[0]);
            }
        }

        return $records;
    }

    /**
     * @return array{label:string,provider:string,location:string,host:string,port:int,username:string,password?:string,protocol:string,enabled:bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'protocol' => ['required', 'string', 'in:http,socks5'],
            'enabled' => ['nullable'],
        ]);

        $data['label'] = $data['label'] ?? '';
        $data['provider'] = $data['provider'] ?? '';
        $data['location'] = $data['location'] ?? '';
        $data['username'] = $data['username'] ?? '';
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }
}
