@extends('layouts.app')

@section('title', 'Clients')
@section('page_title', 'Clients')
@section('page_subtitle', 'Leads with geo enrichment, scheduled calls, and one-click profile creation')

@section('content')
@php
  $baseQuery = array_filter([
    'company' => $companySlug ?? '',
    'q' => $search ?? '',
    'has_call' => $hasCall ?? '',
    'schedule' => $schedulePreset ?? '',
    'from' => $scheduleFrom ?? '',
    'to' => $scheduleTo ?? '',
    'sort' => $sort ?? '',
    'dir' => $dir ?? '',
  ], fn ($v) => $v !== '' && $v !== null);

  $sortLink = function (string $key, string $label) use ($baseQuery, $sort, $dir) {
    $nextDir = (($sort ?? '') === $key && ($dir ?? '') === 'asc') ? 'desc' : 'asc';
    $arrow = ($sort ?? '') === $key ? (($dir ?? '') === 'asc' ? '▲' : '▼') : '↕';
    $url = route('clients.index', array_merge($baseQuery, ['sort' => $key, 'dir' => $nextDir]));
    $active = ($sort ?? '') === $key ? ' active' : '';
    return '<a class="sort-th'.$active.'" href="'.$url.'">'.e($label).' <i>'.$arrow.'</i></a>';
  };

  $chip = fn ($preset) => ($schedulePreset ?? '') === $preset ? 'chip-active' : '';
@endphp

