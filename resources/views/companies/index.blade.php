@extends('layouts.app')

@section('title', 'Companies')
@section('page_title', 'Companies')
@section('page_subtitle', 'Each company has its own lead API, Calendly token, and Multilogin token')

@section('content')
<div class="form-actions" style="margin-bottom:1rem">
  <a class="btn btn-primary" href="{{ route('companies.create') }}">Add company</a>
</div>

<div class="card-grid">
@forelse ($companies as $company)
  <div class="person-card">
    <div class="client-avatar">{{ mb_strtoupper(mb_substr($company->name, 0, 1)) }}</div>
    <div>
      <h3>{{ $company->name }}</h3>
      <p>{{ $company->slug }} · {{ $company->enabled ? 'Enabled' : 'Disabled' }}</p>
      <small>
        Lead API URL {{ $company->lead_api_url ? 'set' : 'missing' }} ·
        key {{ $masked($company->getLeadApiKey()) }} ·
        Calendly {{ $masked($company->getCalendlyApiToken()) }} ·
        Multilogin {{ $masked($company->getMultiloginToken()) }}
      </small>
      <div class="form-actions" style="margin-top:.75rem">
        <a class="btn btn-secondary" href="{{ route('companies.edit', $company) }}">Edit</a>
        <form method="post" action="{{ route('companies.sync', $company) }}" style="display:inline">
          @csrf
          <button class="btn btn-primary" type="submit">Sync now</button>
        </form>
        <form method="post" action="{{ route('companies.destroy', $company) }}" style="display:inline" onsubmit="return confirm('Delete {{ $company->name }}? Related leads/calls for this company will also be removed.');">
          @csrf
          @method('DELETE')
          <button class="btn btn-danger" type="submit">Delete</button>
        </form>
      </div>
    </div>
  </div>
@empty
  <div class="empty-card">No companies yet.</div>
@endforelse
</div>
@endsection
