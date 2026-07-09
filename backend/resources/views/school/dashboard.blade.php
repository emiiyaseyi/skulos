@extends('layouts.app', ['accent' => $accent, 'homeUrl' => route('school.dashboard', ['school' => $school->slug])])
@section('title', $school->name.' Dashboard')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $school->name }}</h1>
    <p class="subtitle">Welcome back, {{ auth()->user()->name }}.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.branding.edit', ['school' => $school->slug]) }}">School branding</a>
</div>

<div class="grid cols-3" style="margin-bottom:1.5rem">
  <div class="card stat"><b>{{ $stats['students'] }}</b><span>Students</span></div>
  <div class="card stat"><b>{{ $stats['staff'] }}</b><span>Staff</span></div>
  <div class="card stat"><b>{{ $stats['events'] }}</b><span>Calendar Events</span></div>
</div>

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <div class="row-between" style="margin-bottom:.6rem">
      <h3>Notifications</h3>
      @if($feed['unread'] > 0)<span class="badge badge-warn">{{ $feed['unread'] }} new</span>@endif
    </div>
    @forelse($feed['items'] as $n)
      <div class="notif">
        <span class="dot {{ $n->read_at ? 'read' : '' }}"></span>
        <div style="flex:1">
          <b>{{ $n->title }}</b>
          @if($n->body)<div class="muted" style="font-size:.85rem">{{ \Illuminate\Support\Str::limit($n->body, 120) }}</div>@endif
          <small>{{ $n->created_at->diffForHumans() }}</small>
        </div>
        @unless($n->read_at)
          <form method="POST" action="{{ route('school.notifications.read', ['school' => $school->slug, 'id' => $n->id]) }}">
            @csrf<button class="btn btn-ghost btn-sm">Mark read</button>
          </form>
        @endunless
      </div>
    @empty
      <p class="muted">You have no notifications yet. School announcements will appear here.</p>
    @endforelse
  </div>

  <div class="card">
    <h3>Upcoming on the calendar</h3>
    <p style="margin-bottom:.8rem">Notable days and events for your school.</p>
    @forelse($upcoming as $event)
      <div class="notif">
        <span class="dot"></span>
        <div style="flex:1"><b>{{ $event->title }}</b><small> {{ $event->starts_on?->toFormattedDateString() }}</small></div>
        <span class="badge badge-ok">{{ ucfirst($event->category) }}</span>
      </div>
    @empty
      <p class="muted">No dated events yet. Your calendar was seeded with default notable days you can date in settings.</p>
    @endforelse
  </div>
</div>
@endsection
