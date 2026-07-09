@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Sign In')
@section('bare', true)

@push('head')
<style>
  .login-page{min-height:100vh}
  .lp-logo{width:56px;height:56px;border-radius:14px;background:var(--accent);color:#241503;display:grid;place-items:center;font-weight:800;font-size:1.4rem;margin-bottom:1rem;overflow:hidden}
  .lp-logo img{width:100%;height:100%;object-fit:contain}
  .lp-head{font-size:1.55rem;font-weight:800;line-height:1.15;letter-spacing:-.01em}
  .lp-sub{color:var(--mut);margin:.4rem 0 1.5rem}
  .form-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)}
  .sso-sep{display:flex;align-items:center;gap:.7rem;margin:1.1rem 0;color:var(--mut-dim);font-size:.78rem}
  .sso-sep::before,.sso-sep::after{content:"";flex:1;height:1px;background:var(--line)}
  .sso-row{display:flex;flex-direction:column;gap:.5rem}
  .powered{font-size:.75rem;color:var(--mut-dim);margin-top:1.2rem;text-align:center}

  /* centered */
  .lp-centered{display:grid;place-items:center;padding:2rem 1.25rem}
  /* split */
  .lp-split{display:grid;grid-template-columns:1.05fr .95fr;min-height:100vh}
  .skword{font-family:var(--font);font-weight:800;letter-spacing:-.01em}
  .skword em{font-style:normal;color:#E8A33D}
  .powered{margin-top:1.4rem;text-align:center;color:var(--mut-dim);font-size:.85rem;display:flex;align-items:center;justify-content:center;gap:.4rem}
  .powered .skword{font-size:1.15rem;color:var(--ink)}
  .lp-brandpanel{position:relative;overflow:hidden;color:#fff;display:flex;flex-direction:column;justify-content:center;padding:3rem;
    background:linear-gradient(150deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #10121a))}
  .lp-brandpanel .bp-logo{width:60px;height:60px;border-radius:16px;background:rgba(255,255,255,.16);display:grid;place-items:center;font-weight:800;font-size:1.5rem;margin-bottom:1.5rem;overflow:hidden}
  .lp-brandpanel .bp-logo img{width:100%;height:100%;object-fit:contain}
  .lp-brandpanel h1{font-size:2rem;font-weight:800;line-height:1.1;margin-bottom:.8rem;max-width:16ch}
  .lp-brandpanel p{opacity:.9;max-width:34ch;line-height:1.6}
  .lp-blob{position:absolute;border-radius:50%;background:rgba(255,255,255,.10)}
  .lp-blob.b1{width:280px;height:280px;right:-80px;top:-60px}
  .lp-blob.b2{width:180px;height:180px;left:-50px;bottom:-40px;background:rgba(255,255,255,.07)}
  .lp-formside{display:grid;place-items:center;padding:2.5rem 1.5rem}
  .lp-formside .form-card{border:none;box-shadow:none;background:transparent;padding:0}
  /* cover */
  .lp-cover{min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem;
    background:radial-gradient(1200px 600px at 50% -10%, color-mix(in srgb, var(--accent) 40%, transparent), transparent),
    linear-gradient(160deg, color-mix(in srgb, var(--accent) 22%, var(--bg)), var(--bg))}
  /* showcase */
  .lp-showcase{display:grid;grid-template-columns:.95fr 1.05fr;min-height:100vh}
  .lp-showcase .sc-info{padding:3rem;display:flex;flex-direction:column;justify-content:center;background:var(--panel);border-right:1px solid var(--line)}
  .lp-showcase .sc-info h1{font-size:1.9rem;font-weight:800;line-height:1.15;margin-bottom:1rem}
  .lp-showcase ul{list-style:none;display:flex;flex-direction:column;gap:.7rem;margin-top:.5rem}
  .lp-showcase li{display:flex;gap:.6rem;color:var(--mut);font-size:.95rem}
  .lp-showcase li::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--accent);margin-top:.5rem;flex-shrink:0}
  .lp-showcase .sc-form{display:grid;place-items:center;padding:2.5rem 1.5rem}
  @media(max-width:820px){.lp-split,.lp-showcase{grid-template-columns:1fr}.lp-brandpanel,.lp-showcase .sc-info{display:none}}
</style>
@endpush

@php
  $b = $school ? $school->branding() : ['color'=>'#E8A33D','logo'=>null,'headline'=>'SKULOS','message'=>'Sign in to your platform account.','login_layout'=>'centered'];
  $layout = $school ? $b['login_layout'] : 'centered';
  $initial = strtoupper(substr($school->name ?? 'S',0,1));
@endphp

@section('content')
<div class="login-page">
@switch($layout)

  @case('split')
    <div class="lp-split">
      <div class="lp-brandpanel">
        <span class="lp-blob b1"></span><span class="lp-blob b2"></span>
        <div style="position:relative">
          <div class="bp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
          <h1>{{ $b['headline'] }}</h1>
          <p>{{ $b['message'] }}</p>
        </div>
      </div>
      <div class="lp-formside">
        <div class="form-card">
          <div class="lp-head">Sign in</div>
          <p class="lp-sub">Welcome back. Please enter your details.</p>
          @include('auth.partials.login-form', ['school' => $school])
          <div class="powered">Powered by <span class="skword">SK<em>U</em>LOS</span></div>
        </div>
      </div>
    </div>
    @break

  @case('cover')
    <div class="lp-cover">
      <div class="form-card">
        <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
        <div class="lp-head">{{ $b['headline'] }}</div>
        <p class="lp-sub">{{ $b['message'] }}</p>
        @include('auth.partials.login-form', ['school' => $school])
        <div class="powered">Powered by <span class="skword">SK<em>U</em>LOS</span></div>
      </div>
    </div>
    @break

  @case('showcase')
    <div class="lp-showcase">
      <div class="sc-info">
        <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
        <h1>{{ $b['headline'] }}</h1>
        <p class="muted" style="max-width:38ch">{{ $b['message'] }}</p>
        <ul>
          <li>See attendance, results and fees in one place</li>
          <li>One login for everything you are allowed to see</li>
          <li>Stay up to date with school announcements</li>
        </ul>
      </div>
      <div class="sc-form">
        <div class="form-card">
          <div class="lp-head">Sign in</div>
          <p class="lp-sub">Continue to your school portal.</p>
          @include('auth.partials.login-form', ['school' => $school])
        </div>
      </div>
    </div>
    @break

  @default {{-- centered --}}
    <div class="lp-centered">
      <div class="form-card">
        <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
        <div class="lp-head">{{ $b['headline'] }}</div>
        <p class="lp-sub">{{ $b['message'] }}</p>
        @include('auth.partials.login-form', ['school' => $school])
        @unless($school)<p class="powered">Platform administration</p>@endunless
      </div>
    </div>
@endswitch
</div>
@endsection
