@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Sign In')
@section('bare', true)

@push('head')
<style>
  /* See layouts/app.blade.php: the blank area below content on
     Chrome for iOS was overscroll, not extra page height. */
  html,body{overscroll-behavior-y:none}
  .login-page{min-height:100vh;min-height:100dvh;min-height:100svh}
  .lp-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .lp-logo img{width:100%;height:100%;object-fit:contain}
  .lp-head{font-size:1.55rem;font-weight:800;line-height:1.15;letter-spacing:-.01em;text-align:center}
  .lp-sub{color:var(--mut);margin:.4rem 0 1.5rem;text-align:center}
  .form-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.2rem;box-shadow:var(--shadow)}
  .sso-sep{display:flex;align-items:center;gap:.7rem;margin:1.1rem 0;color:var(--mut-dim);font-size:.78rem}
  .sso-sep::before,.sso-sep::after{content:"";flex:1;height:1px;background:var(--line)}
  .sso-row{display:flex;flex-direction:column;gap:.5rem}
  .powered{font-size:.75rem;color:var(--mut-dim);margin-top:1.2rem;text-align:center}
  .support-line{font-size:.75rem;color:var(--mut-dim);margin-top:.4rem;text-align:center}
  .support-line a{color:inherit;text-decoration:underline;text-underline-offset:2px}

  /* centered */
  .lp-centered{display:grid;place-items:center;padding:2rem 1.25rem;position:relative;overflow:hidden}
  /* split */
  .lp-split{display:grid;grid-template-columns:1.05fr .95fr;min-height:100vh;min-height:100dvh;min-height:100svh}
  .lp-split.box-left{grid-template-columns:.95fr 1.05fr}
  .lp-split.box-left .lp-brandpanel{order:2}
  .lp-split.box-left .lp-formside{order:1}
  .skword{font-family:var(--font);font-weight:800;letter-spacing:-.01em}
  .skword em{font-style:normal;color:#E8A33D}
  .powered{margin-top:1.4rem;text-align:center;color:var(--mut-dim);font-size:.85rem;display:flex;align-items:center;justify-content:center;gap:.4rem}
  .powered .skword{font-size:1.15rem;color:var(--ink)}
  .lp-brandpanel{position:relative;overflow:hidden;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:3rem;
    background:linear-gradient(150deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #10121a))}
  .lp-brandpanel .bp-logo{width:76px;height:76px;border-radius:20px;background:rgba(255,255,255,.16);display:grid;place-items:center;font-weight:800;font-size:1.9rem;margin:0 auto 1.6rem;overflow:hidden}
  .lp-brandpanel .bp-logo img{width:100%;height:100%;object-fit:contain}
  .lp-brandpanel h1{font-size:2.35rem;font-weight:800;line-height:1.12;margin:0 auto .9rem;max-width:17ch}
  .lp-brandpanel p{opacity:.92;max-width:34ch;line-height:1.6;margin:0 auto}
  .lp-blob{position:absolute;border-radius:50%;background:rgba(255,255,255,.10)}
  .lp-blob.b1{width:280px;height:280px;right:-80px;top:-60px}
  .lp-blob.b2{width:180px;height:180px;left:-50px;bottom:-40px;background:rgba(255,255,255,.07)}
  .lp-formside{display:grid;place-items:center;padding:2.5rem 1.5rem}
  .lp-formside .form-card{border:none;box-shadow:none;background:transparent;padding:0}
  /* cover */
  .lp-cover{min-height:100vh;min-height:100dvh;min-height:100svh;display:grid;place-items:center;padding:2rem 1.25rem;position:relative;overflow:hidden;
    background:radial-gradient(1200px 600px at 50% -10%, color-mix(in srgb, var(--accent) 40%, transparent), transparent),
    linear-gradient(160deg, color-mix(in srgb, var(--accent) 22%, var(--bg)), var(--bg))}
  /* showcase */
  .lp-showcase{display:grid;grid-template-columns:.95fr 1.05fr;min-height:100vh;min-height:100dvh;min-height:100svh}
  .lp-showcase.box-left{grid-template-columns:1.05fr .95fr}
  .lp-showcase.box-left .sc-info{order:2;border-right:none;border-left:1px solid var(--line)}
  .lp-showcase.box-left .sc-form{order:1}
  .lp-showcase .sc-info{position:relative;overflow:hidden;padding:3rem;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;background:var(--panel);border-right:1px solid var(--line)}
  .lp-showcase .sc-info h1{font-size:2rem;font-weight:800;line-height:1.15;margin:0 auto 1rem;max-width:16ch}
  .lp-showcase ul{list-style:none;display:flex;flex-direction:column;gap:.7rem;margin-top:.5rem;text-align:left}
  .lp-showcase li{display:flex;gap:.6rem;color:var(--mut);font-size:.95rem}
  .lp-showcase li::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--accent);margin-top:.5rem;flex-shrink:0}
  .lp-showcase .sc-form{display:grid;place-items:center;padding:2.5rem 1.5rem}
  @media(max-width:820px){.lp-split,.lp-showcase{grid-template-columns:1fr}.lp-brandpanel,.lp-showcase .sc-info{display:none}}

  /* floating feature icons: scattered chips that drift gently, placed on
     branded/background panels to suggest the platform's modules without
     competing with the logo/tagline for attention. */
  .lp-floaters{position:absolute;inset:0;pointer-events:none;z-index:0}
  .lp-floaters .fl{position:absolute;width:44px;height:44px;border-radius:13px;background:rgba(255,255,255,.14);
    display:grid;place-items:center;color:#fff;animation:lp-float 6s ease-in-out infinite;backdrop-filter:blur(2px)}
  .lp-floaters .fl svg{width:20px;height:20px}
  .lp-centered .lp-floaters .fl,.lp-cover .lp-floaters .fl{background:color-mix(in srgb, var(--accent) 16%, var(--panel));color:var(--accent-text);border:1px solid var(--line)}
  @keyframes lp-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
  @media(max-width:820px){.lp-floaters{display:none}}
</style>
@endpush

@php
  $floaters = [
    ['ic'=>'students','top'=>'10%','left'=>'12%','delay'=>'0s'],
    ['ic'=>'academics','top'=>'20%','left'=>'78%','delay'=>'.6s'],
    ['ic'=>'attendance','top'=>'46%','left'=>'6%','delay'=>'1.2s'],
    ['ic'=>'results','top'=>'62%','left'=>'82%','delay'=>'.3s'],
    ['ic'=>'calendar','top'=>'78%','left'=>'16%','delay'=>'.9s'],
    ['ic'=>'comms','top'=>'86%','left'=>'70%','delay'=>'1.5s'],
  ];
@endphp
@php
  $floatIcons = function() use ($floaters) {
    $html = '';
    foreach ($floaters as $f) {
      $html .= '<span class="fl" style="top:'.$f['top'].';left:'.$f['left'].';animation-delay:'.$f['delay'].'">'
        .view('layouts.partials.icon', ['name' => $f['ic']])->render().'</span>';
    }

    return $html;
  };
@endphp

@php
  $b = $school ? $school->branding() : ['color'=>'#E8A33D','logo'=>null,'headline'=>'SKULOS','message'=>'Sign in to your platform account.','login_layout'=>'centered','login_box_side'=>'right'];
  $layout = $school ? $b['login_layout'] : 'centered';
  $boxSide = $b['login_box_side'] ?? 'right';
  $initial = strtoupper(substr($school->name ?? 'S',0,1));
@endphp

@section('content')
<div class="login-page">
@switch($layout)

  @case('split')
    <div class="lp-split {{ $boxSide === 'left' ? 'box-left' : '' }}">
      <div class="lp-brandpanel">
        <span class="lp-blob b1"></span><span class="lp-blob b2"></span>
        <div class="lp-floaters">{!! $floatIcons() !!}</div>
        <div style="position:relative;z-index:1">
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
          @include('auth.partials.powered')
        </div>
      </div>
    </div>
    @break

  @case('cover')
    <div class="lp-cover">
      <div class="lp-floaters">{!! $floatIcons() !!}</div>
      <div class="form-card" style="position:relative;z-index:1">
        <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
        <div class="lp-head">{{ $b['headline'] }}</div>
        <p class="lp-sub">{{ $b['message'] }}</p>
        @include('auth.partials.login-form', ['school' => $school])
        @include('auth.partials.powered')
      </div>
    </div>
    @break

  @case('showcase')
    <div class="lp-showcase {{ $boxSide === 'left' ? 'box-left' : '' }}">
      <div class="sc-info">
        <div class="lp-floaters">{!! $floatIcons() !!}</div>
        <div style="position:relative;z-index:1">
          <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
          <h1>{{ $b['headline'] }}</h1>
          <p class="muted" style="max-width:38ch;margin:0 auto">{{ $b['message'] }}</p>
          <ul>
            <li>See attendance, results and fees in one place</li>
            <li>One login for everything you are allowed to see</li>
            <li>Stay up to date with school announcements</li>
          </ul>
        </div>
      </div>
      <div class="sc-form">
        <div class="form-card">
          <div class="lp-head">Sign in</div>
          <p class="lp-sub">Continue to your school portal.</p>
          @include('auth.partials.login-form', ['school' => $school])
          @include('auth.partials.powered')
        </div>
      </div>
    </div>
    @break

  @default {{-- centered --}}
    <div class="lp-centered">
      <div class="lp-floaters">{!! $floatIcons() !!}</div>
      <div class="form-card" style="position:relative;z-index:1">
        <div class="lp-logo">@if($b['logo'])<img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="">@else{{ $initial }}@endif</div>
        <div class="lp-head">{{ $b['headline'] }}</div>
        <p class="lp-sub">{{ $b['message'] }}</p>
        @include('auth.partials.login-form', ['school' => $school])
        @if($school)
          @include('auth.partials.powered')
        @else
          <p class="powered">Platform administration</p>
          @php($support = app(\App\Services\Platform\SupportContactService::class)->effective())
          <div class="support-line">
            Need help? <a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a>
            &middot; <a href="tel:{{ str_replace(' ', '', $support['phone']) }}">{{ $support['phone'] }}</a>
          </div>
        @endif
      </div>
    </div>
@endswitch
</div>
@endsection
