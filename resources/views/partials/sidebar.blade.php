<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-mark">O</div>
    <div><strong>Orbit CRM</strong><span>Calendly × Multilogin</span></div>
  </div>
  <nav>
    <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">◫ <span>Dashboard</span></a>
    <a class="{{ request()->routeIs('appointments.*') ? 'active' : '' }}" href="{{ route('appointments.index') }}">◷ <span>Appointments</span></a>
    <a class="{{ request()->routeIs('clients.*') ? 'active' : '' }}" href="{{ route('clients.index') }}">◎ <span>Clients</span></a>
    <a class="{{ request()->routeIs('companies.*') ? 'active' : '' }}" href="{{ route('companies.index') }}">▣ <span>Companies</span></a>
    <a class="{{ request()->routeIs('numbers.*') ? 'active' : '' }}" href="{{ route('numbers.index') }}"># <span>Profile Numbers</span></a>
    <a class="{{ request()->routeIs('static-proxies.*') ? 'active' : '' }}" href="{{ route('static-proxies.index') }}">⌥ <span>Static Proxies</span></a>
    <div class="nav-label">System</div>
    <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">⚙ <span>Integrations</span></a>
    <a class="{{ request()->routeIs('logs.*') ? 'active' : '' }}" href="{{ route('logs.index') }}">≡ <span>Audit Logs</span></a>
  </nav>
  <div class="sidebar-foot">
    <div class="status-dot"></div>
    <div><strong>Local mode</strong><span>127.0.0.1:8000</span></div>
  </div>
</aside>
