<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-mark"><img src="{{ asset('logo.svg') }}" alt="Calendly Ai logo" width="42" height="42"></div>
    <div><strong>Calendly Ai</strong><span>Calendly × Multilogin</span></div>
  </div>
  <nav>
    <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">◫ <span>Dashboard</span></a>
    <a class="{{ request()->routeIs('clients.*') || request()->routeIs('appointments.*') ? 'active' : '' }}" href="{{ route('clients.index') }}">◎ <span>Clients</span></a>
    <a class="{{ request()->routeIs('outcomes.index') ? 'active' : '' }}" href="{{ route('outcomes.index') }}">✎ <span>Call Stats</span></a>
    <a class="{{ request()->routeIs('outcomes.lines') ? 'active' : '' }}" href="{{ route('outcomes.lines') }}">🤝 <span>Lines</span></a>
    <div class="nav-label">System</div>
    <a class="{{ request()->routeIs('numbers.*') ? 'active' : '' }}" href="{{ route('numbers.index') }}"># <span>Profile Numbers</span></a>
    <a class="{{ request()->routeIs('static-proxies.*') ? 'active' : '' }}" href="{{ route('static-proxies.index') }}">⌥ <span>Static Proxies</span></a>
    <a class="{{ request()->routeIs('companies.*') ? 'active' : '' }}" href="{{ route('companies.index') }}">▣ <span>Companies</span></a>
    <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">⚙ <span>Integrations</span></a>
    <a class="{{ request()->routeIs('logs.*') ? 'active' : '' }}" href="{{ route('logs.index') }}">≡ <span>Audit Logs</span></a>
  </nav>

  @php($sys = $systemStatus ?? null)
  @if ($sys)
  <a class="sidebar-status" href="{{ route('settings.index', ['tab' => 'sync']) }}" title="How sync works: a Calendly call arrives → ip-api.com reads the lead geo from its IP → you build a Multilogin browser profile. Auto-sync runs {{ $sys['auto_sync'] }}.">
    <div class="sidebar-status-head">
      <span class="status-dot {{ $sys['healthy'] ? 'ok' : 'warn' }}"></span>
      <strong>System status</strong>
    </div>
    <div class="svc-dots">
      <span class="svc-dot state-{{ $sys['ipinfo']['state'] }}">Geo</span>
      <span class="svc-dot state-{{ $sys['multilogin']['state'] }}">Multilogin</span>
      <span class="svc-dot state-{{ $sys['calendly']['configured'] ? 'up' : 'missing' }}">Calendly</span>
    </div>
    <span class="sidebar-status-sub">Sync {{ $sys['auto_sync'] }} · {{ $sys['last_sync_human'] }}</span>
  </a>
  @endif
</aside>
