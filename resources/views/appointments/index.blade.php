@extends('layouts.app')

@section('title', 'Appointments')
@section('page_title', 'Appointments')
@section('page_subtitle', 'Calendly calls — sort and filter across every field')

@section('content')
@php
  $base = array_filter([
    'company' => $companySlug ?? '',
    'status' => $status ?? '',
    'q' => $search ?? '',
  ], fn ($v) => $v !== '' && $v !== null);

  $sortLink = function (string $key, string $label) use ($base, $sort, $dir) {
    $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $key ? ($dir === 'asc' ? '▲' : '▼') : '↕';
    $url = route('appointments.index', array_merge($base, ['sort' => $key, 'dir' => $nextDir]));
    $active = $sort === $key ? ' active' : '';
    return '<a class="sort-th'.$active.'" href="'.$url.'">'.e($label).' <i>'.$arrow.'</i></a>';
  };
@endphp

<form method="get" class="panel filters-bar" style="padding:16px;margin-bottom:16px">
  <input type="hidden" name="sort" value="{{ $sort }}">
  <input type="hidden" name="dir" value="{{ $dir }}">
  <div class="form-grid four" style="align-items:end">
    <div>
      <label>Company</label>
      <select name="company">
        <option value="">All companies</option>
        @foreach (($companies ?? []) as $company)
          <option value="{{ $company->slug }}" @selected(($companySlug ?? '') === $company->slug)>{{ $company->name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <option value="scheduled" @selected(($status ?? '') === 'scheduled')>Scheduled</option>
        <option value="canceled" @selected(($status ?? '') === 'canceled')>Canceled</option>
      </select>
    </div>
    <div>
      <label>Search</label>
      <div class="search-group">
        <input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Client, email, event…">
        <button class="btn btn-primary" type="submit">🔍 Search</button>
      </div>
    </div>
    <div class="form-actions" style="margin:0">
      <a class="btn btn-secondary" href="{{ route('appointments.index') }}">Reset</a>
    </div>
  </div>
</form>

<div class="panel">
  <div class="panel-head">
    <div><h2>All scheduled clients</h2><p>{{ $appointments->count() }} records · sorted by {{ $sort }} ({{ $dir }})</p></div>
  </div>
  <div class="table-wrap">
    <table class="sortable-table">
      <thead>
        <tr>
          <th>{!! $sortLink('company', 'Company') !!}</th>
          <th>{!! $sortLink('client', 'Client') !!}</th>
          <th>{!! $sortLink('event', 'Event') !!}</th>
          <th>{!! $sortLink('date', 'Date') !!}</th>
          <th>{!! $sortLink('location', 'Location') !!}</th>
          <th>{!! $sortLink('profiles', 'Profiles') !!}</th>
          <th>{!! $sortLink('status', 'Status') !!}</th>
        </tr>
      </thead>
      <tbody>
      @forelse ($appointments as $a)
        <tr class="click-row" data-href="{{ route('appointments.show', $a) }}">
          <td>{{ $a->company?->name ?? '—' }}</td>
          <td><strong>{{ $a->contact->full_name }}</strong><small>{{ $a->contact->email }}</small></td>
          <td>{{ $a->event_name }}</td>
          <td>{{ $a->start_time ? $a->start_time->format('d.m.Y H:i') : 'Not set' }}</td>
          <td>{{ $a->city ?: 'Unknown' }}@if($a->region), {{ $a->region }}@endif</td>
          <td><span class="pill-count">{{ $a->profiles->count() }}/2</span></td>
          <td><span class="badge badge-{{ $a->status }}">{{ $a->status }}</span></td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty">No appointments match these filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
