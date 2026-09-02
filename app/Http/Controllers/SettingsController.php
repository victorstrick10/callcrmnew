<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function index(SettingsService $settings): View
    {
        return view('settings.index', $settings->pageData());
    }

    public function store(Request $request, SettingsService $settings): RedirectResponse
    {
        try {
            $provider = (string) $request->input('provider');
            $settings->saveProvider($provider, $request->all());

            return redirect()->route('settings.index')->with('success', ucfirst($provider).' settings saved.');
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('danger', $e->getMessage());
        }
    }

    public function connectMultilogin(Request $request, SettingsService $settings): RedirectResponse
    {
        try {
            $message = $settings->connectMultilogin($request->all());

            return redirect()->route('settings.index')->with('success', $message);
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('danger', 'Multilogin discovery failed: '.$e->getMessage());
        }
    }

    public function test(string $provider, SettingsService $settings): RedirectResponse
    {
        try {
            $message = $settings->testProvider($provider);

            return redirect()->route('settings.index')->with('success', $message);
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('danger', ucfirst($provider).' test failed: '.$e->getMessage());
        }
    }
}
