@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Mail Settings'])
@section('title', 'Mail Settings')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Mail settings</h1>
    <p class="subtitle">Optional. Leave this blank to use SKULOS's shared mail settings; fill it in to send from your own.</p>
  </div>
</div>
<div class="card" style="max-width:36rem">
  <form method="POST" action="{{ route('school.settings.mail.update', ['school' => $school->slug]) }}">
    @csrf
    @include('settings.mail-form-fields')
    <button class="btn btn-primary btn-sm" type="submit">Save mail settings</button>
  </form>
  <form method="POST" action="{{ route('school.settings.mail.test', ['school' => $school->slug]) }}" style="margin-top:1rem">
    @csrf
    <button class="btn btn-ghost btn-sm" type="submit">Send test email to myself</button>
  </form>
</div>

{{--
  Welcome email settings (user request 2026-07-26): "new user accounts
  get a welcome email with login details, portal link, and a role
  specific brief; admin can disable email notifications for a period."
  This pause only ever affects the new account welcome email, not the
  school's mail system generally (announcement email has its own,
  separate toggle on the Communication settings page).
--}}
<div class="card" style="max-width:36rem;margin-top:1.25rem">
  <h3 style="margin-bottom:.3rem">Welcome emails</h3>
  <p class="muted" style="font-size:.85rem;margin-bottom:1rem">Sent automatically whenever a new staff, parent, or student account is created, with the sign in details, a link to the portal, and a short brief on what that role can do.</p>

  <form method="POST" action="{{ route('school.settings.mail.welcome-emails.update', ['school' => $school->slug]) }}">
    @csrf

    <div class="field">
      <label for="paused_until">Pause welcome emails until</label>
      @if($welcomeEmailsPausedUntil)
        <p class="muted" style="font-size:.85rem;margin-bottom:.5rem">Currently paused until {{ $welcomeEmailsPausedUntil->format('d M Y, g:i a') }}. New accounts still get created as normal, they just will not receive this email until then.</p>
      @endif
      <input id="paused_until" name="paused_until" type="datetime-local" value="{{ $welcomeEmailsPausedUntil?->format('Y-m-d\TH:i') }}" />
      <div class="hint">Leave blank and save to keep welcome emails on. Useful during a bulk import so hundreds of accounts do not all email at once.</div>
      @if($welcomeEmailsPausedUntil)
        <label style="display:flex;align-items:center;gap:.5rem;margin-top:.5rem;font-weight:600">
          <input type="checkbox" name="resume_now" value="1" /> Resume welcome emails now instead
        </label>
      @endif
    </div>

    <div class="field">
      <label>Role brief shown in the email</label>
      <div class="hint" style="margin-bottom:.6rem">One short sentence per role, shown under the greeting. Leave a box blank to use SKULOS's own default wording for that role.</div>
      <div style="max-height:22rem;overflow-y:auto;border:1px solid var(--line);border-radius:10px;padding:.8rem;display:flex;flex-direction:column;gap:.7rem">
        @foreach($welcomeBriefs as $slug => $brief)
          <div>
            <label for="brief_{{ $slug }}" style="font-size:.82rem">{{ $roleLabels[$slug] ?? $slug }}</label>
            <textarea id="brief_{{ $slug }}" name="briefs[{{ $slug }}]" rows="2" maxlength="240">{{ old("briefs.$slug", $brief) }}</textarea>
          </div>
        @endforeach
      </div>
    </div>

    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save welcome email settings</button>
  </form>
</div>
@endsection
