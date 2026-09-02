@extends('layouts.app')

@section('title', 'Static Proxies')
@section('page_title', 'Static Proxies')
@section('page_subtitle', 'Pool of proxies assigned randomly when creating STATIC Multilogin profiles')

@section('content')
<div class="stat-grid three">
  <div class="stat-card"><span>Total</span><strong>{{ $proxies->count() }}</strong><small>Configured proxies</small></div>
  <div class="stat-card"><span>Enabled</span><strong>{{ $proxies->where('enabled', true)->count() }}</strong><small>Available for random pick</small></div>
  <div class="stat-card"><span>Disabled</span><strong>{{ $proxies->where('enabled', false)->count() }}</strong><small>Excluded from pool</small></div>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Add proxy</h2><p>New entries join the random pool when enabled</p></div>
  </div>
  <form method="post" action="{{ route('static-proxies.store') }}">
    @csrf
    <div class="form-grid">
      <div>
        <label>Label</label>
        <input name="label" value="{{ old('label') }}" placeholder="Optional name">
      </div>
      <div>
        <label>Host</label>
        <input name="host" value="{{ old('host') }}" required placeholder="proxy.example.com">
      </div>
      <div>
        <label>Port</label>
        <input type="number" name="port" value="{{ old('port', 8080) }}" min="1" max="65535" required>
      </div>
      <div>
        <label>Protocol</label>
        <select name="protocol">
          <option value="http" @selected(old('protocol', 'http') === 'http')>HTTP</option>
          <option value="socks5" @selected(old('protocol') === 'socks5')>SOCKS5</option>
        </select>
      </div>
      <div>
        <label>Username</label>
        <input name="username" value="{{ old('username') }}" placeholder="Optional">
      </div>
      <div>
        <label>Password</label>
        <input type="password" name="password" placeholder="Optional">
      </div>
    </div>
    <label class="switch-row">
      <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))>
      <span class="switch"></span>
      <div><strong>Enabled</strong><small>Include in the random STATIC proxy pool</small></div>
    </label>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Add proxy</button>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-head">
    <div><h2>Proxy pool</h2><p>Edit entries inline or toggle enabled status</p></div>
  </div>
  @foreach ($proxies as $proxy)
    @php $formId = 'proxy-update-'.$proxy->id; @endphp
    <form id="{{ $formId }}" method="post" action="{{ route('static-proxies.update', $proxy) }}" hidden>
      @csrf
      @method('PUT')
    </form>
  @endforeach
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Label</th>
          <th>Host</th>
          <th>Port</th>
          <th>Protocol</th>
          <th>Username</th>
          <th>Password</th>
          <th>Enabled</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      @forelse ($proxies as $proxy)
      @php $formId = 'proxy-update-'.$proxy->id; @endphp
      <tr>
        <td><input form="{{ $formId }}" name="label" value="{{ $proxy->label }}" placeholder="—"></td>
        <td><input form="{{ $formId }}" name="host" value="{{ $proxy->host }}" required></td>
        <td><input form="{{ $formId }}" type="number" name="port" value="{{ $proxy->port }}" min="1" max="65535" required style="width:90px"></td>
        <td>
          <select form="{{ $formId }}" name="protocol">
            <option value="http" @selected($proxy->protocol === 'http')>HTTP</option>
            <option value="socks5" @selected($proxy->protocol === 'socks5')>SOCKS5</option>
          </select>
        </td>
        <td><input form="{{ $formId }}" name="username" value="{{ $proxy->username }}" placeholder="—"></td>
        <td><input form="{{ $formId }}" type="password" name="password" placeholder="{{ $proxy->password ? '••••••••' : '—' }}"></td>
        <td>
          <label class="switch-row" style="margin:0;padding:8px 10px">
            <input form="{{ $formId }}" type="checkbox" name="enabled" value="1" @checked($proxy->enabled)>
            <span class="switch"></span>
          </label>
        </td>
        <td style="white-space:nowrap">
          <button class="btn btn-secondary" type="submit" form="{{ $formId }}">Save</button>
          <form method="post" action="{{ route('static-proxies.destroy', $proxy) }}" style="display:inline;margin-left:6px" onsubmit="return confirm('Delete this proxy?');">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
      </tr>
      @empty
      <tr><td colspan="8" class="empty">No static proxies yet. Add one above.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
