@extends('layouts.app')

@section('title', $company->exists ? 'Edit company' : 'New company')
@section('page_title', $company->exists ? $company->name : 'New company')
@section('page_subtitle', 'Lead API + Calendly PAT + Multilogin token for this tenant')

@section('content')
<div class="settings-grid">
  <div class="panel integration-card wide">
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

      <div class="form-grid">
        <div>
          <label>Name</label>
          <input name="name" value="{{ old('name', $company->name) }}" required>
        </div>
        <div>
          <label>Slug</label>
          <input name="slug" value="{{ old('slug', $company->slug) }}" required>
        </div>
        <div>
          <label>Lead API URL</label>
          <input name="lead_api_url" value="{{ old('lead_api_url', $company->lead_api_url) }}" placeholder="https://diligentplacers.com/api.php">
        </div>
        <div>
          <label>Lead API key <span>{{ $masked($company->exists ? $company->getLeadApiKey() : null) }}</span></label>
          <input type="password" name="lead_api_key" placeholder="Leave blank to keep saved key">
        </div>
        <div>
          <label>Calendly personal token <span>{{ $masked($company->exists ? $company->getCalendlyApiToken() : null) }}</span></label>
          <input type="password" name="calendly_api_token" placeholder="Leave blank to keep saved token">
        </div>
        <div>
          <label>Calendly organization URI</label>
          <input name="calendly_org_uri" value="{{ old('calendly_org_uri', $company->calendly_org_uri) }}" placeholder="https://api.calendly.com/organizations/...">
          <small class="muted">Must be the API org URI from /users/me → current_organization (not calendly.com/…). Leave blank to auto-detect from the token.</small>
        </div>
        <div>
          <label>Calendly webhook signing key <span>{{ $masked($company->exists ? $company->getCalendlyWebhookSigningKey() : null) }}</span></label>
          <input type="password" name="calendly_webhook_signing_key" placeholder="Optional">
        </div>
        <div>
          <label>Multilogin token <span>{{ $masked($company->exists ? $company->getMultiloginToken() : null) }}</span></label>
          <input type="password" name="multilogin_token" placeholder="Leave blank to keep saved token">
        </div>
        <div>
          <label>Multilogin base URL</label>
          <input name="multilogin_base_url" value="{{ old('multilogin_base_url', $company->multilogin_base_url ?: 'https://api.multilogin.com') }}">
        </div>
      </div>

      <label class="switch-row" style="margin-top:1rem">
        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $company->enabled))>
        <span class="switch"></span>
        <div><strong>Enabled</strong><small>Include in leads:sync</small></div>
      </label>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Save company</button>
        <a class="btn btn-secondary" href="{{ route('companies.index') }}">Back</a>
      </div>
    </form>

    @if ($company->exists)
      <div class="form-actions" style="margin-top:1rem">
        <form method="post" action="{{ route('companies.test-lead-api', $company) }}">
          @csrf
          <button class="btn btn-secondary" type="submit">Test lead API</button>
        </form>
        <form method="post" action="{{ route('companies.test-calendly', $company) }}">
          @csrf
          <button class="btn btn-secondary" type="submit">Test Calendly</button>
        </form>
        <form method="post" action="{{ route('companies.sync', $company) }}">
          @csrf
          <button class="btn btn-primary" type="submit">Sync leads + Calendly</button>
        </form>
      </div>
    @endif
  </div>
</div>
@endsection
