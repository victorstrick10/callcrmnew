@extends('layouts.app')

@section('title', $appointment->contact->full_name)
@section('page_title', $appointment->contact->full_name)
@section('page_subtitle', $appointment->event_name.' · '.($appointment->start_time ? $appointment->localStart()->format('d.m.Y H:i').' '.$appointment->inviteeTzAbbr() : 'No date'))

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
    <button class="btn btn-primary" type="button" id="openProfileBuilder">🧩 Build browser profile</button>
    <span class="muted" style="text-align:center;font-size:12px">Review geo, proxy &amp; names before creating</span>
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

  <details class="proxy-edit" style="margin:0 0 12px">
    <summary title="Edit the proxy target like the Multilogin GUI (auto-match stays the default)">✎ Edit target like Multilogin</summary>
    <form method="post" action="{{ route('appointments.proxy.get', $appointment) }}" class="proxy-edit-form" style="max-width:420px">
      @csrf
      <input type="hidden" name="ov_edit" value="1">
      <label>Connection type
        <select name="ov_connection">
          <option value="mobile" selected>Mobile</option>
          <option value="residential">Residential</option>
          <option value="isp">ISP</option>
        </select>
      </label>
      <label>Country <small>(2-letter ISO)</small>
        <input type="text" name="ov_country" maxlength="2" value="{{ $appointment->country_code ?: $appointment->country }}" placeholder="AE">
      </label>
      <div class="pe-row">
        <label>Region <small>(optional)</small><input type="text" name="ov_region" value="{{ $appointment->region }}" placeholder="Dubai"></label>
        <label>City <small>(optional)</small><input type="text" name="ov_city" value="{{ $appointment->city }}" placeholder="Dubai"></label>
      </div>
      <label>ISP <small>(optional)</small>
        <input type="text" name="ov_isp" value="{{ $appointment->client_isp }}" placeholder="Etisalat">
      </label>
      <label>Protocol
        <select name="ov_protocol"><option value="http" selected>HTTP</option><option value="socks5">SOCKS5</option></select>
      </label>
      <button class="btn btn-primary" type="submit">Build with these →</button>
    </form>
  </details>

  <div class="proxy-flow">
    <span>ip-api location</span><b>→</b>
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
      <div><span>Country</span><strong>{{ \App\Support\CountryFlag::emoji($appointment->country_code) }} {{ $appointment->country ?: $appointment->country_code ?: 'Unknown' }}</strong></div>
      <div><span>Timezone</span><strong>{{ $appointment->timezone ?: $appointment->invitee_timezone ?: 'Unknown' }}</strong></div>
      <div><span>Client ISP</span><strong>{{ $appointment->client_isp ?: $appointment->client_org ?: 'Unknown' }}</strong></div>
      <div><span>Client ASN</span><strong>{{ $appointment->client_asn ?: 'Unknown' }}</strong></div>
      <div><span>Coordinates</span><strong>{{ $appointment->latitude ?: '—' }}, {{ $appointment->longitude ?: '—' }}</strong></div>
    </div>
    <form method="post" action="{{ route('appointments.enrich', $appointment) }}">
      @csrf
      <button class="btn btn-secondary full" type="submit">Refresh with ip-api</button>
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
    <div class="empty-card">No profiles created yet. Use <strong>Build browser profile</strong> above.</div>
    @endforelse
  </div>
</div>

