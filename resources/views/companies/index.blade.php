@extends('layouts.app')

@section('title', 'Companies')
@section('page_title', 'Companies')
@section('page_subtitle', 'Each company owns its Lead API, Calendly, and Multilogin configuration')

@section('content')
<div class="form-actions" style="margin-bottom:1rem">
  <a class="btn btn-primary" href="{{ route('companies.create') }}">＋ Add company</a>
</div>

<div class="card-grid">
@forelse ($companies as $company)
  <div class="company-card">
    <div class="company-card-head">
      <div class="client-avatar">{{ mb_strtoupper(mb_substr($company->name, 0, 1)) }}</div>
      <div>
        <h3>{{ $company->name }}</h3>
        <p>{{ $company->slug }} · {{ $company->enabled ? 'Enabled' : 'Disabled' }}</p>
      </div>
    </div>

    <div class="svc-status-row">
      @foreach ([['lead','Lead'],['calendly','Calendly'],['multilogin','Multilogin']] as [$svc,$label])
        @php $st = $company->serviceState($svc); @endphp
        <span class="svc-status state-{{ $st }}" title="{{ $company->serviceMessage($svc) ?: 'Not tested' }}"><span class="dot"></span>{{ $label }}</span>
      @endforeach
    </div>

    <div class="company-keys">
      <span>Lead key {{ $masked($company->getLeadApiKey()) }}</span>
      <span>Calendly {{ $masked($company->getCalendlyApiToken()) }}</span>
      <span>Multilogin {{ $masked($company->getMultiloginToken()) }}</span>
    </div>

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
@empty
  <div class="empty-card">No companies yet.</div>
@endforelse
</div>
@endsection
