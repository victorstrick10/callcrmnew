<?php

namespace App\Http\Controllers;

use App\Models\StaticProxy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaticProxyController extends Controller
{
    public function index(): View
    {
        $proxies = StaticProxy::query()->orderBy('label')->orderBy('host')->get();

        return view('static-proxies.index', compact('proxies'));
    }

    public function store(Request $request): RedirectResponse
    {
        StaticProxy::create($this->validated($request));

        return redirect()->route('static-proxies.index')->with('success', 'Static proxy added.');
    }

    public function update(Request $request, StaticProxy $staticProxy): RedirectResponse
    {
        $data = $this->validated($request);

        if (! $request->filled('password')) {
            unset($data['password']);
        }

        $staticProxy->update($data);

        return redirect()->route('static-proxies.index')->with('success', 'Static proxy updated.');
    }

    public function destroy(StaticProxy $staticProxy): RedirectResponse
    {
        $label = $staticProxy->label ?: $staticProxy->host;
        $staticProxy->delete();

        return redirect()->route('static-proxies.index')->with('success', "Deleted proxy {$label}.");
    }

    /**
     * @return array{label:string,host:string,port:int,username:string,password?:string,protocol:string,enabled:bool}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'protocol' => ['required', 'string', 'in:http,socks5'],
            'enabled' => ['nullable'],
        ]);

        $data['label'] = $data['label'] ?? '';
        $data['username'] = $data['username'] ?? '';
        $data['enabled'] = $request->boolean('enabled');

        return $data;
    }
}
