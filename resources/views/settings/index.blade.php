@extends('layouts.app')

@section('title', 'Integrations')
@section('page_title', 'Integration Settings')
@section('page_subtitle', 'Geolocation runs on ip-api.com (no key needed) — Calendly & Multilogin are configured per company')

@section('content')
@php
  $providerState = function ($provider) use ($rows) {
    $row = $rows[$provider] ?? null;
    $st = (string) ($row->last_test_status ?? '');
    return $st === 'success' ? 'up' : ($st === 'error' ? 'down' : 'unknown');
  };
  $stateText = ['up' => 'Online', 'down' => 'Error', 'unknown' => 'Not tested'];
@endphp

<div class="tabs" role="tablist">
  <button class="tab-btn" data-tab="ipinfo" type="button">🌐 Geo (ip-api.com)</button>
  <button class="tab-btn" data-tab="sync" type="button">🔄 Sync &amp; Status</button>
</div>

{{-- ============ Geolocation (ip-api.com) ============ --}}
<div class="tab-panel" data-tab-panel="ipinfo">
  <div class="panel integration-card wide">
    <div class="integration-head">
      <div class="integration-icon ip">IP</div>
      <div><h2>Geolocation · ip-api.com</h2><p>Reads each lead's country/region/city/ISP from its IP — no API key required</p></div>
      <span class="status-light state-{{ $providerState('ipinfo') }}" title="{{ $rows['ipinfo']->last_test_message ?? 'Not tested yet' }}">
        <span class="dot"></span>{{ $stateText[$providerState('ipinfo')] }}
      </span>
    </div>
    <form method="post" action="{{ route('settings.test', 'ipinfo') }}">
      @csrf
      <div class="notice">Geolocation uses the free <strong>ip-api.com</strong> endpoint — no key or token to manage. It resolves Country · Region · City · ISP for each lead and each proxy exit IP. Free tier allows 45 lookups/minute.</div>
      <div class="form-actions">
        <button class="btn btn-secondary" type="submit">Test connection</button>
      </div>
    </form>
    @if (!empty($rows['ipinfo']?->last_test_message))
    <p class="test-message state-{{ $providerState('ipinfo') }}">Last test: {{ $rows['ipinfo']->last_test_message }} <span class="muted">({{ optional($rows['ipinfo']->updated_at)->diffForHumans() }})</span></p>
    @endif
    <div class="notice" style="margin-top:16px">When a Calendly call arrives, the lead's geo is read automatically via ip-api.com — no manual step needed.</div>
  </div>
</div>

{{-- ============ Sync & Status ============ --}}
<div class="tab-panel" data-tab-panel="sync" hidden>
  <div class="panel">
    <div class="panel-head"><div><h2>How the sync works</h2><p>End-to-end automation pipeline</p></div></div>
    <div class="sync-flow big">
      <span class="sync-step"><b>1</b> Calendly call arrives<small>Webhook or scheduled sync (every 15 min)</small></span>
      <i>→</i>
      <span class="sync-step"><b>2</b> ip-api.com reads lead geo<small>City, region, ISP resolved from the lead's IP</small></span>
      <i>→</i>
      <span class="sync-step"><b>3</b> Build Multilogin profile<small>GEO uses a matched proxy; STATIC uses your pool</small></span>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><div><h2>Service status</h2><p>Geolocation (ip-api.com) is global; Calendly &amp; Multilogin are per company</p></div></div>
    <div class="status-table">
      @php $state = $providerState('ipinfo'); $row = $rows['ipinfo'] ?? null; @endphp
      <div class="status-row state-{{ $state }}">
        <span class="status-light state-{{ $state }}"><span class="dot"></span>{{ $stateText[$state] }}</span>
        <div class="status-row-main">
          <strong>Geo · ip-api.com</strong>
          <small>{{ $row?->last_test_message ?: 'Not tested yet — click Test connection.' }}</small>
        </div>
        <span class="muted">{{ optional($row?->updated_at)->diffForHumans() ?? '—' }}</span>
      </div>
      <div class="status-row state-info">
        <span class="status-light state-up"><span class="dot"></span>Per company</span>
        <div class="status-row-main">
          <strong>Calendly &amp; Multilogin</strong>
          <small>Configured and tested on each company (single place). Manage under <a href="{{ route('companies.index') }}">Companies</a>.</small>
        </div>
        <span class="muted">—</span>
      </div>
    </div>
  </div>
</div>
@endsection
