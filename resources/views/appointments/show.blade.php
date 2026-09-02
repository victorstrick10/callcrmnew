@extends('layouts.app')

@section('title', $appointment->contact->full_name)
@section('page_title', $appointment->contact->full_name)
@section('page_subtitle', $appointment->event_name.' · '.($appointment->start_time ? $appointment->start_time->format('d.m.Y H:i') : 'No date'))

@section('content')
<div class="hero-card">
  <div class="client-hero">
    <div class="client-avatar">{{ mb_substr($appointment->contact->first_name, 0, 1) }}{{ mb_substr($appointment->contact->last_name, 0, 1) }}</div>
    <div>
      <h2>{{ $appointment->contact->full_name }}</h2>
      <p>{{ $appointment->contact->email }}@if($appointment->contact->company) · {{ $appointment->contact->company }}@endif</p>
      <div class="chip-row">
        <span class="chip">{{ $appointment->invitee_timezone ?: 'No timezone' }}</span>
        <span class="chip">{{ $appointment->status }}</span>
      </div>
    </div>
  </div>
  <div class="action-stack">
    <form method="post" action="{{ route('appointments.profiles', [$appointment, 'both']) }}">
      @csrf
      <button class="btn btn-primary" type="submit">Create missing profiles</button>
    </form>
    <div class="inline-actions">
      <form method="post" action="{{ route('appointments.profiles', [$appointment, 'geo']) }}">@csrf<button class="btn btn-secondary" type="submit">Create GEO</button></form>
      <form method="post" action="{{ route('appointments.profiles', [$appointment, 'static']) }}">@csrf<button class="btn btn-secondary" type="submit">Create STATIC</button></form>
    </div>
  </div>
</div>

<div class="panel proxy-client-panel">
  <div class="panel-head">
    <div>
      <h2>Client Multilogin Proxy</h2>
      <p>Generate, inspect, then reuse this proxy for the GEO profile. Naming: <code>{n} Name City,Region,Country</code> / <code>{n} Name Static</code>.</p>
    </div>
    <form method="post" action="{{ route('appointments.proxy.get', $appointment) }}" class="proxy-controls">
      @csrf
      <select name="candidate_count">
        <option value="3">3 proxies</option>
        <option value="5" selected>5 proxies</option>
        <option value="10">10 proxies</option>
      </select>
      <select name="selection_mode">
        <option value="auto">Auto-select best ISP</option>
        <option value="manual">Show list for manual selection</option>
      </select>
      <button class="btn btn-primary" type="submit">Get Proxy List</button>
    </form>
  </div>

  <div class="proxy-flow">
    <span>IPinfo location</span><b>→</b>
    <span>{{ $appointment->city ?: 'No city' }}, {{ $appointment->region ?: 'No region' }}, {{ $appointment->country_code ?: $appointment->country ?: 'No country' }}</span><b>→</b>
    <span>{{ $appointment->proxy_requested_city ?: 'pending' }} / {{ $appointment->proxy_requested_region ?: 'pending' }}</span><b>→</b>
    <span>Multilogin proxy</span>
  </div>

  @if ($appointment->proxy_status === 'ready')
  <div class="proxy-ready-grid">
    <div><small>Status</small><strong>Ready</strong></div>
    <div><small>Match</small><strong>{{ \Illuminate\Support\Str::title(str_replace('_', ' ', $appointment->proxy_match_level ?? '')) }}</strong></div>
    <div><small>Actual city</small><strong>{{ $appointment->proxy_actual_city ?: $appointment->proxy_city ?: '—' }}</strong></div>
    <div><small>Actual region</small><strong>{{ $appointment->proxy_actual_region ?: $appointment->proxy_region ?: '—' }}</strong></div>
    <div><small>Proxy ISP</small><strong>{{ $appointment->proxy_isp ?: '—' }}</strong></div>
    <div><small>Proxy ASN</small><strong>{{ $appointment->proxy_asn ?: '—' }}</strong></div>
    <div><small>Exit IP</small><strong>{{ $appointment->proxy_exit_ip ?: '—' }}</strong></div>
    <div><small>Protocol</small><strong>{{ strtoupper($appointment->proxy_protocol ?? '') }}</strong></div>
    <div><small>Proxy</small><strong>{{ $appointment->proxy_host }}:{{ $appointment->proxy_port }}</strong></div>
  </div>
  <div class="proxy-safe-note">Create GEO will reuse this exact saved proxy.</div>
  @elseif ($appointment->proxy_status === 'failed')
  <div class="error-box">{{ $appointment->proxy_last_error }}</div>
  @else
  <div class="notice">Click <b>Get Proxy</b> before creating the GEO profile.</div>
  @endif

  @if (!empty($proxyCandidates))
  <div class="candidate-list">
    <h3>Available ISP candidates for this client</h3>
    <div class="candidate-table">
      @foreach ($proxyCandidates as $candidate)
      <div class="candidate-row {{ ($appointment->proxy_username ?? '') === ($candidate['username'] ?? null) ? 'selected' : '' }}">
        <div>
          <strong>{{ $candidate['isp'] ?? $candidate['org'] ?? 'Unknown ISP' }}</strong>
          <small>{{ $candidate['asn'] ?? 'No ASN' }} · {{ $candidate['exit_ip'] ?? 'No exit IP' }}</small>
        </div>
        <div>
          <strong>{{ $candidate['city'] ?? 'Unknown city' }}, {{ $candidate['region'] ?? 'Unknown region' }}</strong>
          <small>
            ISP score {{ $candidate['isp_score'] ?? 0 }}%
            · City {{ !empty($candidate['city_match']) ? 'match' : 'different' }}
            · Region {{ !empty($candidate['region_match']) ? 'match' : 'different' }}
          </small>
        </div>
        <form method="post" action="{{ route('appointments.proxy.select', [$appointment, $candidate['id'] ?? $loop->index]) }}">
          @csrf
          <button class="btn btn-secondary" type="submit">Select ISP</button>
        </form>
      </div>
      @endforeach
    </div>
  </div>
  @endif
