@extends('layouts.app')

@section('title', 'Integrations')
@section('page_title', 'Integration Settings')
@section('page_subtitle', 'IPinfo geo token and Multilogin automation — Calendly is configured per company')

@section('content')
@php
  $discovery = $multilogin['discovery_cache'] ?? [];
  $simOn = in_array(strtolower((string)($multilogin['simulation_mode'] ?? 'true')), ['true','1','yes','on'], true);
  $strictOn = in_array(strtolower((string)($multilogin['multilogin_proxy_strict_mode'] ?? 'false')), ['true','1','yes','on'], true);

  $providerState = function ($provider) use ($rows) {
    $row = $rows[$provider] ?? null;
    $st = (string) ($row->last_test_status ?? '');
    return $st === 'success' ? 'up' : ($st === 'error' ? 'down' : 'unknown');
  };
  $stateText = ['up' => 'Online', 'down' => 'Error', 'unknown' => 'Not tested'];
@endphp

<div class="tabs" role="tablist">
  <button class="tab-btn" data-tab="ipinfo" type="button">🌐 IPinfo</button>
  <button class="tab-btn" data-tab="multilogin" type="button">🧩 Multilogin</button>
  <button class="tab-btn" data-tab="sync" type="button">🔄 Sync &amp; Status</button>
</div>

{{-- ============ IPinfo ============ --}}
<div class="tab-panel" data-tab-panel="ipinfo">
  <div class="panel integration-card wide">
    <div class="integration-head">
      <div class="integration-icon ip">IP</div>
      <div><h2>IPinfo</h2><p>Geolocation enrichment token — reads each lead's city/region/ISP from its IP</p></div>
      <span class="status-light state-{{ $providerState('ipinfo') }}" title="{{ $rows['ipinfo']->last_test_message ?? 'Not tested yet' }}">
        <span class="dot"></span>{{ $stateText[$providerState('ipinfo')] }}
      </span>
    </div>
    <form method="post" action="{{ route('settings.store') }}">
      @csrf
      <input type="hidden" name="provider" value="ipinfo">
      <label>API token <span>{{ $masked($ipinfo['api_token'] ?? '') }}</span></label>
      <input type="password" name="api_token" placeholder="Leave blank to keep saved token">
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Save IPinfo</button>
        <button class="btn btn-secondary" formaction="{{ route('settings.test', 'ipinfo') }}" formmethod="post" type="submit">Test connection</button>
      </div>
    </form>
    @if (!empty($rows['ipinfo']?->last_test_message))
    <p class="test-message state-{{ $providerState('ipinfo') }}">Last test: {{ $rows['ipinfo']->last_test_message }} <span class="muted">({{ optional($rows['ipinfo']->updated_at)->diffForHumans() }})</span></p>
    @endif
    <div class="notice" style="margin-top:16px">When a Calendly call arrives, the lead's geo is read automatically with this token. No manual step needed.</div>
  </div>
</div>

