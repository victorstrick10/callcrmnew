@extends('layouts.app')

@section('title', 'Call Stats')
@section('page_title', 'Call Stats')
@section('page_subtitle', 'Log each call outcome (tap an icon), comment per client, and keep the deal browser')

@section('content')
@php
  $dispTz = config('app.display_timezone') ?: config('app.timezone');
  $chip = fn ($r) => ($range ?? '') === $r ? 'chip-active' : '';
  $icons = [
    'scheduled'   => ['📅', 'Scheduled'],
    'joined_line' => ['🤝', 'Joined/LINE (deal closed)'],
    'joined_vorr' => ['🗑️', 'Joined/Vorr'],
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
  $copyLines = $copyList->map(function ($a) use ($labelFor) {
    $time = $a->localStart()?->format('H:i') ?? '--:--';
    $note = trim((string) $a->outcome_note);
    return $time.' - '.$labelFor($a).($note !== '' ? ' - '.$note : '');
  })->implode("\n");
  $copyDate = optional($copyList->first()?->localStart())->format('d.m.y')
    ?: \Illuminate\Support\Carbon::now($dispTz)->format('d.m.y');
  $copyText = $copyDate."\n".$copyLines;
@endphp

<form method="get" class="filters-bar panel" style="padding:16px;margin-bottom:16px">
  <div class="quick-chips">
    <button class="chip-btn {{ $chip('today') }}" type="submit" name="range" value="today">Today</button>
    <button class="chip-btn {{ $chip('this_week') }}" type="submit" name="range" value="this_week">This week</button>
    <button class="chip-btn {{ $chip('last_week') }}" type="submit" name="range" value="last_week">Last week</button>
    <button class="chip-btn {{ $chip('month') }}" type="submit" name="range" value="month">This month</button>
    <button class="chip-btn {{ $chip('q3') }}" type="submit" name="range" value="q3">3 months</button>
    <button class="chip-btn {{ $chip('q6') }}" type="submit" name="range" value="q6">6 months</button>
    <button class="chip-btn {{ $chip('year') }}" type="submit" name="range" value="year">Year</button>
    <button class="chip-btn {{ $chip('all') }}" type="submit" name="range" value="all">All time</button>
  </div>
  <div class="form-grid" style="grid-template-columns:repeat(5,1fr);align-items:end;margin-top:14px">
    <div><label>From</label><input type="date" name="from" value="{{ $from ?? '' }}"></div>
    <div><label>To</label><input type="date" name="to" value="{{ $to ?? '' }}"></div>
    <div><label>Search calls</label><input type="search" name="q" value="{{ $search ?? '' }}" placeholder="Name, email, company…"></div>
    <div>
      <label>Per page</label>
      <select name="per_page" onchange="this.form.submit()">
        @foreach (['10' => '10', '20' => '20', '25' => '25', 'all' => 'All'] as $val => $lbl)
          <option value="{{ $val }}" @selected(($perPage ?? '10') === $val)>{{ $lbl }}</option>
        @endforeach
      </select>
    </div>
    <div class="form-actions" style="margin:0">
      <button class="btn btn-primary" type="submit">🔍 Apply</button>
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

@php
  $exportQuery = array_filter(['range' => $range ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'q' => $search ?? ''], fn ($v) => $v !== '' && $v !== null);
@endphp
<div class="form-actions" style="margin:0 0 16px">
  <button type="button" class="btn btn-primary copy-btn" data-copy="{{ $copyText }}" title="Copy: date, then each call as time - outcome - comment">⧉ Copy stats (end of day)</button>
  <a class="btn btn-secondary" href="{{ route('outcomes.export', $exportQuery) }}">⭳ Export CSV</a>
  <span class="muted">Copies/exports each call as <code>Lead · Company · time · outcome · comment</code>.</span>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Calls</h2><p>{{ $appointments->total() }} call(s) · tap an outcome icon to log it · GMT+1</p></div>
    <div class="oc-legend">
      @foreach ($icons as $key => [$icon, $label])
        <span class="oc-legend-item"><b>{{ $icon }}</b> {{ $label }}</span>
      @endforeach
    </div>
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
            <span class="oc-current">{{ $labelFor($a) }}</span>
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
            @php
              // Show the lead's saved browsers across ALL their calls, so old
              // saved Multilogin browsers match this lead.
              $profs = $a->contact
                ? $a->contact->appointments->flatMap(fn ($ap) => $ap->profiles)
                    ->whereIn('status', ['created', 'reserved'])
                    ->unique('id')->sortBy('number')->values()
                : $a->profiles->whereIn('status', ['created', 'reserved'])->sortBy('number');
            @endphp
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
        <tr><td colspan="6" class="empty">No calls match this filter.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if ($appointments->hasPages())
    <div class="pager">
      @if (! $appointments->onFirstPage())
        <a class="chip-btn" href="{{ $appointments->previousPageUrl() }}">‹ Prev</a>
      @endif
      <span class="muted">Page {{ $appointments->currentPage() }} / {{ $appointments->lastPage() }} · {{ $appointments->total() }} calls</span>
      @if ($appointments->hasMorePages())
        <a class="chip-btn" href="{{ $appointments->nextPageUrl() }}">Next ›</a>
      @endif
    </div>
  @endif
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Outcome analytics</h2><p>Reflects the {{ $summary['total'] }} call(s) in the current filter above — change the range/search to move the gauges</p></div>
  </div>
  <div class="analytics-grid">
    <div class="analytics-card">
      <h3>Joined/LINE</h3>
      <div class="gauge-wrap"><canvas id="gaugeDeal"></canvas><div class="gauge-center deal" id="gaugeDealVal">0</div></div>
      <small class="muted">deals closed</small>
    </div>
    <div class="analytics-card">
      <h3>Win rate</h3>
      <div class="gauge-wrap"><canvas id="gaugeWin"></canvas><div class="gauge-center" id="gaugeWinVal">0%</div></div>
      <small class="muted">deals / attended</small>
    </div>
    <div class="analytics-card">
      <h3>Show rate</h3>
      <div class="gauge-wrap"><canvas id="gaugeShow"></canvas><div class="gauge-center" id="gaugeShowVal">0%</div></div>
      <small class="muted">attended / total</small>
    </div>
    <div class="analytics-card wide">
      <h3>Outcomes — share of total</h3>
      <div class="outcome-gauges" id="outcomeGauges"></div>
    </div>
    <div class="analytics-card wide">
      <h3>Calls &amp; deals per month <span class="muted">(hover a month for its outcome breakdown)</span></h3>
      <div class="trend-wrap"><canvas id="trendBar"></canvas></div>
      <div class="month-totals">
        @foreach ($trend as $m)
          <div class="month-total {{ $m['calls'] > 0 ? 'has' : '' }}">
            <span class="mt-label">{{ $m['label'] }}</span>
            <b class="mt-count">📞 {{ $m['calls'] }}</b>
          </div>
        @endforeach
      </div>
    </div>
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

<script>window.__analytics = @json($analytics); window.__trend = @json($trend);</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  var A = window.__analytics || {}, T = window.__trend || [];
  var labels = { scheduled:'Scheduled', joined_line:'Joined/LINE', joined_vorr:'Joined/Vorr', joined_left:'Joined/Left', no_show:"Didn't join", rescheduled:'Rescheduled', canceled:'Canceled' };
  var colors = { scheduled:'#94a3b8', joined_line:'#16a36a', joined_vorr:'#5b5cf0', joined_left:'#0ea5e9', no_show:'#dc3f51', rescheduled:'#f59e0b', canceled:'#64748b' };
  var keys = Object.keys(labels);

  // Half-circle gauge with a text center. `pct` fills the arc, `centerText` is shown.
  function gauge(canvasId, valId, pct, color, centerText) {
    var el = document.getElementById(canvasId);
    if (!el) return null;
    var c = new Chart(el, {
      type: 'doughnut',
      data: { datasets: [{ data: [pct, 100 - pct], backgroundColor: [color, '#eef1f6'], borderWidth: 0 }] },
      options: { rotation: -90, circumference: 180, cutout: '72%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, responsive: true, maintainAspectRatio: false }
    });
    var v = document.getElementById(valId); if (v) v.textContent = centerText;
    return c;
  }

  // Per-outcome mini gauges, each in the outcome's own color.
  var miniCharts = [];
  function renderOutcomeGauges(counts, total) {
    var wrap = document.getElementById('outcomeGauges');
    if (!wrap) return;
    miniCharts.forEach(function (c) { c.destroy(); });
    miniCharts = [];
    wrap.innerHTML = '';
    keys.forEach(function (k) {
      var count = counts[k] || 0;
      var pct = total > 0 ? Math.round(count / total * 100) : 0;
      var cell = document.createElement('div');
      cell.className = 'mini-gauge';
      cell.innerHTML = '<div class="mini-gauge-wrap"><canvas></canvas><div class="mini-gauge-val">' + pct + '%</div></div>'
        + '<span class="mini-gauge-label" style="color:' + colors[k] + '">' + labels[k] + '</span>'
        + '<b class="mini-gauge-count">' + count + '</b>';
      wrap.appendChild(cell);
      var cv = cell.querySelector('canvas');
      miniCharts.push(new Chart(cv, {
        type: 'doughnut',
        data: { datasets: [{ data: [pct, 100 - pct], backgroundColor: [colors[k], '#eef1f6'], borderWidth: 0 }] },
        options: { cutout: '70%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, responsive: true, maintainAspectRatio: false }
      }));
    });
  }

  (function renderAnalytics() {
    var d = (A && A.counts) ? A : { counts: {}, total: 0, win_rate: 0, show_rate: 0, deals: 0 };
    var dealShare = d.total > 0 ? Math.round((d.deals || 0) / d.total * 100) : 0;
    gauge('gaugeDeal', 'gaugeDealVal', dealShare, '#16a36a', String(d.deals || 0));
    gauge('gaugeWin', 'gaugeWinVal', d.win_rate || 0, '#5b5cf0', (d.win_rate || 0) + '%');
    gauge('gaugeShow', 'gaugeShowVal', d.show_rate || 0, '#0ea5e9', (d.show_rate || 0) + '%');
    renderOutcomeGauges(d.counts || {}, d.total || 0);
  })();

  var tb = document.getElementById('trendBar');
  if (tb) {
    new Chart(tb, {
      data: {
        labels: T.map(function (m) { return m.label; }),
        datasets: [
          { type: 'bar', label: 'Calls', data: T.map(function (m) { return m.calls; }), backgroundColor: 'rgba(91,92,240,.35)', borderRadius: 4 },
          { type: 'line', label: 'Deals (Joined/LINE)', data: T.map(function (m) { return m.deals; }), borderColor: '#16a36a', backgroundColor: '#16a36a', tension: .3, pointRadius: 3 }
        ]
      },
      options: {
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
          tooltip: {
            callbacks: {
              afterBody: function (items) {
                var idx = items[0].dataIndex;
                var o = (T[idx] || {}).outcomes || {};
                var lines = ['', 'Outcomes:'];
                keys.forEach(function (k) { if ((o[k] || 0) > 0) lines.push('  ' + labels[k] + ': ' + o[k]); });
                return lines;
              }
            }
          }
        },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        responsive: true, maintainAspectRatio: false
      }
    });
  }
})();
</script>
@endsection
