@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Command Center')
@section('page_subtitle', 'Monitor bookings, profiles, and integration health')

@section('content')
<div class="stat-grid">
  <div class="stat-card"><span>Total bookings</span><strong>{{ $stats['appointments'] }}</strong><small>All CRM appointments</small></div>
  <div class="stat-card"><span>Scheduled calls</span><strong>{{ $stats['scheduled'] }}</strong><small>Waiting for action</small></div>
  <div class="stat-card"><span>Profiles created</span><strong>{{ $stats['profiles'] }}</strong><small>Multilogin workspaces</small></div>
  <div class="stat-card danger"><span>Failed actions</span><strong>{{ $stats['failed'] }}</strong><small>Require review</small></div>
</div>

<div class="grid-2">
  <div class="panel">
    <div class="panel-head"><div><h2>Upcoming appointments</h2><p>Open a client to create profiles</p></div><a class="text-link" href="{{ route('appointments.index') }}">View all</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Client</th><th>Call</th><th>Location</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($upcoming as $a)
          <tr class="click-row" data-href="{{ route('appointments.show', $a) }}">
            <td><strong>{{ $a->contact->full_name }}</strong><small>{{ $a->contact->email }}</small></td>
            <td>{{ $a->start_time ? $a->start_time->format('d.m.Y H:i') : 'Not set' }}</td>
            <td>{{ $a->city ?: 'Unknown' }}@if($a->country), {{ $a->country }}@endif</td>
            <td><span class="badge badge-{{ $a->status }}">{{ $a->status }}</span></td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No appointments yet.</td></tr>
        @endforelse
        </tbody>
      </table>
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
</div>
@endsection
