@extends('layouts.app')

@section('title', $company->exists ? 'Edit company' : 'New company')
@section('page_title', $company->exists ? $company->name : 'New company')
@section('page_subtitle', 'Lead API + Calendly + Multilogin — configured per company in one place')

@section('content')
@php
  $mlCfg = $company->multiloginConfig();
  $discovery = $mlCfg['discovery_cache'] ?? [];
  $mlSimOn = in_array(strtolower((string)($mlCfg['simulation_mode'] ?? 'false')), ['true','1','yes','on'], true);
  $mlStrictOn = in_array(strtolower((string)($mlCfg['multilogin_proxy_strict_mode'] ?? 'false')), ['true','1','yes','on'], true);
  $stateText = ['up' => 'Live', 'down' => 'Down', 'unknown' => 'Not tested'];
@endphp

@if ($company->exists)
<div class="panel">
  <div class="panel-head"><div><h2>Service status</h2><p>Live connectivity for this company — test each below</p></div></div>
  <div class="status-strip">
    @foreach ([['lead','Lead API'],['calendly','Calendly'],['multilogin','Multilogin']] as [$svc,$label])
      @php $st = $company->serviceState($svc); @endphp
      <div class="status-pill state-{{ $st }}">
        <span class="dot"></span>
        <div>
          <strong>{{ $label }}</strong>
          <small>{{ $stateText[$st] }}@if($company->serviceMessage($svc)) · {{ \Illuminate\Support\Str::limit($company->serviceMessage($svc), 40) }}@endif</small>
        </div>
      </div>
    @endforeach
  </div>
</div>
@endif