<form method="get" class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <input type="hidden" name="sort" value="{{ $sort ?? '' }}">
  <input type="hidden" name="dir" value="{{ $dir ?? '' }}">
  <div class="quick-chips">
    <button class="chip-btn {{ $chip('today') }}" type="submit" name="schedule" value="today">Today’s calls</button>
    <button class="chip-btn {{ $chip('tomorrow') }}" type="submit" name="schedule" value="tomorrow">Tomorrow’s calls</button>
    <button class="chip-btn {{ $chip('week') }}" type="submit" name="schedule" value="week">Next 7 days</button>
    <button class="chip-btn {{ $chip('all') }}" type="submit" name="schedule" value="all">All dates</button>
  </div>

  <div class="form-grid five" style="align-items:end;margin-top:14px">
    <div>
      <label>Company</label>
      <select name="company">
        <option value="">All companies</option>
        @foreach ($companies as $company)
          <option value="{{ $company->slug }}" @selected(($companySlug ?? '') === $company->slug)>{{ $company->name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label>Calls</label>
      <select name="has_call">
        <option value="" @selected(($hasCall ?? '') === '')>All</option>
        <option value="upcoming" @selected(($hasCall ?? '') === 'upcoming')>Upcoming call</option>
        <option value="any" @selected(($hasCall ?? '') === 'any')>Has any call</option>
        <option value="none" @selected(($hasCall ?? '') === 'none')>No calls</option>
      </select>
    </div>
    <div>
      <label>From</label>
      <input type="date" name="from" value="{{ $scheduleFrom ?? '' }}">
    </div>
    <div>
      <label>To</label>
      <input type="date" name="to" value="{{ $scheduleTo ?? '' }}">
    </div>
    <div>
      <label>Search leads</label>
      <div class="search-group">
        <input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Name, email, referrer…">
        <button class="btn btn-primary" type="submit">🔍 Search</button>
      </div>
    </div>
  </div>
  <div class="form-actions" style="margin-top:14px">
    <button class="btn btn-primary" type="submit">Apply filters</button>
    <a class="btn btn-secondary" href="{{ route('clients.index') }}">Reset</a>
    <span class="muted" style="margin-left:auto">Schedule filters use Calendly <strong>call start time</strong>.</span>
  </div>
</form>

<div class="panel">
  <div class="panel-head">
    <div>
      <h2>Leads</h2>
      <p>{{ $contacts->count() }} result(s) · click a row for full lead data</p>
    </div>
    <div class="form-actions" style="margin:0">
      <form method="post" action="{{ route('numbers.sync-all') }}" class="js-progress" style="display:inline;margin:0">@csrf<button class="btn btn-secondary" type="submit" title="Sync Multilogin profile numbers for all companies">↻ Sync numbers (all)</button></form>
      <form method="post" action="{{ route('clients.enrich-geo') }}" style="display:inline;margin:0">@csrf<button class="btn btn-secondary" type="submit" title="Run IPinfo geolocation for leads with an IP">🌐 Run IPinfo (geo)</button></form>
      <a class="btn btn-secondary" href="{{ route('clients.export', $baseQuery) }}">⭳ Export CSV</a>
      <button class="btn btn-primary" type="submit" form="bulkProfilesForm">＋ Create profiles (selected only)</button>
    </div>
  </div>
  <p class="muted" style="padding:0 4px 12px;margin:0">Profiles are created <strong>only for leads you select</strong> (or the per-lead GEO/STATIC/Both buttons). There is no automatic mass-create. Existing profiles are skipped; GEO needs a known location.</p>

  <div class="table-wrap">
    <table class="clients-table">
      <thead>
        <tr>
          <th style="width:38px"><input type="checkbox" id="clientsSelectAll" title="Select all"></th>
          <th>{!! $sortLink('name', 'Lead') !!}</th>
          <th>{!! $sortLink('company', 'Company') !!}</th>
          <th>{!! $sortLink('call', 'Scheduled call') !!}</th>
          <th>{!! $sortLink('location', 'GEO (IPinfo)') !!}</th>
          <th>Our Proxy <small>(static)</small></th>
          <th>Multilogin Proxy <small>(geo)</small></th>
          <th>Profiles</th>
          <th>{!! $sortLink('calls', 'Calls') !!}</th>
          <th style="width:120px">Action</th>
        </tr>
      </thead>
      <tbody>
      @forelse ($contacts as $c)
        @php
          $payload = [
            'id' => $c->id,
            'full_name' => $c->full_name,
            'first_name' => $c->first_name,
            'last_name' => $c->last_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'company_label' => $c->company,
            'tenant' => $c->ownerCompany?->name,
            'referrer' => $c->referrer,
            'source' => $c->source_label,
            'lead_user_agent' => $c->lead_user_agent,
            'lead_ip' => $c->lead_ip,
            'geo_location' => $c->geo_location ?? '',
            'geo_provider' => $c->geo_provider ?? '',
            'geo_profile_name' => $c->geo_profile_name ?? '',
            'has_geo_profile' => (bool) ($c->has_geo_profile ?? false),
            'has_static_profile' => (bool) ($c->has_static_profile ?? false),
            'lead_synced_at' => optional($c->lead_synced_at)->format('d.m.Y H:i'),
            'created_at' => optional($c->created_at)->format('d.m.Y H:i'),
            'updated_at' => optional($c->updated_at)->format('d.m.Y H:i'),
            'calls_count' => $c->calls_count,
            'next_call_at' => optional($c->next_call_at)->format('d.m.Y H:i'),
            'next_call_status' => $c->next_call_status,
            'appointments' => $c->appointments->map(fn ($a) => [
              'id' => $a->id,
              'event_name' => $a->event_name,
              'start_time' => optional($a->localStart())->format('d.m.Y H:i'),
              'end_time' => optional($a->localEnd())->format('d.m.Y H:i'),
              'status' => $a->status,
              'timezone' => $a->invitee_timezone,
              'ip' => $a->ip_address,
              'country' => $a->country ?: $a->country_code,
              'country_code' => $a->country_code,
              'region' => $a->region,
              'city' => $a->city,
              'isp' => $a->client_isp ?: $a->client_org,
            ])->values()->all(),
            'lead_raw_json' => $c->lead_raw_json,
          ];
        @endphp
        <tr class="lead-row" tabindex="0" data-lead='@json($payload)'>
          <td>
            @if ($c->display_appointment_id)
              <input class="client-appointment-check" type="checkbox" value="{{ $c->display_appointment_id }}">
            @endif
          </td>
          <td class="col-lead">
            <strong>{{ $c->full_name }}</strong>
            <small>{{ $c->email }}</small>
            <span class="src-badge src-{{ $c->source }}">{{ $c->source_label }}</span>
          </td>
          <td class="col-b">{{ $c->ownerCompany?->name ?? '—' }}</td>
          <td class="col-b">
            @if ($c->next_call_at)
              <strong>{{ $c->next_call_at->format('d.m.Y H:i') }}</strong>
              <small>{{ $c->next_call_status }}</small>
            @else
              <span class="muted">Not set</span>
            @endif
          </td>
          <td class="col-geo">
            @if ($c->geo_country || $c->geo_region || $c->geo_city || $c->geo_provider)
              <div class="geo-lines">
                <span><b>Country</b>{{ \App\Support\CountryFlag::emoji($c->geo_country_code) }} {{ $c->geo_country ?: $c->geo_country_code ?: '—' }}</span>
                <span><b>Region</b>{{ $c->geo_region ?: '—' }}</span>
                <span><b>City</b>{{ $c->geo_city ?: '—' }}</span>
                <span><b>ISP</b>{{ $c->geo_provider ?: '—' }}</span>
              </div>
            @else
              <span class="muted">Not enriched</span>
            @endif
          </td>
          <td>
            @if ($c->our_proxy_ready)
              <span class="svc-status state-up"><span class="dot"></span>{{ ucfirst($c->our_proxy_provider ?: 'pool') }} · {{ str_replace('_', '+', $c->our_proxy_level) }}</span>
              <div class="geo-lines">
                <span><b>Country</b>{{ \App\Support\CountryFlag::emoji($c->our_proxy_country) }} {{ $c->our_proxy_country ?: '—' }}</span>
                <span><b>Region</b>{{ $c->our_proxy_region ?: '—' }}</span>
                <span><b>City</b>{{ $c->our_proxy_city ?: '—' }}</span>
                <span><b>ISP</b>{{ $c->our_proxy_isp ?: '—' }}</span>
              </div>
              @unless ($c->our_proxy_checked)<small class="muted">run “Check all live” to verify region/city</small>@endunless
            @else
              <span class="svc-status state-unknown"><span class="dot"></span>No match</span>
            @endif
          </td>
          <td>
            @if ($c->ml_proxy_ready)
              <span class="svc-status state-up"><span class="dot"></span>Ready</span>
              <div class="geo-lines">
                <span><b>Country</b>{{ \App\Support\CountryFlag::emoji($c->ml_proxy_country) }} {{ $c->ml_proxy_country ?: '—' }}</span>
                <span><b>Region</b>{{ $c->ml_proxy_region ?: '—' }}</span>
                <span><b>City</b>{{ $c->ml_proxy_city ?: '—' }}</span>
                <span><b>ISP</b>{{ $c->ml_proxy_isp ?: '—' }}</span>
              </div>
              <form method="post" action="{{ route('appointments.proxy.get', $c->display_appointment_id) }}" style="margin:6px 0 0">
                @csrf
                <button class="mini-btn ghost" type="submit" title="Rotate / get a new Multilogin proxy IP for this lead">↻ Refresh IP</button>
              </form>
            @elseif ($c->display_appointment_id)
              <form method="post" action="{{ route('appointments.proxy.get', $c->display_appointment_id) }}" style="margin:0">
                @csrf
                <button class="mini-btn" type="submit" title="Generate a Multilogin proxy matching country/region/city + ISP">Get proxy</button>
              </form>
            @else
              <span class="muted">—</span>
            @endif
          </td>
          <td class="col-profiles">
            <span class="role-chip {{ $c->has_geo_profile ? 'on' : '' }}">GEO</span>
            <span class="role-chip {{ $c->has_static_profile ? 'on' : '' }}">STATIC</span>
            <span class="role-chip {{ $c->has_static_mhop_profile ? 'on' : '' }}">STATIC-MH</span>
          </td>
          <td>{{ $c->calls_count }}</td>
          <td>
            @if ($c->display_appointment_id)
              <form method="post" action="{{ route('clients.create-missing-profiles') }}" class="inline-create js-progress">
                @csrf
                @foreach ($baseQuery as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <input type="hidden" name="appointment_ids[]" value="{{ $c->display_appointment_id }}">
                <div class="mini-btn-group" role="group" aria-label="Create profile for this lead">
                  <button class="mini-btn {{ $c->has_geo_profile ? 'done' : '' }}" name="role" value="geo" type="submit" title="Create GEO profile (mobile Multilogin proxy, matches region/city/ISP)">GEO</button>
                  <button class="mini-btn {{ $c->has_static_profile ? 'done' : '' }}" name="role" value="static" type="submit" title="Create STATIC profile (best matching pool proxy)">STATIC</button>
                  <button class="mini-btn {{ $c->has_static_mhop_profile ? 'done' : '' }}" name="role" value="static_mhop" type="submit" title="Create STATIC profile using a MobileHop proxy only (match country/region/city, else random MobileHop)">STATIC-MHop</button>
                  <button class="mini-btn strong" name="role" value="both" type="submit" title="Create both missing profiles">Both</button>
                </div>
              </form>
            @else
              <span class="muted">No call</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="10" class="empty">No clients match these filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- Standalone bulk form (kept outside the table so per-row forms don't nest) --}}
<form method="post" action="{{ route('clients.create-missing-profiles') }}" id="bulkProfilesForm" class="js-progress" hidden>
  @csrf
  @foreach ($baseQuery as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
  <div id="bulkProfilesIds"></div>
</form>

@include('partials.progress-modal')

<div class="modal-backdrop" id="leadModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="leadModalTitle">
    <div class="modal-head">
      <div>
        <h2 id="leadModalTitle">Lead details</h2>
        <p id="leadModalSub">Full lead + call history</p>
      </div>
      <button type="button" class="btn btn-secondary" id="leadModalClose">Close</button>
    </div>
    <div class="modal-body" id="leadModalBody"></div>
  </div>
</div>
@endsection
