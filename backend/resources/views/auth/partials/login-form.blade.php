@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
@elseif(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1rem">{{ session('error') }}</div>
@endif
<form method="POST" action="{{ $school ? route('school.login.submit', ['school' => $school->slug]) : url('/login') }}">
  @csrf
  <div class="field">
    <label for="email">Email or phone number</label>
    <input id="email" name="email" type="text" value="{{ old('email') }}" autocomplete="username" required autofocus />
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required />
  </div>
  <button class="btn btn-primary btn-block" type="submit">Sign in</button>
</form>
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