<div class="panel">
  <div class="integration-head">
    <div class="integration-icon cal">Co</div>
    <div>
      <h2>{{ $company->exists ? 'Company settings' : 'Create company' }}</h2>
      <p>Webhook: <code>{{ url('/webhooks/calendly/'.($company->slug ?: '{slug}')) }}</code></p>
    </div>
  </div>

  <form method="post" action="{{ $company->exists ? route('companies.update', $company) : route('companies.store') }}">
    @csrf
    @if ($company->exists)
      @method('PUT')
    @endif

    <h3 class="section-title">Identity</h3>
    <div class="form-grid">
      <div><label>Name</label><input name="name" value="{{ old('name', $company->name) }}" required></div>
      <div><label>Slug</label><input name="slug" value="{{ old('slug', $company->slug) }}" required></div>
    </div>

    <h3 class="section-title">Lead API</h3>
    <div class="form-grid">
      <div>
        <label>Lead API URL</label>
        <input name="lead_api_url" value="{{ old('lead_api_url', $company->lead_api_url) }}" placeholder="https://diligentplacers.com/api.php">
      </div>
      <div>
        <label>Lead API key <span>{{ $masked($company->exists ? $company->getLeadApiKey() : null) }}</span></label>
        <input type="password" name="lead_api_key" placeholder="Leave blank to keep saved key">
      </div>
    </div>

    <h3 class="section-title">Calendly</h3>
    <div class="form-grid">
      <div>
        <label>Calendly personal token <span>{{ $masked($company->exists ? $company->getCalendlyApiToken() : null) }}</span></label>
        <input type="password" name="calendly_api_token" placeholder="Leave blank to keep saved token">
      </div>
      <div>
        <label>Calendly organization URI</label>
        <input name="calendly_org_uri" value="{{ old('calendly_org_uri', $company->calendly_org_uri) }}" placeholder="https://api.calendly.com/organizations/...">
      </div>
      <div>
        <label>Calendly webhook signing key <span>{{ $masked($company->exists ? $company->getCalendlyWebhookSigningKey() : null) }}</span></label>
        <input type="password" name="calendly_webhook_signing_key" placeholder="Optional">
      </div>
    </div>

    <h3 class="section-title">Multilogin</h3>
    <div class="form-grid">
      <div>
        <label>Automation token <span>{{ $masked($company->exists ? $company->getMultiloginToken() : null) }}</span></label>
        <input type="password" name="multilogin_token" placeholder="Leave blank to keep saved token">
      </div>
      <div>
        <label>API base URL</label>
        <input name="multilogin_base_url" value="{{ old('multilogin_base_url', $company->multilogin_base_url ?: 'https://api.multilogin.com') }}">
      </div>
      <div>
        <label>Workspace ID</label>
        <input name="ml_workspace_id" value="{{ $mlCfg['workspace_id'] ?? '' }}" placeholder="Account settings → Info (also the Default folder ID)">
      </div>
      <div>
        <label>GEO folder ID</label>
        <input name="ml_geo_folder_id" value="{{ $mlCfg['geo_folder_id'] ?? '' }}" placeholder="Defaults to Workspace ID">
      </div>
      <div>
        <label>STATIC folder ID</label>
        <input name="ml_static_folder_id" value="{{ $mlCfg['static_folder_id'] ?? '' }}" placeholder="Defaults to Workspace ID">
      </div>
      <div>
        <label>Proxy protocol</label>
        <select name="ml_multilogin_proxy_protocol">
          <option value="http" @selected(($mlCfg['multilogin_proxy_protocol'] ?? 'http') === 'http')>HTTP</option>
          <option value="socks5" @selected(($mlCfg['multilogin_proxy_protocol'] ?? '') === 'socks5')>SOCKS5</option>
        </select>
      </div>
      <div>
        <label>Session type</label>
        <select name="ml_multilogin_proxy_session_type">
          <option value="sticky" @selected(($mlCfg['multilogin_proxy_session_type'] ?? 'sticky') === 'sticky')>Sticky</option>
          <option value="rotating" @selected(($mlCfg['multilogin_proxy_session_type'] ?? '') === 'rotating')>Rotating</option>
        </select>
      </div>
      <div>
        <label>Proxy IP TTL (s)</label>
        <input type="number" min="0" max="86400" name="ml_multilogin_proxy_ip_ttl" value="{{ $mlCfg['multilogin_proxy_ip_ttl'] ?? '0' }}">
      </div>
      <div>
        <label>STATIC template profile</label>
        <select name="ml_template_profile_id">
          <option value="">Select a template</option>
          @foreach (($discovery['templates'] ?? []) as $item)
            <option value="{{ $item['id'] }}" @selected(($item['id'] ?? '') == ($mlCfg['template_profile_id'] ?? ''))>{{ $item['name'] }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div class="switch-grid">
      <label class="switch-row">
        <input type="checkbox" name="ml_simulation_mode" @checked($mlSimOn)>
        <span class="switch"></span><div><strong>Simulation Mode</strong><small>Test without real Multilogin API calls.</small></div>
      </label>
      <label class="switch-row">
        <input type="checkbox" name="ml_strict_mode" @checked($mlStrictOn)>
        <span class="switch"></span><div><strong>Strict proxy mode</strong><small>Send every proxy field explicitly.</small></div>
      </label>
      <label class="switch-row">
        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $company->enabled))>
        <span class="switch"></span><div><strong>Enabled</strong><small>Include in leads:sync</small></div>
      </label>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save company</button>
      <a class="btn btn-secondary" href="{{ route('companies.index') }}">Back</a>
    </div>
  </form>

  @if ($company->exists)
    <div class="form-actions" style="margin-top:1rem;flex-wrap:wrap">
      <form method="post" action="{{ route('companies.test-lead-api', $company) }}">@csrf<button class="btn btn-secondary" type="submit">Test lead API</button></form>
      <form method="post" action="{{ route('companies.test-calendly', $company) }}">@csrf<button class="btn btn-secondary" type="submit">Test Calendly</button></form>
      <form method="post" action="{{ route('companies.multilogin.connect', $company) }}">@csrf<button class="btn btn-secondary" type="submit">⚡ Connect Multilogin &amp; sync numbers</button></form>
      <form method="post" action="{{ route('companies.sync', $company) }}">@csrf<button class="btn btn-primary" type="submit">Sync leads + Calendly</button></form>
    </div>

    @if (!empty($discovery['connected']))
    <div class="discovery-summary">
      <div><strong>{{ $discovery['numbering']['profiles_scanned'] ?? count($discovery['profiles'] ?? []) }}</strong><span>Profiles scanned</span></div>
      <div><strong>{{ count($discovery['folders'] ?? []) }}</strong><span>Folders</span></div>
      <div><strong>{{ $discovery['numbering']['numbers_used'] ?? 0 }}</strong><span>Numbered</span></div>
      <div><strong>{{ isset($discovery['numbering']['next_free']) ? sprintf('%03d', $discovery['numbering']['next_free']) : '—' }}</strong><span>Next free</span></div>
    </div>
    @endif
  @endif
</div>
@endsection
