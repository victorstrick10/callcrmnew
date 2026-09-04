@extends('layouts.app')

@section('title', 'Static Proxies')
@section('page_title', 'Static Proxies')
@section('page_subtitle', 'Pool of proxies assigned randomly when creating STATIC Multilogin profiles')

@section('content')
@php
  $providerLabel = fn ($p) => $providers[$p] ?? ($p ?: 'Other');
@endphp

<div class="tabs" role="tablist">
  <a class="chip-btn {{ ($provider ?? '') === '' ? 'chip-active' : '' }}" href="{{ route('static-proxies.index', ['type' => $type]) }}">All ({{ $scoped->count() }})</a>
  @foreach ($providers as $key => $label)
    <a class="chip-btn {{ ($provider ?? '') === $key ? 'chip-active' : '' }}" href="{{ route('static-proxies.index', ['provider' => $key, 'type' => $type]) }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
  @endforeach
  @if (($counts['other'] ?? 0) > 0)
    <a class="chip-btn {{ ($provider ?? '') === 'other' ? 'chip-active' : '' }}" href="{{ route('static-proxies.index', ['provider' => 'other', 'type' => $type]) }}">Other ({{ $counts['other'] }})</a>
  @endif
</div>
<div class="tabs" role="tablist" style="margin-top:-6px">
  <a class="chip-btn {{ $type === 'mobile' ? 'chip-active' : '' }}" href="{{ route('static-proxies.index', array_filter(['provider' => $provider, 'type' => 'mobile'])) }}">📱 Mobile only</a>
  <a class="chip-btn {{ $type === 'all' ? 'chip-active' : '' }}" href="{{ route('static-proxies.index', array_filter(['provider' => $provider, 'type' => 'all'])) }}">All types</a>
</div>

