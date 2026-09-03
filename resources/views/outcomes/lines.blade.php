@extends('layouts.app')

@section('title', 'Lines')
@section('page_title', '🤝 Lines')
@section('page_subtitle', 'Closed deals (Joined/LINE) — dashboard, trend & totals')

@section('content')
@php
  $dispTz = config('app.display_timezone') ?: config('app.timezone');
  $chip = fn ($r) => ($range ?? '') === $r ? 'chip-active' : '';
  $copyLines = $copyList->map(function ($a) {
    $when = $a->localStart()?->format('d.m.y H:i') ?? '--';
    $note = trim((string) $a->outcome_note);
    return $when.' - '.($a->contact?->full_name ?: 'lead').' ('.($a->company?->name ?: '—').')'.($note !== '' ? ' - '.$note : '');
  })->implode("\n");
  $exportQuery = array_filter(['range' => $range ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'q' => $search ?? '', 'outcome' => 'deals'], fn ($v) => $v !== '' && $v !== null);
@endphp

<form method="get" class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <div class="quick-chips">
    <button class="chip-btn {{ $chip('today') }}" type="submit" name="range" value="today">Today</button>
    <button class="chip-btn {{ $chip('this_week') }}" type="submit" name="range" value="this_week">This week</button>
    <button class="chip-btn {{ $chip('month') }}" type="submit" name="range" value="month">This month</button>
    <button class="chip-btn {{ $chip('q3') }}" type="submit" name="range" value="q3">3 months</button>
    <button class="chip-btn {{ $chip('q6') }}" type="submit" name="range" value="q6">6 months</button>
    <button class="chip-btn {{ $chip('year') }}" type="submit" name="range" value="year">Year</button>
    <button class="chip-btn {{ $chip('all') }}" type="submit" name="range" value="all">All time</button>
  </div>
  <div class="form-grid" style="grid-template-columns:repeat(4,1fr);align-items:end;margin-top:14px">
    <div><label>From</label><input type="date" name="from" value="{{ $from ?? '' }}"></div>
    <div><label>To</label><input type="date" name="to" value="{{ $to ?? '' }}"></div>
    <div><label>Search deals</label><input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Name, email, company…"></div>
    <div class="form-actions" style="margin:0">
      <button class="btn btn-primary" type="submit">🔍 Apply</button>
      <a class="btn btn-secondary" href="{{ route('outcomes.lines') }}">Reset</a>
    </div>
  </div>
</form>

<div class="stat-grid wrap compact" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat-card"><span>🤝 Deals in range</span><strong>{{ $totals['range'] }}</strong><small>current filter</small></div>
  <div class="stat-card accent"><span>📅 This month</span><strong>{{ $totals['month'] }}</strong><small>closed</small></div>
  <div class="stat-card"><span>🗓️ This year</span><strong>{{ $totals['year'] }}</strong><small>closed</small></div>
  <div class="stat-card"><span>🏆 All-time</span><strong>{{ $totals['all'] }}</strong><small>total deals</small></div>
</div>

<div class="form-actions" style="margin:0 0 16px">
  <button type="button" class="btn btn-primary copy-btn" data-copy="{{ $copyLines }}" title="Copy the deals list">⧉ Copy deals</button>
  <a class="btn btn-secondary" href="{{ route('outcomes.export', $exportQuery) }}">⭳ Export CSV</a>
</div>

