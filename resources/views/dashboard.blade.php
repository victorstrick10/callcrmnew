@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Command Center')
@section('page_subtitle', 'Monitor bookings, geo enrichment, profiles, and integration health')

@section('content')
@php
  $sys = $systemStatus;
  $stateLabel = ['up' => 'Online', 'down' => 'Error', 'unknown' => 'Untested', 'missing' => 'Not set'];
@endphp

<div class="panel status-panel">
  <div class="panel-head">
    <div><h2>System status</h2><p>Live integration health &amp; automatic pipeline</p></div>
    <a class="text-link" href="{{ route('settings.index', ['tab' => 'sync']) }}">Manage integrations →</a>
  </div>

  <div class="status-strip">
    <div class="status-pill state-{{ $sys['ipinfo']['state'] }}">
      <span class="dot"></span>
      <div>
        <strong>IPinfo</strong>
        <small>{{ $stateLabel[$sys['ipinfo']['state']] ?? 'Unknown' }} · geo enrichment</small>
      </div>
    </div>
    <div class="status-pill state-{{ $sys['multilogin']['state'] }}">
      <span class="dot"></span>
      <div>
        <strong>Multilogin</strong>
        <small>{{ $stateLabel[$sys['multilogin']['state']] ?? 'Unknown' }} · profile creation</small>
      </div>
    </div>
    <div class="status-pill state-{{ $sys['calendly']['configured'] ? 'up' : 'missing' }}">
      <span class="dot"></span>
      <div>
        <strong>Calendly</strong>
        <small>{{ $sys['calendly']['companies'] }}/{{ $sys['calendly']['companies_total'] }} companies connected</small>
      </div>
    </div>
  </div>

  <div class="sync-flow">
    <span class="sync-step"><b>1</b> Calendly call arrives</span>
    <i>→</i>
    <span class="sync-step"><b>2</b> IPinfo reads lead geo from IP</span>
    <i>→</i>
    <span class="sync-step"><b>3</b> Build Multilogin browser profile</span>
    <span class="sync-meta">Auto-sync {{ $sys['auto_sync'] }} · last run {{ $sys['last_sync_human'] }}</span>
  </div>
</div>

<div class="stat-grid wrap compact">
  <div class="stat-card"><span>Total bookings</span><strong>{{ $stats['appointments'] }}</strong><small>All CRM appointments</small></div>
  <div class="stat-card"><span>Confirmed calls</span><strong>{{ $stats['scheduled'] }}</strong><small>Scheduled &amp; active</small></div>
  <div class="stat-card"><span>Calls today</span><strong>{{ $stats['calls_today'] }}</strong><small>All companies</small></div>
  <div class="stat-card"><span>Calls tomorrow</span><strong>{{ $stats['calls_tomorrow'] }}</strong><small>All companies</small></div>
  <div class="stat-card accent"><span>Pending today</span><strong>{{ $stats['pending_profiles'] }}</strong><small>Need GEO / STATIC</small></div>
  <div class="stat-card"><span>Profiles created</span><strong>{{ $stats['profiles'] }}</strong><small>Multilogin</small></div>
  <div class="stat-card danger"><span>Failed</span><strong>{{ $stats['failed'] }}</strong><small>Require review</small></div>
</div>

<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><div><h2>Pending browser profiles</h2><p>Today · {{ \Illuminate\Support\Carbon::now(config('app.timezone'))->format('D d.m.Y') }} · both companies</p></div><a class="text-link" href="{{ route('clients.index', ['schedule' => 'today']) }}">Today’s clients</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Client</th><th>Call</th><th>Location</th><th>Missing</th><th></th></tr></thead>
        <tbody>
        @forelse ($pending as $a)
          @php
            $roles = $a->profiles->whereIn('status', ['reserved','created'])->pluck('profile_role')->unique();
          @endphp
          <tr class="click-row" data-href="{{ route('appointments.show', $a) }}">
            <td><strong>{{ $a->contact->full_name }}</strong><small>{{ $a->company?->name }}</small></td>
            <td>{{ $a->localStart() ? $a->localStart()->format('d.m.Y H:i') : 'Not set' }}</td>
            <td>{{ \App\Support\CountryFlag::emoji($a->country_code) }} {{ $a->city ?: 'Unknown' }}@if($a->region), {{ $a->region }}@endif</td>
            <td>
              @unless($roles->contains('geo'))<span class="badge badge-reserved">GEO</span>@endunless
              @unless($roles->contains('static'))<span class="badge badge-reserved">STATIC</span>@endunless
            </td>
            <td><span class="mini-btn">Build →</span></td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty">All confirmed calls have profiles. 🎉</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><div><h2>Upcoming calls</h2><p>Tomorrow · {{ \Illuminate\Support\Carbon::now(config('app.timezone'))->addDay()->format('D d.m.Y') }} · both companies</p></div><a class="text-link" href="{{ route('clients.index', ['schedule' => 'tomorrow']) }}">Tomorrow’s clients</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Client</th><th>Call</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($upcoming as $a)
          <tr class="click-row" data-href="{{ route('appointments.show', $a) }}">
            <td><strong>{{ $a->contact->full_name }}</strong><small>{{ $a->contact->email }}</small></td>
            <td>{{ $a->localStart() ? $a->localStart()->format('d.m.Y H:i') : 'Not set' }}</td>
            <td>{{ \App\Support\CountryFlag::emoji($a->country_code) }} {{ $a->city ?: 'Unknown' }}@if($a->country), {{ $a->country }}@endif</td>
            <td><span class="badge badge-{{ $a->status }}">{{ $a->status }}</span></td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No calls scheduled for tomorrow.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div><h2>Recent activity</h2><p>System and API actions</p></div><a class="text-link" href="{{ route('logs.index') }}">Full log</a></div>
  <div class="timeline">
    @forelse ($recentLogs as $log)
    <div class="timeline-item">
      <div class="timeline-dot"></div>
      <div><strong>{{ $log->action }}</strong><p>{{ $log->details }}</p><small>{{ $log->created_at?->format('d.m.Y H:i') }}</small></div>
    </div>
    @empty
    <div class="empty">No audit events yet.</div>
    @endforelse
  </div>
</div>
@endsection
