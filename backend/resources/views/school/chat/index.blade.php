@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Chat'])
@section('title', 'Chat')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Chat</h1>
    <p class="subtitle">Private conversations with a teacher or parent about a specific student.</p>
  </div>
</div>

<div class="card">
  @if($threads->isEmpty())
    <p class="muted">No conversations yet. Start one from a student's page.</p>
  @else
    <div class="stack" style="gap:0">
      @foreach($threads as $row)
        <a href="{{ route('school.chat.show', ['school' => $school->slug, 'threadId' => $row['thread']->id]) }}"
           style="display:flex;justify-content:space-between;align-items:center;padding:.9rem 0;border-bottom:1px solid var(--line);text-decoration:none;color:inherit">
          <div>
            <strong>{{ $row['other']->name ?? 'Unknown' }}</strong>
            <div class="muted" style="font-size:.82rem">About {{ $row['thread']->student?->fullName() ?? 'a student' }}</div>
          </div>
          <div style="display:flex;align-items:center;gap:.6rem">
            @if($row['unread'] > 0)
              <span class="badge badge-warn">{{ $row['unread'] }} new</span>
            @endif
            <span class="muted" style="font-size:.78rem;white-space:nowrap">{{ $row['thread']->last_message_at?->diffForHumans() ?? '' }}</span>
          </div>
        </a>
      @endforeach
    </div>
  @endif
</div>
@endsection
