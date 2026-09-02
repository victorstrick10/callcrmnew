@extends('layouts.app')

@section('title', 'Clients')
@section('page_title', 'Clients')
@section('page_subtitle', 'Leads with created date, scheduled call time, and full lead details')

@section('content')
@php
  $baseQuery = array_filter([
    'company' => $companySlug ?? '',
    'q' => $search ?? '',
    'has_call' => $hasCall ?? '',
    'schedule' => $schedulePreset ?? '',
    'from' => $scheduleFrom ?? '',
    'to' => $scheduleTo ?? '',
  ], fn ($v) => $v !== '' && $v !== null);
@endphp

<form method="get" class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <div class="form-actions" style="margin-bottom:14px">
    <a class="btn {{ ($schedulePreset ?? '') === 'today' ? 'btn-primary' : 'btn-secondary' }}"
       href="{{ route('clients.index', array_merge($baseQuery, ['schedule' => 'today'])) }}">Today’s calls</a>
    <a class="btn {{ ($schedulePreset ?? '') === 'tomorrow' ? 'btn-primary' : 'btn-secondary' }}"
       href="{{ route('clients.index', array_merge($baseQuery, ['schedule' => 'tomorrow'])) }}">Tomorrow’s calls</a>
    <a class="btn btn-secondary" href="{{ route('clients.index', array_filter(['company' => $companySlug ?? '', 'q' => $search ?? '', 'has_call' => $hasCall ?? ''], fn ($v) => $v !== '' && $v !== null)) }}">Clear schedule</a>
  </div>

  <div class="form-grid" style="align-items:end">
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
      <label>Scheduled from</label>
      <input type="date" name="from" value="{{ $scheduleFrom ?? '' }}">
    </div>
    <div>
      <label>Scheduled to</label>
      <input type="date" name="to" value="{{ $scheduleTo ?? '' }}">
    </div>
    <div>
      <label>Search</label>
      <input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Name, email, referrer…">
    </div>
    <div class="form-actions" style="margin:0">
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-secondary" href="{{ route('clients.index') }}">Reset</a>
    </div>
  </div>
  <p class="muted" style="margin:12px 0 0">Schedule filters use Calendly <strong>call start time</strong>, not lead created time.</p>
</form>

<form method="post" action="{{ route('clients.create-missing-profiles') }}" id="clientsBulkProfilesForm">
  @csrf
  <input type="hidden" name="company" value="{{ $companySlug ?? '' }}">
  <input type="hidden" name="q" value="{{ $search ?? '' }}">
  <input type="hidden" name="has_call" value="{{ $hasCall ?? '' }}">
  <input type="hidden" name="schedule" value="{{ $schedulePreset ?? '' }}">
  <input type="hidden" name="from" value="{{ $scheduleFrom ?? '' }}">
  <input type="hidden" name="to" value="{{ $scheduleTo ?? '' }}">

  <div class="panel">
    <div class="panel-head">
      <div>
        <h2>Leads</h2>
        <p>{{ $contacts->count() }} result(s) · click a row for full lead data</p>
      </div>
      <div class="form-actions" style="margin:0">
        <a class="btn btn-secondary" href="{{ route('clients.export', $baseQuery) }}">Export to Excel</a>
        <button class="btn btn-primary" type="submit">Create missing profiles</button>
      </div>
    </div>
    <p class="muted" style="padding:0 16px 12px;margin:0">If none selected, runs for all leads in the current filter. Creates only missing GEO/STATIC (safe to click again — existing profiles are skipped). GEO is skipped when location/IP is unavailable.</p>
    <div class="table-wrap">
      <table class="clients-table">
        <thead>
          <tr>
            <th style="width:42px"><input type="checkbox" id="clientsSelectAll" title="Select all"></th>
            <th>Lead</th>
            <th>Company</th>
            <th>Created</th>
            <th>Scheduled call</th>
            <th>Location</th>
            <th>GEO</th>
            <th>STATIC</th>
            <th>Calls</th>
            <th>Status</th>
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
              'lead_user_agent' => $c->lead_user_agent,
              'lead_ip' => $c->lead_ip,
              'lead_synced_at' => optional($c->lead_synced_at)->format('d.m.Y H:i'),
              'created_at' => optional($c->created_at)->format('d.m.Y H:i'),
              'updated_at' => optional($c->updated_at)->format('d.m.Y H:i'),
              'calls_count' => $c->calls_count,
              'next_call_at' => optional($c->next_call_at)->format('d.m.Y H:i'),
              'next_call_status' => $c->next_call_status,
              'has_geo_profile' => (bool) ($c->has_geo_profile ?? false),
              'has_static_profile' => (bool) ($c->has_static_profile ?? false),
              'geo_location' => $c->geo_location ?? '',
              'geo_profile_name' => $c->geo_profile_name ?? '',
              'appointments' => $c->appointments->map(fn ($a) => [
                'id' => $a->id,
                'event_name' => $a->event_name,
                'start_time' => optional($a->start_time)->format('d.m.Y H:i'),
                'end_time' => optional($a->end_time)->format('d.m.Y H:i'),
                'status' => $a->status,
                'timezone' => $a->invitee_timezone,
              ])->values()->all(),
              'lead_raw_json' => $c->lead_raw_json,
            ];
          @endphp
          <tr class="lead-row" tabindex="0" data-lead='@json($payload)'>
            <td>
              @if ($c->display_appointment_id)
                <input class="client-appointment-check" type="checkbox" name="appointment_ids[]" value="{{ $c->display_appointment_id }}">
              @endif
            </td>
            <td>
              <strong>{{ $c->full_name }}</strong>
              <small>{{ $c->email }}</small>
            </td>
            <td>{{ $c->ownerCompany?->name ?? '—' }}</td>
            <td>{{ optional($c->created_at)->format('d.m.Y H:i') ?? '—' }}</td>
            <td>
              @if ($c->next_call_at)
                <strong>{{ $c->next_call_at->format('d.m.Y H:i') }}</strong>
                <small>{{ $c->next_call_status }}</small>
              @else
                Not set
              @endif
            </td>
            <td>
              @if ($c->geo_location)
                <strong>{{ $c->geo_location }}</strong>
                @if ($c->geo_profile_name)
                  <small>{{ $c->geo_profile_name }}</small>
                @endif
              @else
                —
              @endif
            </td>
            <td>
              @if ($c->has_geo_profile)
                <span class="badge badge-scheduled">true</span>
              @else
                <span class="badge">false</span>
              @endif
            </td>
            <td>
              @if ($c->has_static_profile)
                <span class="badge badge-scheduled">true</span>
              @else
                <span class="badge">false</span>
              @endif
            </td>
            <td>{{ $c->calls_count }}</td>
            <td>
              @if ($c->next_call_status)
                <span class="badge badge-{{ $c->next_call_status }}">{{ $c->next_call_status }}</span>
              @else
                <span class="badge">no call</span>
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
</form>

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
