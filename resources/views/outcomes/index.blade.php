@extends('layouts.app')

@section('title', 'Call Outcomes')
@section('page_title', 'Call Outcomes')
@section('page_subtitle', 'Log what happened on each call, comment per client, and keep the deal browser')

@section('content')
@php
  $chip = fn ($r) => ($range ?? '') === $r ? 'chip-active' : '';
  $dispTz = config('app.display_timezone') ?: config('app.timezone');
@endphp

<div class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <div class="quick-chips">
    <a class="chip-btn {{ $chip('this_week') }}" href="{{ route('outcomes.index', ['range' => 'this_week']) }}">This week</a>
    <a class="chip-btn {{ $chip('last_week') }}" href="{{ route('outcomes.index', ['range' => 'last_week']) }}">Last week</a>
    <a class="chip-btn {{ $chip('all') }}" href="{{ route('outcomes.index', ['range' => 'all']) }}">All time</a>
  </div>
</div>

<div class="stat-grid wrap compact" style="grid-template-columns:repeat(6,1fr)">
  <div class="stat-card"><span>Total calls</span><strong>{{ $summary['total'] }}</strong><small>in range</small></div>
  <div class="stat-card danger"><span>No-show</span><strong>{{ $summary['no_show'] }}</strong><small>didn't join</small></div>
  <div class="stat-card accent"><span>Rescheduled</span><strong>{{ $summary['rescheduled'] }}</strong><small>moved</small></div>
  <div class="stat-card"><span>Left the call</span><strong>{{ $summary['left_early'] }}</strong><small>early exit</small></div>
  <div class="stat-card"><span>Deals won</span><strong>{{ $summary['closed_won'] }}</strong><small>closed</small></div>
  <div class="stat-card"><span>Commented</span><strong>{{ $summary['commented'] }}</strong><small>have a note</small></div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Calls</h2><p>{{ $appointments->count() }} call(s) · set an outcome + comment, and star the browser to keep · GMT+1</p></div>
  </div>
  <div class="table-wrap">
    <table class="outcomes-table">
      <thead>
        <tr>
          <th>Lead</th>
          <th>Company</th>
          <th>Call</th>
          <th style="width:190px">Outcome</th>
          <th>Comment</th>
          <th>Browsers</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
      @forelse ($appointments as $a)
        <tr class="oc-row oc-{{ $a->outcome }}">
          <td class="col-lead">
            <strong>{{ $a->contact?->full_name ?: '—' }}</strong>
            <small>{{ $a->contact?->email }}</small>
          </td>
          <td class="col-b">{{ $a->company?->name ?? '—' }}</td>
          <td class="col-b">
            @if ($a->localStart())
              <strong>{{ $a->localStart()->format('d.m.Y H:i') }}</strong>
              <small>{{ $a->status }}</small>
            @else
              <span class="muted">Not set</span>
            @endif
          </td>
          <td>
            <form method="post" action="{{ route('outcomes.update', $a) }}" id="oc-{{ $a->id }}">@csrf @method('PUT')</form>
            <select name="outcome" form="oc-{{ $a->id }}" class="outcome-select">
              @foreach ($outcomes as $key => $label)
                <option value="{{ $key }}" @selected($a->outcome === $key)>{{ $label }}</option>
              @endforeach
            </select>
          </td>
          <td>
            <input type="text" name="outcome_note" form="oc-{{ $a->id }}" class="oc-note"
                   value="{{ $a->outcome_note }}" placeholder="Reason / reschedule / notes…">
          </td>
          <td class="oc-browsers">
            @php $profs = $a->profiles->whereIn('status', ['created', 'reserved'])->sortBy('number'); @endphp
            @forelse ($profs as $p)
              <span class="oc-browser {{ $p->is_kept ? 'kept' : '' }}">
                <span class="oc-browser-name">{{ sprintf('%03d', (int) $p->number) }} {{ strtoupper(str_replace('_', '-', $p->profile_role)) }}</span>
                <form method="post" action="{{ route('outcomes.keep-profile', $p) }}" style="display:inline;margin:0">
                  @csrf
                  <button type="submit" class="keep-star {{ $p->is_kept ? 'on' : '' }}"
                          title="{{ $p->is_kept ? 'Kept forever (deal). Click to unkeep.' : 'Keep this browser forever (deal closed)' }}">{{ $p->is_kept ? '★' : '☆' }}</button>
                </form>
              </span>
            @empty
              <span class="muted">No browsers</span>
            @endforelse
          </td>
          <td>
            <button class="btn btn-primary btn-sm" form="oc-{{ $a->id }}" type="submit">Save</button>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty">No calls in this range.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
