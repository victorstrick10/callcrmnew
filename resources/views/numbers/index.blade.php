@extends('layouts.app')

@section('title', 'Profile Numbers')
@section('page_title', 'Profile Number Manager')
@section('page_subtitle', 'Per-company allocation from 001 through 999')

@section('content')
<form method="get" action="{{ route('numbers.index') }}" class="panel filters-bar" style="padding:16px;margin-bottom:16px">
  <div class="form-grid" style="align-items:end">
    <div>
      <label for="company_id">Company</label>
      <select name="company_id" id="company_id" required onchange="this.form.submit()">
        <option value="">Select a company…</option>
        @foreach ($companies as $c)
          <option value="{{ $c->id }}" @selected((int) ($companyId ?? 0) === (int) $c->id)>{{ $c->name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <button class="btn btn-secondary" type="submit">Filter</button>
    </div>
  </div>
</form>

@if (! $company)
<div class="panel">
  <div class="panel-head">
    <div>
      <h2>Select a company</h2>
      <p>Each company has its own Multilogin account and number pool. Choose a company to view numbers, sync, or rename profiles.</p>
    </div>
  </div>
</div>
@else
<div class="stat-grid three">
  <div class="stat-card"><span>Available</span><strong>{{ $availableCount }}</strong><small>Unused in {{ $company->name }}</small></div>
  <div class="stat-card"><span>Used or reserved</span><strong>{{ $used->count() }}</strong><small>Protected from duplication</small></div>
  <div class="stat-card"><span>Next numbers</span><strong>{{ $nextNumbersLabel }}</strong><small>Lowest free gaps first</small></div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Refresh from Multilogin</h2><p>Re-reads <strong>{{ $company->name }}</strong>'s Multilogin workspace: marks used numbers, frees numbers whose profile was deleted, and flags deleted profiles in the CRM</p></div>
    <form method="post" action="{{ route('numbers.sync') }}">
      @csrf
      <input type="hidden" name="company_id" value="{{ $company->id }}">
      <button class="btn btn-primary" type="submit">↻ Refresh from Multilogin</button>
    </form>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div><h2>Reserved and used numbers</h2><p>Saving a name updates Multilogin. Changing the leading digits (e.g. 159 → 007) also remaps the CRM number if the target is free.</p></div></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Number</th>
          <th>Status</th>
          <th>Type</th>
          <th>Appointment</th>
          <th>Multilogin ID</th>
          <th>Profile name</th>
          <th>Reserved</th>
        </tr>
      </thead>
      <tbody>
      @forelse ($used as $n)
      <tr>
        <td><span class="number-pill">{{ $formatNumber($n->number) }}</span></td>
        <td><span class="badge badge-{{ $n->status }}">{{ $n->status }}</span></td>
        <td>{{ $n->profile_type ?: '—' }}</td>
        <td>{{ $n->appointment_id ?: '—' }}</td>
        <td><code>{{ $n->multilogin_profile_id ?: '—' }}</code></td>
        <td>
          @if ($n->multilogin_profile_id)
            <form method="post" action="{{ route('numbers.update', $n) }}" class="inline-rename" style="display:flex;gap:8px;align-items:center;min-width:280px">
              @csrf
              @method('PUT')
              <input type="text" name="profile_name" value="{{ $n->profile_name }}" required maxlength="500" style="flex:1;min-width:160px">
              <button class="btn btn-secondary" type="submit">Save</button>
            </form>
          @else
            {{ $n->profile_name ?: '—' }}
          @endif
        </td>
        <td>{{ $n->reserved_at ? $n->reserved_at->format('d.m.Y H:i') : '—' }}</td>
      </tr>
      @empty
      <tr><td colspan="7" class="empty">No numbers used yet for this company. Run Sync to import Multilogin profiles.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endif
@endsection
