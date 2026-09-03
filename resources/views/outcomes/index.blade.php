@extends('layouts.app')

@section('title', 'Call Stats')
@section('page_title', 'Call Stats')
@section('page_subtitle', 'Log each call outcome (tap an icon), comment per client, and keep the deal browser')

@section('content')
@php
  $chip = fn ($r) => ($range ?? '') === $r ? 'chip-active' : '';
  $icons = [
    'scheduled'   => ['📅', 'Scheduled'],
    'joined_line' => ['🤝', 'Joined/LINE (deal closed)'],
    'joined_vorr' => ['💬', 'Joined/Vorr'],
    'joined_left' => ['🚪', 'Joined/Left Call'],
    'no_show'     => ['❌', "Didn't join"],
    'rescheduled' => ['🔄', 'Rescheduled'],
    'canceled'    => ['🚫', 'Canceled'],
  ];
  $labelFor = function ($a) use ($icons) {
    if ($a->hasCustomOutcome()) return $a->outcome;
    $eff = $a->effectiveOutcome();
    return $icons[$eff][1] ?? (\App\Models\Appointment::OUTCOMES[$eff] ?? $eff);
  };
  $copyLines = $appointments->map(function ($a) use ($labelFor) {
    $time = $a->localStart()?->format('H:i') ?? '--:--';
    $note = trim((string) $a->outcome_note);
    return $time.' - '.$labelFor($a).($note !== '' ? ' - '.$note : '');
  })->implode("\n");
@endphp

<form method="get" class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <div class="quick-chips">
    <button class="chip-btn {{ $chip('today') }}" type="submit" name="range" value="today">Today's calls</button>
    <button class="chip-btn {{ $chip('this_week') }}" type="submit" name="range" value="this_week">This week</button>
    <button class="chip-btn {{ $chip('last_week') }}" type="submit" name="range" value="last_week">Last week</button>
    <button class="chip-btn {{ $chip('all') }}" type="submit" name="range" value="all">All time</button>
  </div>
  <div class="form-grid" style="grid-template-columns:repeat(3,1fr);align-items:end;margin-top:14px">
    <div><label>From</label><input type="date" name="from" value="{{ $from ?? '' }}"></div>
    <div><label>To</label><input type="date" name="to" value="{{ $to ?? '' }}"></div>
    <div class="form-actions" style="margin:0">
      <button class="btn btn-primary" type="submit">Apply dates</button>
      <a class="btn btn-secondary" href="{{ route('outcomes.index') }}">Reset</a>
    </div>
  </div>
</form>

<div class="stat-grid wrap compact" style="grid-template-columns:repeat(6,1fr)">
  <div class="stat-card"><span>Total calls</span><strong>{{ $summary['total'] }}</strong><small>in range</small></div>
  <div class="stat-card"><span>Joined</span><strong>{{ $summary['joined'] }}</strong><small>attended</small></div>
  <div class="stat-card"><span>Deals</span><strong>{{ $summary['deals'] }}</strong><small>Joined/LINE</small></div>
  <div class="stat-card danger"><span>Didn't join</span><strong>{{ $summary['no_show'] }}</strong><small>no-show</small></div>
  <div class="stat-card accent"><span>Rescheduled</span><strong>{{ $summary['rescheduled'] }}</strong><small>moved</small></div>
  <div class="stat-card"><span>Commented</span><strong>{{ $summary['commented'] }}</strong><small>have a note</small></div>
</div>

<div class="form-actions" style="margin:0 0 16px">
  <button type="button" class="btn btn-primary copy-btn" data-copy="{{ $copyLines }}" title="Copy every call as: time - outcome - comment">⧉ Copy stats (end of day)</button>
  <span class="muted">Copies each call as <code>HH:MM - outcome - comment</code>.</span>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Calls</h2><p>{{ $appointments->count() }} call(s) · tap an outcome icon to log it · GMT+1</p></div>
  </div>
  <div class="table-wrap">
    <table class="outcomes-table">
      <thead>
        <tr>
          <th>Lead</th>
          <th>Company</th>
          <th>Call</th>
          <th>Outcome</th>
          <th>Comment</th>
          <th>Browsers</th>
        </tr>
      </thead>
      <tbody>
      @forelse ($appointments as $a)
        @php $isCustom = $a->hasCustomOutcome(); $eff = $a->effectiveOutcome(); @endphp
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
            <div class="outcome-icons">
              @foreach ($icons as $key => [$icon, $label])
                <button type="submit" form="oc-{{ $a->id }}" name="outcome" value="{{ $key }}"
                        class="oc-icon {{ ! $isCustom && $eff === $key ? 'active' : '' }}" title="{{ $label }}">{{ $icon }}</button>
              @endforeach
              <button type="button" class="oc-icon oc-custom-toggle {{ $isCustom ? 'active' : '' }}" data-row="{{ $a->id }}" title="Custom outcome">✎</button>
            </div>
            <div class="oc-custom-wrap" id="occ-wrap-{{ $a->id }}" style="{{ $isCustom ? '' : 'display:none' }}">
              <input type="text" name="outcome_custom" form="oc-{{ $a->id }}" value="{{ $isCustom ? $a->outcome : '' }}" placeholder="Custom outcome" maxlength="30">
              <button type="submit" form="oc-{{ $a->id }}" name="outcome" value="__custom__" class="btn btn-primary btn-sm">Save</button>
            </div>
          </td>
          <td>
            <div class="oc-note-row">
              <input type="text" name="outcome_note" form="oc-{{ $a->id }}" class="oc-note"
                     value="{{ $a->outcome_note }}" placeholder="Reason / notes…">
              <button type="submit" form="oc-{{ $a->id }}" name="outcome" value="{{ $isCustom ? '__custom__' : $eff }}"
                      class="btn btn-secondary btn-sm" title="Save note (keeps current outcome)">💾</button>
            </div>
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
        </tr>
      @empty
        <tr><td colspan="6" class="empty">No calls in this range.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<script>
  document.querySelectorAll('.oc-custom-toggle').forEach(function (btn) {
    var wrap = document.getElementById('occ-wrap-' + btn.dataset.row);
    if (!wrap) return;
    btn.addEventListener('click', function () {
      var show = wrap.style.display === 'none';
      wrap.style.display = show ? '' : 'none';
      var inp = wrap.querySelector('input');
      if (show && inp) inp.focus();
    });
  });
</script>
@endsection