</div>

<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><div><h2>Location intelligence</h2><p>Used to choose an authorized workspace region</p></div></div>
    <div class="detail-grid">
      <div><span>IP address</span><strong>{{ $appointment->ip_address ?: 'Not captured' }}</strong></div>
      <div><span>City</span><strong>{{ $appointment->city ?: 'Unknown' }}</strong></div>
      <div><span>Region</span><strong>{{ $appointment->region ?: 'Unknown' }}</strong></div>
      <div><span>Country</span><strong>{{ $appointment->country ?: $appointment->country_code ?: 'Unknown' }}</strong></div>
      <div><span>Timezone</span><strong>{{ $appointment->timezone ?: $appointment->invitee_timezone ?: 'Unknown' }}</strong></div>
      <div><span>Client ISP</span><strong>{{ $appointment->client_isp ?: $appointment->client_org ?: 'Unknown' }}</strong></div>
      <div><span>Client ASN</span><strong>{{ $appointment->client_asn ?: 'Unknown' }}</strong></div>
      <div><span>Coordinates</span><strong>{{ $appointment->latitude ?: '—' }}, {{ $appointment->longitude ?: '—' }}</strong></div>
    </div>
    <form method="post" action="{{ route('appointments.enrich', $appointment) }}">
      @csrf
      <button class="btn btn-secondary full" type="submit">Refresh with IPinfo</button>
    </form>
  </div>

  <div class="panel">
    <div class="panel-head"><div><h2>Appointment details</h2><p>Calendly booking record</p></div></div>
    <div class="detail-grid">
      <div><span>Start</span><strong>{{ $appointment->start_time ? $appointment->start_time->format('d.m.Y H:i') : 'Not set' }}</strong></div>
      <div><span>End</span><strong>{{ $appointment->end_time ? $appointment->end_time->format('d.m.Y H:i') : 'Not set' }}</strong></div>
      <div><span>Event URI</span><strong class="truncate">{{ $appointment->calendly_event_uri ?: 'Local demo' }}</strong></div>
      <div><span>User agent</span><strong class="truncate">{{ $appointment->user_agent ?: 'Not captured' }}</strong></div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div><h2>Browser workspaces</h2><p>Reserved numbers and Multilogin results</p></div></div>
  <div class="profile-grid">
    @forelse ($appointment->profiles->sortBy('number') as $p)
    <div class="profile-card">
      <div class="profile-number">{{ $formatNumber($p->number) }}</div>
      <div class="profile-main">
        <div class="profile-title"><h3>{{ $p->profile_name }}</h3><span class="badge badge-{{ $p->status }}">{{ $p->status }}</span></div>
        <p>{{ strtoupper($p->profile_role) }} workspace</p>
        <div class="meta-line"><span>Multilogin ID</span><code>{{ $p->multilogin_profile_id ?: 'Pending' }}</code></div>
        @if ($p->error_message)<div class="error-box">{{ $p->error_message }}</div>@endif
        @if ($p->status === 'failed')
        <form method="post" action="{{ route('browser-profiles.retry', $p) }}">@csrf<button class="btn btn-danger" type="submit">Retry profile</button></form>
        @endif
      </div>
    </div>
    @empty
    <div class="empty-card">No profiles created yet. Use the buttons above.</div>
    @endforelse
  </div>
</div>
@endsection
