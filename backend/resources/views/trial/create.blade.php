@extends('layouts.app')
@section('title', 'Start a Trial')
@section('bare', true)

@push('head')
<style>
  .trial-shell{min-height:100vh;display:grid;place-items:center;padding:2.5rem 1.25rem}
  .trial-card{width:100%;max-width:34rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.4rem 2.2rem;box-shadow:var(--shadow)}
  .trial-card .auth-logo{margin:0 auto 1.1rem}
  .trial-card h1{text-align:center;font-size:1.4rem;font-weight:800}
  .trial-card p.lead{text-align:center;color:var(--mut);margin:.4rem 0 1.6rem}
  .trial-powered{margin-top:1.4rem;text-align:center;color:var(--mut-dim);font-size:.85rem}
  .trial-powered b em{font-style:normal;color:var(--accent)}
</style>
@endpush

@section('content')
<div class="trial-shell">
  <div class="trial-card">
    <div class="auth-logo" style="margin-inline:auto">S</div>
    <h1>Start a trial</h1>
    <p class="lead">Tell us about your school and you'll get instant access to set it up, free for a full year. Our team reviews every new account in the background and follows up about a demo and training for your staff.</p>

    @if($errors->any())
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
    @endif
    @if(session('error'))
      <div class="flash flash-bad" style="margin-bottom:1rem">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('trial.store') }}">
      @csrf
      {{-- honeypot: hidden from real visitors, bots tend to fill every field --}}
      <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label for="website">Leave blank</label>
        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off" />
      </div>

      <div class="field">
        <label for="school_name">School name</label>
        <input id="school_name" name="school_name" value="{{ old('school_name') }}" required />
      </div>

      {{-- The school's own web address (2026-08-16). Asked here rather
           than derived silently at approval time, so a school sees and
           chooses it up front instead of finding out in the approval
           email. Availability is checked live, but re-checked at
           approval, since another school could claim the same name in
           between. --}}
      <div class="field">
        <label class="req" for="preferred_slug">Your school's web address</label>
        <div style="display:flex;align-items:center;gap:.35rem;flex-wrap:wrap">
          <input id="preferred_slug" name="preferred_slug" value="{{ old('preferred_slug') }}"
                 maxlength="60" placeholder="brightstaracademy" pattern="[a-zA-Z0-9]+" required
                 style="flex:1;min-width:12rem" />
          <span class="muted">.{{ config('app.domain') }}</span>
        </div>
        <div class="hint" id="slugPreview">Letters and numbers only, no spaces.</div>
        @error('preferred_slug')<div class="err">{{ $message }}</div>@enderror
      </div>

      {{-- A real, searchable country list rather than free text
           (2026-08-19), sourced from the same countries table every
           other country picker in the app already uses. A native
           <datalist> gives type-to-filter search with no JS of its own
           to write or ship, which is exactly the "search function" ask
           on a page that otherwise carries no client-side dependencies. --}}
      <div class="field">
        <label class="req" for="country">School location (country)</label>
        <input id="country" name="country" list="country-options" autocomplete="off"
               value="{{ old('country') }}" placeholder="Start typing to search" required />
        <datalist id="country-options">
          @foreach($countries as $country)
            <option value="{{ $country }}">
          @endforeach
        </datalist>
        @error('country')<div class="err">{{ $message }}</div>@enderror
      </div>

      <div class="grid cols-2">
        <div class="field">
          <label for="contact_name">Your name</label>
          <input id="contact_name" name="contact_name" value="{{ old('contact_name') }}" required />
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" required />
        </div>
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="phone">Phone (optional)</label>
          <input id="phone" name="phone" value="{{ old('phone') }}" />
        </div>
        <div class="field">
          <label for="student_count">Roughly how many students</label>
          <input id="student_count" name="student_count" type="number" min="1" value="{{ old('student_count') }}" />
        </div>
      </div>
      <div class="field">
        <label for="message">Anything else we should know (optional)</label>
        <textarea id="message" name="message" rows="3">{{ old('message') }}</textarea>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Request a trial</button>
    </form>

<script>
(function () {
  var input = document.getElementById('preferred_slug');
  var note = document.getElementById('slugPreview');
  if (!input || !note) return;
  var timer = null;

  function check() {
    var value = input.value.trim();
    if (!value) {
      note.textContent = 'Letters and numbers only, no spaces.';
      note.style.color = '';
      return;
    }
    fetch('{{ route('trial.check-slug') }}?slug=' + encodeURIComponent(value))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          note.textContent = data.url + ' is available.';
          note.style.color = 'var(--ok, #12A97B)';
        } else {
          var msg = data.reason || 'That address is not available.';
          if (data.suggestions && data.suggestions.length) {
            msg += ' Try: ' + data.suggestions.join(', ');
          }
          note.textContent = msg;
          note.style.color = 'var(--bad, #E14F63)';
        }
      })
      .catch(function () { /* leave the hint as it is if the check cannot run */ });
  }

  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(check, 400);
  });
})();
</script>
    <p class="trial-powered">Powered by <b>SK<em>U</em>LOS</b></p>
  </div>
</div>
@endsection
