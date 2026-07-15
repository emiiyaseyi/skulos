@php
  // A school login asks who is signing in first (user directive
  // 2026-07-13: "Maybe we need to change how the login page works now
  // so that it ask if you are login in as a staff, parents or learner
  // before one see the needed username and password"), so students can
  // sign in with their admission number, a format with no fixed shape,
  // without any risk of colliding with an email/phone guess. The
  // platform admin login ($school null) has no such concept and skips
  // straight to the identifier form, same as before.
  $as = request('as');
@endphp

@if($school && ! $as)
  <div class="stack" style="gap:.6rem">
    <a class="btn btn-ghost btn-block" href="{{ route('school.login', ['school' => $school->slug, 'as' => 'staff']) }}">Staff</a>
    <a class="btn btn-ghost btn-block" href="{{ route('school.login', ['school' => $school->slug, 'as' => 'parent']) }}">Parent</a>
    <a class="btn btn-ghost btn-block" href="{{ route('school.login', ['school' => $school->slug, 'as' => 'student']) }}">Student</a>
  </div>
@else
  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @elseif(session('error'))
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ session('error') }}</div>
  @elseif(session('status'))
    <div class="flash flash-ok" style="margin-bottom:1rem">{{ session('status') }}</div>
  @endif
  <form method="POST" action="{{ $school ? route('school.login.submit', ['school' => $school->slug]) : url('/login') }}">
    @csrf
    <input type="hidden" name="as" value="{{ $as }}" />
    <div class="field">
      <label for="email">
        @if($as === 'student') Admission Number, Email, or Phone
        @else Email or Phone Number
        @endif
      </label>
      <input id="email" name="email" type="text" value="{{ old('email') }}" autocomplete="username" required autofocus />
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required />
    </div>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
  @if($school)
    <p class="support-line" style="margin-top:.8rem">
      <a href="{{ route('school.login', ['school' => $school->slug]) }}">Not {{ ucfirst($as) }}? Change</a> &middot;
      <a href="{{ route('school.password.forgot', ['school' => $school->slug]) }}">Forgot password?</a>
    </p>
  @else
    <p class="support-line" style="margin-top:.8rem">
      <a href="{{ route('login.forgot') }}">Forgot password?</a>
    </p>
  @endif
@endif
@php
  $sso = app(\App\Services\Auth\SsoManager::class);
@endphp
@if(count($sso->available()))
  <div class="sso-sep"><span>or continue with</span></div>
  <div class="sso-row">
    @foreach($sso->available() as $p)
      <a class="btn btn-ghost btn-block" href="{{ route('sso.redirect', ['provider' => $p]) }}">{{ $sso->label($p) }}</a>
    @endforeach
  </div>
@endif
