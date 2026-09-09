@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Messages'])
@section('title', "Messages - {$applicant->name}")

@push('head')
<style>
  .msg-thread{display:flex;flex-direction:column;gap:.7rem;margin-bottom:1.2rem;max-width:38rem}
  .msg-bubble{border-radius:12px;padding:.7rem .9rem;max-width:80%}
  .msg-bubble .who{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.25rem;opacity:.7}
  .msg-bubble .when{font-size:.7rem;opacity:.55;margin-top:.3rem}
  .msg-staff{align-self:flex-end;background:var(--accent);color:var(--accent-ink)}
  .msg-applicant{align-self:flex-start;background:var(--soft)}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Messages with {{ $applicant->name }}</h1>
    <p class="subtitle">Reference {{ $applicant->admission_number }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.pipeline', ['school' => $school->slug]) }}">Back to pipeline</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($thread->isEmpty())
    <p class="muted" style="margin-bottom:1.2rem">No messages yet.</p>
  @else
    <div class="msg-thread">
      @foreach($thread as $message)
        <div class="msg-bubble {{ $message->sender === 'staff' ? 'msg-staff' : 'msg-applicant' }}">
          <div class="who">{{ $message->sender === 'staff' ? ($senderNames[$message->sender_user_id] ?? 'School staff') : $applicant->name }}</div>
          <div style="white-space:pre-wrap">{{ $message->body }}</div>
          <div class="when">{{ $message->created_at->format('d M Y, g:ia') }}</div>
        </div>
      @endforeach
    </div>
  @endif

  @if(! $applicant->email)
    <p class="muted" style="margin-bottom:.6rem">This applicant has no email on file, so they will only see a new message the next time they sign in.</p>
  @endif

  <form method="POST" action="{{ route('school.admissions.pipeline.messages.send', ['school' => $school->slug, 'applicantId' => $applicant->id]) }}" style="max-width:38rem">
    @csrf
    <div class="field">
      <label for="body">Send a message</label>
      <textarea id="body" name="body" rows="3" maxlength="2000" required></textarea>
      @error('body')<div class="err">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Send</button>
  </form>
</div>
@endsection