{{-- ============ Advanced Profile Builder ============ --}}
<div class="modal-backdrop" id="profileModal" hidden
     data-ready="{{ $builder['multilogin_ready'] ? '1' : '0' }}"
     data-url-geo="{{ route('appointments.profiles', [$appointment, 'geo']) }}"
     data-url-static="{{ route('appointments.profiles', [$appointment, 'static']) }}"
     data-url-both="{{ route('appointments.profiles', [$appointment, 'both']) }}">
  <div class="modal-card builder-card" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
    <div class="modal-head">
      <div>
        <h2 id="profileModalTitle">Build browser profile</h2>
        <p>Review geo, proxy and profile names before anything is created on Multilogin.</p>
      </div>
      <button type="button" class="btn btn-secondary" id="profileModalClose">Close</button>
    </div>

    <div class="modal-body">
      {{-- Readiness --}}
      <div class="builder-checks">
        <div class="check {{ ($appointment->country_code || $appointment->country) ? 'ok' : 'warn' }}">
          <span class="dot"></span> Location {{ ($appointment->country_code || $appointment->country) ? 'known' : 'missing — run geo lookup' }}
        </div>
        <div class="check {{ $builder['proxy_ready'] ? 'ok' : 'warn' }}">
          <span class="dot"></span> Proxy {{ $builder['proxy_ready'] ? 'ready' : 'not fetched (GEO needs it)' }}
        </div>
        <div class="check {{ $builder['multilogin_ready'] ? 'ok' : 'down' }}">
          <span class="dot"></span> Multilogin {{ $builder['multilogin_ready'] ? 'connected' : 'no token' }}
        </div>
        <div class="check {{ $builder['static_proxy_count'] > 0 ? 'ok' : 'warn' }}">
          <span class="dot"></span> Static proxies: {{ $builder['static_proxy_count'] }}
        </div>
      </div>

      <div class="modal-section">
        <h3>Assigned number &amp; names</h3>
        <div class="builder-preview">
          <div class="preview-number">{{ $builder['preview_number_label'] }}</div>
          <div class="preview-names">
            <div><span>GEO profile</span><code>{{ $builder['geo_name'] ?? 'Location required' }}</code></div>
            <div><span>STATIC profile</span><code>{{ $builder['static_name'] ?? '—' }}</code></div>
          </div>
        </div>
      </div>

      <div class="modal-section">
        <h3>Location intelligence</h3>
        <div class="detail-list">
          <div class="detail-row"><span>City</span><strong>{{ $appointment->city ?: 'Unknown' }}</strong></div>
          <div class="detail-row"><span>Region</span><strong>{{ $appointment->region ?: 'Unknown' }}</strong></div>
          <div class="detail-row"><span>Country</span><strong>{{ \App\Support\CountryFlag::emoji($appointment->country_code) }} {{ $appointment->country ?: $appointment->country_code ?: 'Unknown' }}</strong></div>
          <div class="detail-row"><span>Client ISP</span><strong>{{ $appointment->client_isp ?: $appointment->client_org ?: 'Unknown' }}</strong></div>
        </div>
        <form method="post" action="{{ route('appointments.enrich', $appointment) }}" style="margin-top:12px">
          @csrf
          <button class="btn btn-secondary" type="submit">↻ Re-run geo</button>
        </form>
      </div>

      <div class="modal-section">
        <h3>Select profiles to create</h3>
        <div class="builder-roles">
          <label class="role-select {{ (!$builder['geo_eligible'] || $builder['geo_exists']) ? 'is-disabled' : '' }}">
            <input type="checkbox" class="role-check" value="geo"
              @if($builder['geo_eligible'] && !$builder['geo_exists']) checked @else disabled @endif>
            <div>
              <strong>Create Geo Profile</strong>
              <small>
                @if($builder['geo_exists']) Already created
                @elseif(!$builder['geo_eligible']) Needs a known country (run geo lookup)
                @else Matched Multilogin proxy for the lead's location @endif
              </small>
            </div>
          </label>
          <label class="role-select {{ $builder['static_exists'] ? 'is-disabled' : '' }}">
            <input type="checkbox" class="role-check" value="static"
              @if(!$builder['static_exists']) checked @else disabled @endif>
            <div>
              <strong>Create Static Profile</strong>
              <small>@if($builder['static_exists']) Already created @else Uses a location-matched mobile proxy (ProxyCheap / MobileHop) @endif</small>
            </div>
          </label>
        </div>
        @unless($builder['multilogin_ready'])
        <div class="error-box">No Multilogin token. Add one on the company or in Integrations → Multilogin.</div>
        @endunless
      </div>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" id="profileModalCancel">Cancel</button>
      <form method="post" id="profileCreateForm" data-fallback="{{ route('appointments.profiles', [$appointment, 'both']) }}">
        @csrf
        <button type="submit" class="btn btn-primary" id="profileConfirm" @unless($builder['multilogin_ready']) disabled @endunless>Create Geo/Static Profile</button>
      </form>
    </div>
  </div>
</div>

@include('partials.progress-modal')
@endsection
