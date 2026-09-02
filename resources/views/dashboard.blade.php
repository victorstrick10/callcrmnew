@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Command Center')
@section('page_subtitle', 'Monitor bookings, geo enrichment, profiles, and integration health')

@section('content')
@php
  $sys = $systemStatus;
  $stateLabel = ['up' => 'Online', 'down' => 'Error', 'unknown' => 'Untested', 'missing' => 'Not set'];
  $dispTz = config('app.display_timezone') ?: config('app.timezone');
  $seenLabel = ($newCallsSince ?? null) ? $newCallsSince->copy()->setTimezone($dispTz)->format('D d.m.Y H:i') : null;
@endphp

<div class="notify-row {{ ($newCalls ?? 0) > 0 ? 'has-new' : '' }}">
  <div class="notify-icon">🔔</div>
  <div class="notify-body">
    @if (($newCalls ?? 0) > 0)
      <strong>{{ $newCalls }} new call{{ $newCalls === 1 ? '' : 's' }} scheduled</strong>
      <small>since your last visit{{ $seenLabel ? ' · '.$seenLabel.' (GMT+1)' : '' }}</small>
    @elseif (!empty($newCallsFirstVisit))
      <strong>Now tracking new calls</strong>
      <small>New bookings since your last visit will show up here next time you open the CRM.</small>
    @else
      <strong>No new calls since your last visit</strong>
      <small>{{ $seenLabel ? 'Last checked '.$seenLabel.' (GMT+1)' : "You're all caught up." }}</small>
    @endif
  </div>
  @if (($newCalls ?? 0) > 0)
    <a class="btn btn-primary" href="{{ route('clients.index', ['schedule' => 'all', 'sort' => 'created', 'dir' => 'desc']) }}">View new leads →</a>
  @endif
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Calls this week</h2><p>Scheduled calls per day · next 7 days · {{ $weekCalls['total'] }} total · GMT+1</p></div>
    <a class="text-link" href="{{ route('clients.index', ['schedule' => 'week']) }}">Open week →</a>
  </div>
  <div class="week-strip">
    @foreach ($weekCalls['days'] as $d)
      <a class="week-day {{ $d['is_today'] ? 'is-today' : '' }} {{ $d['count'] > 0 ? 'has-calls' : '' }}"
         href="{{ route('clients.index', ['from' => $d['date'], 'to' => $d['date']]) }}">
        <span class="week-day-name">{{ $d['weekday'] }}</span>
        <strong class="week-day-count">{{ $d['count'] }}</strong>
        <span class="week-day-date">{{ $d['label'] }}</span>
      </a>
    @endforeach
  </div>
</div>

<div class="panel status-panel">
  <div class="panel-head">
    <div><h2>System status</h2><p>Live integration health &amp; automatic pipeline</p></div>
    <div class="form-actions" style="margin:0">
      <form method="post" action="{{ route('numbers.sync-all') }}" class="js-progress" style="margin:0">@csrf<button class="btn btn-secondary" type="submit" title="Sync Multilogin profile numbers for all companies">↻ Sync numbers (all)</button></form>
      <a class="btn btn-secondary" href="{{ route('settings.index', ['tab' => 'sync']) }}">Integrations</a>
    </div>
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

<div class="panel">
  <div class="panel-head">
    <div><h2>Company health</h2><p>Live status per company — green = live, red = down/expired</p></div>
    <form method="post" action="{{ route('system.check-all') }}" class="js-progress" style="margin:0">@csrf<button class="btn btn-primary" type="submit">🔌 Run all system checks</button></form>
  </div>
  <div class="card-grid">
    @php $stLabel = ['up' => 'Live', 'down' => 'Down', 'unknown' => 'Not tested']; @endphp
    @forelse ($companies as $co)
      <div class="company-card">
        <div class="company-card-head">
          <div class="client-avatar">{{ mb_strtoupper(mb_substr($co->name, 0, 1)) }}</div>
          <div><h3>{{ $co->name }}</h3><p>{{ $co->slug }}</p></div>
        </div>
        <div class="svc-status-row">
          @foreach ([['lead', 'Lead API'], ['calendly', 'Calendly'], ['multilogin', 'Multilogin']] as [$svc, $label])
            @php $st = $co->serviceState($svc); @endphp
            <span class="svc-status state-{{ $st }}" title="{{ $co->serviceMessage($svc) ?: 'Not tested' }}"><span class="dot"></span>{{ $label }}: {{ $stLabel[$st] ?? '—' }}</span>
          @endforeach
        </div>
        <a class="text-link" href="{{ route('companies.edit', $co) }}">Open company →</a>
      </div>
    @empty
      <div class="empty-card">No companies yet.</div>
    @endforelse
  </div>
</div>

<div class="grid-2">
  @foreach ([['Today', $todayCalls], ['Tomorrow', $tomorrowCalls]] as [$label, $data])
    @php $copyText = $label." Calls\n".$data['count']." Calls\n".implode("\n", $data['times']); @endphp
    <div class="panel">
      <div class="panel-head">
        <div><h2>{{ $label }} Calls</h2><p>{{ $data['date'] }} · {{ $data['count'] }} calls · GMT+1</p></div>
        <button type="button" class="btn btn-secondary copy-btn" data-copy="{{ $copyText }}">⧉ Copy</button>
      </div>
      <div class="times-list">
        @forelse ($data['times'] as $t)
          <span class="time-chip">{{ $t }}</span>
        @empty
          <span class="muted">No calls scheduled.</span>
        @endforelse
      </div>
    </div>
  @endforeach
</div>

<div class="stat-grid wrap compact">
  <a class="stat-card" href="{{ route('clients.index') }}"><span>Total bookings</span><strong>{{ $stats['appointments'] }}</strong><small>All CRM appointments</small></a>
  <a class="stat-card" href="{{ route('clients.index', ['has_call' => 'upcoming']) }}"><span>Confirmed calls</span><strong>{{ $stats['scheduled'] }}</strong><small>Scheduled &amp; active</small></a>
  <a class="stat-card" href="{{ route('clients.index', ['schedule' => 'today']) }}"><span>Calls today</span><strong>{{ $stats['calls_today'] }}</strong><small>All companies</small></a>
  <a class="stat-card" href="{{ route('clients.index', ['schedule' => 'tomorrow']) }}"><span>Calls tomorrow</span><strong>{{ $stats['calls_tomorrow'] }}</strong><small>All companies</small></a>
  <a class="stat-card accent" href="{{ route('clients.index', ['schedule' => 'today']) }}"><span>Pending today</span><strong>{{ $stats['pending_profiles'] }}</strong><small>Need GEO / STATIC</small></a>
  <a class="stat-card" href="{{ route('clients.index') }}"><span>Profiles created</span><strong>{{ $stats['profiles'] }}</strong><small>Multilogin</small></a>
  <a class="stat-card danger" href="{{ route('clients.index') }}"><span>Failed</span><strong>{{ $stats['failed'] }}</strong><small>Require review</small></a>
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

@include('partials.progress-modal')
@endsection
