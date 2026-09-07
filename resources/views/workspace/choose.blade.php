@extends('layouts.app')

@section('title', 'Choose office')
@section('page_title', 'Choose an office')
@section('page_subtitle', 'Each office keeps its own calls, leads, numbers and stats — pick which one to open')

@section('content')
<div class="workspace-choose">
  @foreach ($trees as $slug => $label)
    <a class="workspace-card {{ $active === $slug ? 'is-active' : '' }}" href="{{ route('workspace.select', $slug) }}">
      <div class="workspace-card-badge">{{ $label }}</div>
      <h2>{{ $label }}</h2>
      <p class="muted">
        @if (!empty($companiesByTree[$slug]))
          {{ implode(' · ', $companiesByTree[$slug]) }}
        @else
          No companies yet
        @endif
      </p>
      <span class="btn btn-primary">Open {{ $label }} →</span>
    </a>
  @endforeach
</div>
<p class="muted" style="margin-top:18px">You can switch office anytime from the left sidebar. Assign a company's office under <a class="text-link" href="{{ route('companies.index') }}">Companies</a> → Edit.</p>
@endsection
