@extends('layouts.app')

@section('title', 'Audit Logs')
@section('page_title', 'Audit Logs')
@section('page_subtitle', 'A trace of settings, webhooks, profile creation, and errors')

@section('content')
<div class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Time</th><th>Action</th><th>Details</th></tr></thead>
      <tbody>
      @forelse ($logs as $l)
      <tr><td>{{ $l->created_at?->format('d.m.Y H:i:s') }}</td><td><strong>{{ $l->action }}</strong></td><td>{{ $l->details }}</td></tr>
      @empty
      <tr><td colspan="3" class="empty">No logs yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
