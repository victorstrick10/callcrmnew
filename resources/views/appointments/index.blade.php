@extends('layouts.app')

@section('title', 'Appointments')
@section('page_title', 'Appointments')
@section('page_subtitle', 'Calendly calls waiting for profile creation')

@section('content')
<form method="get" class="form-actions" style="margin-bottom:1rem">
  <select name="company" onchange="this.form.submit()">
    <option value="">All companies</option>
    @foreach (($companies ?? []) as $company)
      <option value="{{ $company->slug }}" @selected(($companySlug ?? '') === $company->slug)>{{ $company->name }}</option>
    @endforeach
  </select>
</form>

<div class="panel">
  <div class="panel-head"><div><h2>All scheduled clients</h2><p>{{ $appointments->count() }} records</p></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Company</th><th>Client</th><th>Event</th><th>Date</th><th>Location</th><th>Profiles</th><th>Status</th></tr></thead>
      <tbody>
      @forelse ($appointments as $a)
        <tr class="click-row" data-href="{{ route('appointments.show', $a) }}">
          <td>{{ $a->company?->name ?? '—' }}</td>
          <td><strong>{{ $a->contact->full_name }}</strong><small>{{ $a->contact->email }}</small></td>
          <td>{{ $a->event_name }}</td>
          <td>{{ $a->start_time ? $a->start_time->format('d.m.Y H:i') : 'Not set' }}</td>
          <td>{{ $a->city ?: 'Unknown' }}@if($a->region), {{ $a->region }}@endif</td>
          <td>{{ $a->profiles->count() }}/2</td>
          <td><span class="badge badge-{{ $a->status }}">{{ $a->status }}</span></td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty">No appointments.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
