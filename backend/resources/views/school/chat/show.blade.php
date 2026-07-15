@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Chat'])
@section('title', 'Chat with '.($other->name ?? 'Unknown'))

@push('head')
<style>
  .chat-scroll{max-height:60vh;overflow-y:auto;display:flex;flex-direction:column;gap:.6rem;padding:.2rem .2rem 1rem}
  .chat-bubble{max-width:26rem;padding:.55rem .8rem;border-radius:14px;font-size:.92rem;line-height:1.45}
  .chat-bubble.mine{align-self:flex-end;background:var(--accent);color:var(--accent-ink);border-bottom-right-radius:4px}
  .chat-bubble.theirs{align-self:flex-start;background:var(--soft);color:var(--ink);border-bottom-left-radius:4px}
  .chat-bubble img{max-width:100%;border-radius:8px;margin-top:.4rem;display:block}
  .chat-meta{font-size:.7rem;opacity:.7;margin-top:.25rem}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $other->name ?? 'Unknown' }}</h1>
    <p class="subtitle">About {{ $thread->student?->fullName() ?? 'a student' }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.chat.index', ['school' => $school->slug]) }}">Back to Chat</a>
</div>

<div class="card">
  @if($messages->isEmpty())
    <p class="muted">No messages yet. Say hello.</p>
  @else
    <div class="chat-scroll">
      @foreach($messages as $m)
        @php($mine = $m->sender_user_id === auth()->id())
        <div class="chat-bubble {{ $mine ? 'mine' : 'theirs' }}">
          @if($m->body)<div>{{ $m->body }}</div>@endif
          @if($m->image_path)<img src="{{ $m->imageUrl() }}" alt="" />@endif
          <div class="chat-meta">{{ $m->created_at->format('d M, g:ia') }}</div>
        </div>
      @endforeach
    </div>
  @endif

  @if($canSend)
    <form method="POST" action="{{ route('school.chat.messages.store', ['school' => $school->slug, 'threadId' => $thread->id]) }}" enctype="multipart/form-data" style="margin-top:1rem;border-top:1px solid var(--line);padding-top:1rem">
      @csrf
      @if($errors->any())
        <div class="flash flash-bad" style="margin-bottom:.8rem">{{ $errors->first() }}</div>
      @endif
      <div class="field">
        <textarea name="body" rows="2" maxlength="4000" placeholder="Write a message&hellip;">{{ old('body') }}</textarea>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap">
        <input name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="max-width:14rem" />
        <button class="btn btn-primary btn-sm" type="submit">Send</button>
      </div>
    </form>
  @else
    <p class="muted" style="margin-top:1rem;border-top:1px solid var(--line);padding-top:1rem">Messaging teachers to parents has been turned off by your school admin.</p>
  @endif
</div>
@endsection
