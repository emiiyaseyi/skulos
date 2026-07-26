@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Chat'])
@section('title', 'Chat')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Chat</h1>
    <p class="subtitle">Private conversations with a teacher or parent about a specific student.</p>
  </div>
</div>

{{--
  Start a new conversation right here (user report 2026-07-25: this
  screen's own empty state said "Start one from a student's page," but
  offered no way to do it from this screen itself). $starters is one
  entry per student this user could message someone about, each carrying
  the real people on the other side (guardians for a teacher, class/
  assistant teacher for a guardian) — see ChatController::
  conversationStarters(). Empty when messaging is turned off (teacher
  side only) or there is genuinely no one to message yet.
--}}
@if(count($starters))
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.6rem">Start a new conversation</h3>
    <form method="GET" action="{{ route('school.chat.start', ['school' => $school->slug]) }}" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">
      <div class="field" style="margin:0;min-width:14rem;flex:1">
        <label for="starterStudent">About</label>
        <select id="starterStudent" name="student_id" onchange="skulosChatRefreshTargets()">
          @foreach($starters as $row)
            <option value="{{ $row['student']->id }}" data-targets="{{ $row['targets']->map(fn($u) => ['id' => $u->id, 'name' => $u->name])->values()->toJson() }}">{{ $row['student']->fullName() }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" style="margin:0;min-width:14rem;flex:1">
        <label for="starterTarget">Message</label>
        <select id="starterTarget" name="user_id"></select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Start</button>
    </form>
  </div>
  <script>
    function skulosChatRefreshTargets(){
      var studentSelect = document.getElementById('starterStudent');
      var targetSelect = document.getElementById('starterTarget');
      var opt = studentSelect.options[studentSelect.selectedIndex];
      var targets = opt ? JSON.parse(opt.dataset.targets || '[]') : [];
      targetSelect.innerHTML = targets.map(function(t){ return '<option value="'+t.id+'">'+t.name+'</option>'; }).join('');
      targetSelect.dispatchEvent(new Event('change'));
    }
    document.addEventListener('DOMContentLoaded', skulosChatRefreshTargets);
  </script>
@endif

<div class="card">
  @if($threads->isEmpty())
    <p class="muted">No conversations yet{{ count($starters) ? '' : ", start one from a student's page" }}.</p>
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