<div class="panel">
  <div class="panel-head"><div><h2>🤝 Deals per month</h2><p>Closed deals over the last 12 months · GMT+1</p></div></div>
  <div class="trend-wrap"><canvas id="dealsBar"></canvas></div>
  <div class="month-totals">
    @foreach ($trend as $m)
      <div class="month-total {{ $m['deals'] > 0 ? 'has' : '' }}">
        <span class="mt-label">{{ $m['label'] }}</span>
        <b class="mt-count">🤝 {{ $m['deals'] }}</b>
      </div>
    @endforeach
  </div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Closed deals</h2><p>{{ $deals->total() }} deal(s) · comment &amp; keep the deal browser · GMT+1</p></div>
  </div>
  <div class="table-wrap">
    <table class="outcomes-table">
      <thead>
        <tr>
          <th style="width:20%">📇 Lead</th>
          <th style="width:14%">🏢 Company</th>
          <th style="width:14%">🕐 Call</th>
          <th style="width:32%">💬 Comment</th>
          <th style="width:20%">🌐 Browsers</th>
        </tr>
      </thead>
      <tbody>
      @forelse ($deals as $a)
        <tr class="oc-row oc-joined_line">
          <td class="col-lead"><strong>{{ $a->contact?->full_name ?: '—' }}</strong><small>{{ $a->contact?->email }}</small></td>
          <td class="col-b">{{ $a->company?->name ?? '—' }}</td>
          <td class="col-b">
            @if ($a->localStart())<strong>{{ $a->localStart()->format('d.m.Y H:i') }}</strong>@else<span class="muted">—</span>@endif
          </td>
          <td>
            <form method="post" action="{{ route('outcomes.update', $a) }}" id="ln-{{ $a->id }}">@csrf @method('PUT')<input type="hidden" name="outcome" value="joined_line"></form>
            <div class="oc-note-row">
              <input type="text" name="outcome_note" form="ln-{{ $a->id }}" class="oc-note" value="{{ $a->outcome_note }}" placeholder="Deal notes…">
              <button type="submit" form="ln-{{ $a->id }}" class="btn btn-secondary btn-sm" title="Save note">💾</button>
            </div>
          </td>
          <td class="oc-browsers">
            @php
              $profs = $a->contact
                ? $a->contact->appointments->flatMap(fn ($ap) => $ap->profiles)->whereIn('status', ['created', 'reserved'])->unique('id')->sortBy('number')->values()
                : collect();
            @endphp
            @forelse ($profs as $p)
              <span class="oc-browser {{ $p->is_kept ? 'kept' : '' }}">
                <span class="oc-browser-name">{{ sprintf('%03d', (int) $p->number) }} {{ strtoupper(str_replace('_', '-', $p->profile_role)) }}</span>
                <form method="post" action="{{ route('outcomes.keep-profile', $p) }}" style="display:inline;margin:0">@csrf
                  <button type="submit" class="keep-star {{ $p->is_kept ? 'on' : '' }}" title="{{ $p->is_kept ? 'Kept (deal browser)' : 'Keep this browser (deal)' }}">{{ $p->is_kept ? '★' : '☆' }}</button>
                </form>
              </span>
            @empty
              <span class="muted">No browsers</span>
            @endforelse
          </td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty">No closed deals in this filter.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($deals->hasPages())
    <div class="pager">
      @if (! $deals->onFirstPage())<a class="chip-btn" href="{{ $deals->previousPageUrl() }}">‹ Prev</a>@endif
      <span class="muted">Page {{ $deals->currentPage() }} / {{ $deals->lastPage() }} · {{ $deals->total() }} deals</span>
      @if ($deals->hasMorePages())<a class="chip-btn" href="{{ $deals->nextPageUrl() }}">Next ›</a>@endif
    </div>
  @endif
</div>

<script>window.__dealsTrend = @json($trend);</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  var T = window.__dealsTrend || [];
  var el = document.getElementById('dealsBar');
  if (!el) return;
  new Chart(el, {
    type: 'bar',
    data: { labels: T.map(function (m) { return m.label; }), datasets: [{ label: 'Deals (Joined/LINE)', data: T.map(function (m) { return m.deals; }), backgroundColor: 'rgba(22,163,106,.55)', borderColor: '#16a36a', borderWidth: 1, borderRadius: 5 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, responsive: true, maintainAspectRatio: false }
  });
})();
</script>
@endsection
