@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $announcement->title])
@section('title', $announcement->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $announcement->title }}</h1>
    <p class="subtitle">Who this reached, and who has acknowledged it.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.announcements.history', ['school' => $school->slug]) }}">Back to Communication History</a>
</div>

@php($pending = $notifications->whereNull('read_at'))

<div class="card" style="margin-bottom:1.1rem">
  <div class="row-between" style="flex-wrap:wrap;gap:.6rem">
    <p class="muted" style="margin:0">{{ $notifications->count() - $pending->count() }} of {{ $notifications->count() }} have acknowledged.</p>
    @if($pending->isNotEmpty())
      <form method="POST" action="{{ route('school.announcements.history.nudge', ['school' => $school->slug, 'announcementId' => $announcement->id]) }}" onsubmit="return confirm('Send a reminder to everyone who hasn\'t acknowledged this yet?')">
        @csrf
        <button class="btn btn-primary btn-sm" type="submit">Nudge everyone pending ({{ $pending->count() }})</button>
      </form>
    @endif
  </div>
</div>

<div class="card">
  @if($notifications->isEmpty())
    <p class="muted">Nobody was delivered this.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Recipient</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
        @foreach($notifications as $n)
          <tr>
            <td><strong>{{ $n->user?->name ?? 'Removed user' }}</strong>@if($n->user?->email)<div class="muted" style="font-size:.8rem">{{ $n->user->email }}</div>@endif</td>
            <td>
              @if($n->read_at)
                <span class="badge badge-ok">Acknowledged</span>
              @else
                <span class="badge badge-warn">Pending</span>
              @endif
            </td>
            <td class="muted">{{ $n->read_at?->diffForHumans() ?? $n->created_at->diffForHumans() }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