<div class="stat-grid three">
  <div class="stat-card"><span>Total {{ $provider ? '· '.$providerLabel($provider) : '' }}</span><strong>{{ $proxies->count() }}</strong><small>Configured proxies</small></div>
  <div class="stat-card"><span>Enabled</span><strong>{{ $proxies->where('enabled', true)->count() }}</strong><small>Available for random pick</small></div>
  <div class="stat-card"><span>Disabled</span><strong>{{ $proxies->where('enabled', false)->count() }}</strong><small>Excluded from pool</small></div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Proxy pool {{ $provider ? '· '.$providerLabel($provider) : '' }}</h2><p>Edit inline, toggle enabled, or check live status via ipinfo.io</p></div>
    <div class="pool-actions">
      <form method="post" action="{{ route('static-proxies.check-all') }}" style="margin:0">
        @csrf
        <input type="hidden" name="provider" value="{{ $provider }}">
        <button class="btn btn-secondary" type="submit">🌐 Check all live</button>
      </form>
      <a class="btn btn-secondary" href="{{ route('static-proxies.export-credentials') }}" title="Download host/port/username/password for every ProxyCheap + MobileHop proxy">⬇ Export credentials</a>
      <form method="post" action="{{ route('static-proxies.change-ip-proxycheap') }}" style="margin:0">
        @csrf
        <button class="btn btn-secondary" type="submit" title="Re-verify current exit IPs for all ProxyCheap proxies (they rotate automatically)">🔄 Change IP · ProxyCheap</button>
      </form>
      <form method="post" action="{{ route('static-proxies.soft-reset-mobilehop') }}" style="margin:0" onsubmit="return confirm('Soft reset all MobileHop proxies? This clears their cached geo and re-verifies current IPs.');">
        @csrf
        <button class="btn btn-secondary" type="submit" title="Fast/soft reset: clear cached geo for all MobileHop proxies and re-verify current IPs">♻ Soft reset · MobileHop</button>
      </form>
      <form method="post" action="{{ route('static-proxies.hard-reset-all') }}" style="margin:0" onsubmit="return confirm('Hard reset ALL proxies (ProxyCheap + MobileHop)? This clears every proxy\'s cached geo and re-verifies the whole pool.');">
        @csrf
        <button class="btn btn-danger" type="submit" title="Hard reset: clear cached geo for every proxy across all providers and re-verify the whole pool">⟲ Hard reset · all</button>
      </form>
    </div>
  </div>
  @foreach ($proxies as $proxy)
    @php $formId = 'proxy-update-'.$proxy->id; @endphp
    <form id="{{ $formId }}" method="post" action="{{ route('static-proxies.update', $proxy) }}" hidden>
      @csrf
      @method('PUT')
    </form>
  @endforeach
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Provider</th>
          <th>Label</th>
          <th>Location</th>
          <th>Live (ipinfo geo)</th>
          <th>Enabled</th>
          <th>Connection</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      @forelse ($proxies as $proxy)
      @php $formId = 'proxy-update-'.$proxy->id; $connId = 'proxy-conn-'.$proxy->id; @endphp
      <tr>
        <td>
          <select form="{{ $formId }}" name="provider" style="min-width:110px">
            <option value="" @selected(! $proxy->provider)>Other</option>
            @foreach ($providers as $key => $label)
              <option value="{{ $key }}" @selected($proxy->provider === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </td>
        <td>
          <input type="hidden" form="{{ $formId }}" name="network_type" value="{{ $proxy->network_type }}">
          <input form="{{ $formId }}" name="label" value="{{ $proxy->label }}" placeholder="—" style="min-width:120px">
          @if ($proxy->network_type)<small class="role-chip {{ $proxy->network_type === 'mobile' ? 'on' : '' }}">{{ strtoupper($proxy->network_type) }}</small>@endif
        </td>
        <td><input form="{{ $formId }}" name="location" value="{{ $proxy->location }}" placeholder="—" style="min-width:100px"></td>
        <td>
          @php
            $dispTz = config('app.display_timezone') ?: config('app.timezone');
            $lastCheckLabel = $proxy->last_checked_at
              ? $proxy->last_checked_at->copy()->setTimezone($dispTz)->format('d.m.Y H:i')
              : null;
          @endphp
          <span class="svc-status state-{{ $proxy->checkState() }}" title="{{ $lastCheckLabel ? 'Last check '.$lastCheckLabel.' (GMT+1)' : 'Not checked yet' }}">
            <span class="dot"></span>{{ $proxy->last_check_status ?: 'untested' }}
          </span>
          @if ($proxy->last_checked_at)
            <small class="muted" style="display:block;margin-top:4px">Last check {{ $lastCheckLabel }} (GMT+1) · {{ $proxy->last_checked_at->diffForHumans() }}</small>
          @else
            <small class="muted" style="display:block;margin-top:4px">Not checked yet</small>
          @endif
          @if ($proxy->exit_ip || $proxy->exit_country)
          <div class="geo-lines">
            <span><b>Country</b>{{ \App\Support\CountryFlag::emoji($proxy->exit_country) }} {{ $proxy->exit_country ?: '—' }}</span>
            <span><b>Region</b>{{ $proxy->exit_region ?: '—' }}</span>
            <span><b>City</b>{{ $proxy->exit_city ?: '—' }}</span>
            <span><b>ISP</b>{{ $proxy->exit_isp ?: '—' }}</span>
            <span><b>IP</b>{{ $proxy->exit_ip ?: '—' }}</span>
          </div>
          @endif
        </td>
        <td>
          <label class="switch-row" style="margin:0;padding:8px 10px">
            <input form="{{ $formId }}" type="checkbox" name="enabled" value="1" @checked($proxy->enabled)>
            <span class="switch"></span>
          </label>
        </td>
        <td><button type="button" class="mini-btn ghost proxy-conn-toggle" data-target="{{ $connId }}">Show ▾</button></td>
        <td style="white-space:nowrap">
          <button class="btn btn-secondary" type="submit" form="{{ $formId }}">Save</button>
          <form method="post" action="{{ route('static-proxies.check', $proxy) }}" style="display:inline;margin-left:6px">
            @csrf
            <button class="btn btn-secondary" type="submit">Check</button>
          </form>
          <form method="post" action="{{ route('static-proxies.destroy', $proxy) }}" style="display:inline;margin-left:6px" onsubmit="return confirm('Delete this proxy?');">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
      <tr id="{{ $connId }}" class="proxy-conn-row" hidden>
        <td colspan="7">
          <div class="conn-grid">
            <div><label>Host</label><input form="{{ $formId }}" name="host" value="{{ $proxy->host }}" required></div>
            <div><label>Port</label><input form="{{ $formId }}" type="number" name="port" value="{{ $proxy->port }}" min="1" max="65535" required></div>
            <div><label>Protocol</label>
              <select form="{{ $formId }}" name="protocol">
                <option value="http" @selected($proxy->protocol === 'http')>HTTP</option>
                <option value="socks5" @selected($proxy->protocol === 'socks5')>SOCKS5</option>
              </select>
            </div>
            <div><label>Username</label><input form="{{ $formId }}" name="username" value="{{ $proxy->username }}" placeholder="—"></div>
            <div><label>Password</label><input form="{{ $formId }}" type="password" name="password" placeholder="{{ $proxy->password ? '••••••••' : '—' }}"></div>
          </div>
        </td>
      </tr>
      @empty
      <tr><td colspan="7" class="empty">No static proxies{{ $provider ? ' for '.$providerLabel($provider) : '' }} yet. Add or import below.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>ProxyCheap API sync</h2><p>Pull active <strong>mobile</strong> proxies directly from your ProxyCheap account (residential skipped)</p></div>
    <span class="status-light state-{{ $proxyCheapConfigured ? 'up' : 'unknown' }}"><span class="dot"></span>{{ $proxyCheapConfigured ? 'Connected' : 'Not configured' }}</span>
  </div>
  <form method="post" action="{{ route('static-proxies.proxycheap.sync') }}">
    @csrf
    <div class="form-grid">
      <div><label>API key <span>{{ $proxyCheapMasked }}</span></label><input type="password" name="api_key" placeholder="Leave blank to keep saved key"></div>
      <div><label>API secret</label><input type="password" name="api_secret" placeholder="Leave blank to keep saved secret"></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">⟳ Save &amp; sync ProxyCheap (mobile)</button>
    </div>
  </form>
</div>

<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><div><h2>Bulk import — MobileHop</h2><p>Paste the MobileHop proxy list (Name / Location / IP:port / Username / Password). ProxyCheap uses its API — coming next.</p></div></div>
    <form method="post" action="{{ route('static-proxies.import') }}">
      @csrf
      <div class="form-grid">
        <div>
          <label>Provider</label>
          <select name="provider">
            @foreach ($providers as $key => $label)
              <option value="{{ $key }}" @selected(($provider ?: 'mobilehop') === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Protocol</label>
          <select name="protocol">
            <option value="http">HTTP</option>
            <option value="socks5">SOCKS5</option>
          </select>
        </div>
      </div>
      <label>Paste list</label>
      <textarea name="raw" rows="8" class="code-input" placeholder="F4A2-2026-06-12&#9;mh_Kristi Duan&#10;Location: New York, NY&#10;IP Address: 199.188.92.125:8000&#10;Username: proxy&#10;Password: 1YSqP9u&#10;..."></textarea>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">⭳ Import proxies</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <div class="panel-head"><div><h2>Add one proxy</h2><p>Manual entry joins the random pool when enabled</p></div></div>
    <form method="post" action="{{ route('static-proxies.store') }}">
      @csrf
      <div class="form-grid">
        <div>
          <label>Provider</label>
          <select name="provider">
            <option value="">Other</option>
            @foreach ($providers as $key => $label)
              <option value="{{ $key }}" @selected(($provider ?? '') === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Network type</label>
          <select name="network_type">
            <option value="mobile" @selected(old('network_type', 'mobile') === 'mobile')>Mobile</option>
            <option value="residential" @selected(old('network_type') === 'residential')>Residential</option>
            <option value="">Other</option>
          </select>
        </div>
        <div><label>Label</label><input name="label" value="{{ old('label') }}" placeholder="Optional name"></div>
        <div><label>Location</label><input name="location" value="{{ old('location') }}" placeholder="City, ST"></div>
        <div><label>Host</label><input name="host" value="{{ old('host') }}" required placeholder="199.188.92.125"></div>
        <div><label>Port</label><input type="number" name="port" value="{{ old('port', 8000) }}" min="1" max="65535" required></div>
        <div>
          <label>Protocol</label>
          <select name="protocol">
            <option value="http" @selected(old('protocol', 'http') === 'http')>HTTP</option>
            <option value="socks5" @selected(old('protocol') === 'socks5')>SOCKS5</option>
          </select>
        </div>
        <div><label>Username</label><input name="username" value="{{ old('username') }}" placeholder="Optional"></div>
        <div><label>Password</label><input type="password" name="password" placeholder="Optional"></div>
      </div>
      <label class="switch-row">
        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))>
        <span class="switch"></span>
        <div><strong>Enabled</strong><small>Include in the random STATIC proxy pool</small></div>
      </label>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Add proxy</button>
      </div>
    </form>
  </div>
</div>

@endsection
