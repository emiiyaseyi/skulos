@extends('layouts.app', ['accent' => $accent, 'homeUrl' => route('school.dashboard', ['school' => $school->slug])])
@section('title', $school->name.' Dashboard')

@php
  // Widget partials rendered by the chosen layout, honouring visibility.
  $show = fn($w) => ($widgets[$w] ?? false);
@endphp

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $school->name }}</h1>
    @if($show('greeting'))<p class="subtitle">Welcome back, {{ auth()->user()->name }}. Here is what is happening today.</p>@endif
  </div>
  <div class="stack" style="flex-direction:row;gap:.5rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard.edit', ['school' => $school->slug]) }}">Dashboard look</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.branding.edit', ['school' => $school->slug]) }}">Branding</a>
  </div>
</div>

@php
  $stats = view('school.widgets.stats', ['stats' => $stats])->render();
  $notes = view('school.widgets.notifications', ['feed' => $feed, 'school' => $school])->render();
  $cal = view('school.widgets.calendar', ['upcoming' => $upcoming])->render();
@endphp

@switch($layout)
  @case('focus')
    @if($show('stats'))<div class="grid cols-4" style="margin-bottom:1.25rem">{!! $stats !!}</div>@endif
    <div class="grid" style="gap:1.1rem">
      @if($show('notifications')){!! $notes !!}@endif
      @if($show('calendar')){!! $cal !!}@endif
    </div>
    @break

  @case('analytics')
    @if($show('stats'))<div class="grid cols-3" style="margin-bottom:1.25rem">{!! $stats !!}</div>@endif
    <div class="grid cols-2" style="align-items:start">
      @if($show('calendar')){!! $cal !!}@endif
      @if($show('notifications')){!! $notes !!}@endif
    </div>
    @break

  @case('compact')
    <div class="grid" style="gap:1rem;max-width:38rem">
      @if($show('stats'))<div class="grid cols-3">{!! $stats !!}</div>@endif
      @if($show('notifications')){!! $notes !!}@endif
      @if($show('calendar')){!! $cal !!}@endif
    </div>
    @break

  @default {{-- classic --}}
    @if($show('stats'))<div class="grid cols-3" style="margin-bottom:1.5rem">{!! $stats !!}</div>@endif
    <div class="grid cols-2" style="align-items:start">
      @if($show('notifications')){!! $notes !!}@endif
      @if($show('calendar')){!! $cal !!}@endif
    </div>
@endswitch
@endsection