{{-- ============ Multilogin ============ --}}
<div class="tab-panel" data-tab-panel="multilogin" hidden>
  <div class="notice"><strong>Multilogin setup:</strong> paste the Automation Token and click <b>Connect &amp; Discover</b>. The CRM will load your workspace, folders, profiles/templates, and existing 001–999 numbers. This global token is used for Profile Numbers and profile creation when a company has no token of its own.</div>

  <div class="panel integration-card wide">
    <div class="integration-head"><div class="integration-icon mlx">M</div><div><h2>Multilogin Automatic Setup</h2><p>Token + Workspace ID discover folders and profiles</p></div>
      <span class="status-light state-{{ $providerState('multilogin') }}" title="{{ $rows['multilogin']->last_test_message ?? 'Not tested yet' }}">
        <span class="dot"></span>{{ $stateText[$providerState('multilogin')] }}
      </span>
    </div>

    <form method="post" action="{{ route('settings.multilogin.connect') }}" class="connect-box">
      @csrf
      <div class="form-grid">
        <div><label>Automation token <span>{{ $masked($multilogin['automation_token'] ?? '') }}</span></label><input type="password" name="automation_token" placeholder="Paste Workspace Automation Token"></div>
        <div><label>Workspace ID</label><input name="workspace_id" value="{{ $multilogin['workspace_id'] ?? '' }}" placeholder="Copy from Multilogin Account settings → Info"></div>
        <div><label>API base URL</label><input name="base_url" value="{{ $multilogin['base_url'] ?? 'https://api.multilogin.com' }}"></div>
      </div>
      <div class="workspace-help"><strong>Where is it?</strong> Multilogin → Account settings → Info → copy Workspace ID. It is also the Default folder ID.</div>
      <label class="switch-row">
        <input type="checkbox" name="simulation_mode" @checked($simOn)>
        <span class="switch"></span><div><strong>Simulation Mode</strong><small>Test discovery and creation locally without real API calls.</small></div>
      </label>
      <button class="btn btn-primary connect-btn" type="submit">⚡ Connect &amp; Load Folders / Profiles</button>
    </form>

    @if (!empty($discovery['connected']))
    <div class="discovery-summary">
      <div><strong>{{ count($discovery['workspaces'] ?? []) }}</strong><span>Workspaces</span></div>
      <div><strong>{{ count($discovery['folders'] ?? []) }}</strong><span>Folders</span></div>
      <div><strong>{{ $discovery['numbering']['profiles_scanned'] ?? count($discovery['profiles'] ?? []) }}</strong><span>All profiles scanned</span></div>
      <div><strong>{{ isset($discovery['numbering']['next_free']) ? sprintf('%03d', $discovery['numbering']['next_free']) : '—' }}</strong><span>Next free number</span></div>
    </div>
    @endif

    @if (!empty($discovery['numbering']))
    <div class="numbering-result">
      <div><span>API total / loaded</span><strong>{{ $discovery['numbering']['reported_total'] ?? '?' }} / {{ $discovery['numbering']['profiles_scanned'] ?? 0 }}</strong></div>
      <div><span>Pages requested</span><strong>{{ $discovery['numbering']['pages_requested'] ?? '—' }}</strong></div>
      <div><span>Inventory status</span><strong>{{ !empty($discovery['numbering']['complete']) ? 'COMPLETE' : 'CHECK' }}</strong></div>
      <div><span>Numbered profiles</span><strong>{{ $discovery['numbering']['numbers_used'] ?? 0 }}</strong></div>
      <div><span>Highest used</span><strong>{{ !empty($discovery['numbering']['highest_used']) ? sprintf('%03d', $discovery['numbering']['highest_used']) : '—' }}</strong></div>
      <div><span>Next free</span><strong>{{ isset($discovery['numbering']['next_free']) ? sprintf('%03d', $discovery['numbering']['next_free']) : 'FULL' }}</strong></div>
    </div>
    @endif

    @if (!empty($discovery['folder_warning']))
    <div class="folder-warning">
      <strong>Folder auto-list unavailable for this token.</strong>
      The CRM is connected and profile discovery succeeded. The Default folder uses the Workspace ID.
      For other folders, paste their IDs below.
    </div>
    @endif

    <form method="post" action="{{ route('settings.store') }}">
      @csrf
      <input type="hidden" name="provider" value="multilogin">
      <input type="hidden" name="base_url" value="{{ $multilogin['base_url'] ?? 'https://api.multilogin.com' }}">
      <input type="hidden" name="simulation_mode" value="{{ $simOn ? 'on' : '' }}">
      <input type="hidden" name="workspaces_endpoint" value="{{ $multilogin['workspaces_endpoint'] ?? '' }}">
      <input type="hidden" name="folders_endpoint" value="{{ $multilogin['folders_endpoint'] ?? '' }}">
      <input type="hidden" name="profile_search_endpoint" value="{{ $multilogin['profile_search_endpoint'] ?? '/profile/search' }}">
      <input type="hidden" name="profile_create_endpoint" value="{{ $multilogin['profile_create_endpoint'] ?? '' }}">
      <input type="hidden" name="profile_clone_endpoint" value="{{ $multilogin['profile_clone_endpoint'] ?? '/profile/clone' }}">
      <input type="hidden" name="browser_type" value="{{ $multilogin['browser_type'] ?? 'mimic' }}">
      <input type="hidden" name="os_type" value="{{ $multilogin['os_type'] ?? 'windows' }}">

      <div class="form-grid discovery-fields">
        <div>
          <label>Workspace</label>
          <select name="workspace_id">
            @forelse (($discovery['workspaces'] ?? []) as $item)
            <option value="{{ $item['id'] }}" @selected(($item['id'] ?? '') == ($multilogin['workspace_id'] ?? ''))>{{ $item['name'] }}</option>
            @empty
            <option value="{{ $multilogin['workspace_id'] ?? '' }}">Connect token first</option>
            @endforelse
          </select>
        </div>
        <div>
          <label>GEO folder ID</label>
          <input name="geo_folder_id" list="multilogin-folders" value="{{ $multilogin['geo_folder_id'] ?? $multilogin['workspace_id'] ?? '' }}" placeholder="Workspace ID = Default folder">
        </div>
        <div>
          <label>STATIC folder ID</label>
          <input name="static_folder_id" list="multilogin-folders" value="{{ $multilogin['static_folder_id'] ?? $multilogin['workspace_id'] ?? '' }}" placeholder="Paste folder ID or use Workspace ID">
        </div>
        <datalist id="multilogin-folders">
          @foreach (($discovery['folders'] ?? []) as $item)
          <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
          @endforeach
        </datalist>
        <div>
          <label>Multilogin proxy type</label>
          <select name="multilogin_proxy_type">
            <option value="residential" @selected(($multilogin['multilogin_proxy_type'] ?? 'residential') === 'residential')>Residential</option>
            <option value="mobile" @selected(($multilogin['multilogin_proxy_type'] ?? '') === 'mobile')>Mobile</option>
          </select>
        </div>
        <div>
          <label>Protocol</label>
          <select name="multilogin_proxy_protocol">
            <option value="http" @selected(($multilogin['multilogin_proxy_protocol'] ?? 'http') === 'http')>HTTP</option>
            <option value="socks5" @selected(($multilogin['multilogin_proxy_protocol'] ?? '') === 'socks5')>SOCKS5</option>
          </select>
        </div>
        <div>
          <label>Session type</label>
          <select name="multilogin_proxy_session_type">
            <option value="sticky" @selected(($multilogin['multilogin_proxy_session_type'] ?? 'sticky') === 'sticky')>Sticky</option>
            <option value="rotating" @selected(($multilogin['multilogin_proxy_session_type'] ?? '') === 'rotating')>Rotating</option>
          </select>
        </div>
        <div>
          <label>IP TTL seconds</label>
          <input type="number" min="0" max="86400" name="multilogin_proxy_ip_ttl" value="{{ $multilogin['multilogin_proxy_ip_ttl'] ?? '0' }}">
          <small>Used for rotating sessions. Maximum 86,400.</small>
        </div>
        <div class="wide-field">
          <label>Generate Proxy endpoint</label>
          <input name="proxy_generate_endpoint" value="{{ $multilogin['proxy_generate_endpoint'] ?? 'https://profile-proxy.multilogin.com/v1/proxy/connection_url' }}">
          <small>Official endpoint from your supplied Multilogin API documentation.</small>
        </div>
        <div>
          <label class="switch-row">
            <input type="checkbox" name="multilogin_proxy_strict_mode" @checked($strictOn)>
            <span class="switch"></span>
            <div><strong>Strict mode</strong><small>Send every proxy field explicitly.</small></div>
          </label>
        </div>
        <div>
          <label>Template name filter</label>
          <input name="template_name_filter" value="{{ $multilogin['template_name_filter'] ?? 'template' }}" placeholder="Example: TEMPLATE">
          <small>Only profiles matching this word or marked as templates appear below.</small>
        </div>
        <div>
          <label>STATIC template profile</label>
          <select name="template_profile_id">
            <option value="">Select a template only</option>
            @foreach (($discovery['templates'] ?? []) as $item)
            <option value="{{ $item['id'] }}" @selected(($item['id'] ?? '') == ($multilogin['template_profile_id'] ?? ''))>{{ $item['name'] }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Save workspace, folder IDs &amp; template</button>
        <button class="btn btn-secondary" formaction="{{ route('numbers.sync') }}" formmethod="post" type="submit">Sync profile numbers</button>
      </div>
    </form>

    <details class="advanced-details">
      <summary>Advanced API compatibility settings</summary>
      <div class="endpoint-list">
        @foreach (($discovery['endpoints'] ?? []) as $key => $value)
          <code>{{ $key }} = {{ $value }}</code>
        @endforeach
      </div>
      <p>The connector tests compatible API paths and remembers the first Profile Create path accepted by your deployment.</p>
    </details>
  </div>
</div>

{{-- ============ Sync & Status ============ --}}
<div class="tab-panel" data-tab-panel="sync" hidden>
  <div class="panel">
    <div class="panel-head"><div><h2>How the sync works</h2><p>End-to-end automation pipeline</p></div></div>
    <div class="sync-flow big">
      <span class="sync-step"><b>1</b> Calendly call arrives<small>Webhook or scheduled sync (every 15 min)</small></span>
      <i>→</i>
      <span class="sync-step"><b>2</b> IPinfo reads lead geo<small>City, region, ISP resolved from the lead's IP</small></span>
      <i>→</i>
      <span class="sync-step"><b>3</b> Build Multilogin profile<small>GEO uses a matched proxy; STATIC uses your pool</small></span>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><div><h2>Service status</h2><p>Live connectivity for every token</p></div></div>
    <div class="status-table">
      @foreach ([['ipinfo','IPinfo','api_token'],['multilogin','Multilogin','automation_token']] as [$prov,$label,$tok])
        @php $state = $providerState($prov); $row = $rows[$prov] ?? null; @endphp
        <div class="status-row state-{{ $state }}">
          <span class="status-light state-{{ $state }}"><span class="dot"></span>{{ $stateText[$state] }}</span>
          <div class="status-row-main">
            <strong>{{ $label }}</strong>
            <small>{{ $row?->last_test_message ?: 'Not tested yet — click Test connection.' }}</small>
          </div>
          <span class="muted">{{ optional($row?->updated_at)->diffForHumans() ?? '—' }}</span>
        </div>
      @endforeach
      <div class="status-row state-info">
        <span class="status-light state-up"><span class="dot"></span>Per company</span>
        <div class="status-row-main">
          <strong>Calendly</strong>
          <small>Configured on each company (token + org URI). Manage under <a href="{{ route('companies.index') }}">Companies</a>.</small>
        </div>
        <span class="muted">—</span>
      </div>
    </div>
  </div>
</div>
@endsection
