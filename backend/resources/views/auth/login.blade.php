@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', ($school->name ?? 'SKULOS').' Sign In')
@section('bare', true)

@push('head')
<style>
  /* See layouts/app.blade.php: the blank area below content on
     Chrome for iOS was overscroll, not extra page height. */
  html,body{overscroll-behavior-y:none}
  body::after{content:'';display:block;height:calc(100lvh - 100svh)}
  .login-page{min-height:100vh;min-height:100dvh;min-height:100svh}
  .lp-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .lp-logo img{width:100%;height:100%;object-fit:contain}
  /* Two or more distinct section logos side by side (user request
     2026-09-09), in place of the single mark above. Sized down a touch
     so the row never reads wider than the single mark it replaces. */
  .lp-logo-lockup{display:flex;align-items:center;justify-content:center;gap:.65rem;margin:0 auto 1.1rem}
  .lp-logo-lockup .lp-logo-sm{margin:0;position:relative}
  .lp-logo.lp-logo-sm,.bp-logo.lp-logo-sm{width:50px;height:50px;font-size:1.15rem;border-radius:13px}
  .lp-logo-lockup .lp-logo-sm + .lp-logo-sm::before{content:'';position:absolute;left:-.42rem;top:15%;width:1px;height:70%;background:currentColor;opacity:.25}
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
  /* .lp-formside included here too (2026-08-19): its floaters now sit
     on the light sign in panel, not the warm brand panel the default
     white/translucent chip styling above was built for -- a live
     screenshot found them nearly invisible without this. */
  .lp-centered .lp-floaters .fl,.lp-cover .lp-floaters .fl,.lp-formside .lp-floaters .fl{background:color-mix(in srgb, var(--accent) 16%, var(--panel));color:var(--accent-text);border:1px solid var(--line)}
  @keyframes lp-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-14px)}}
  @media(max-width:820px){.lp-floaters{display:none}}

  /* ================================================================
     Split and centered redesign (2026-08-19, direct feedback across
     several rounds of an artifact comparison before this landed here):
     floating glimpses of the real product instead of a flat colour
     panel, the school's identity kept clearly legible over them, and a
     second, distinct set of icons so nothing repeats between the two
     halves of the page. Cover and showcase are untouched.
     ================================================================ */

  /* Decorative product-glimpse cards -- deliberately generic content
     (no invented specific names), this is background chrome on a real
     login page real people see, not a mockup. */
  .lp-glimpse{position:absolute;width:12.5rem;border-radius:14px;background:rgba(255,255,255,.95);color:#0B1420;
    box-shadow:0 20px 40px -18px rgba(0,0,0,.5);padding:.8rem .85rem;animation:lp-drift-card 7s ease-in-out infinite}
  @keyframes lp-drift-card{0%,100%{transform:translateY(0) var(--gr,rotate(0deg))}50%{transform:translateY(-11px) var(--gr,rotate(0deg))}}
  .lp-glimpse .gdots{display:flex;gap:.28rem;margin-bottom:.55rem}
  .lp-glimpse .gdots i{width:6px;height:6px;border-radius:50%;background:rgba(11,20,32,.16);display:block}
  .lp-glimpse h5{font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:rgba(11,20,32,.48);margin:0 0 .5rem}
  .lp-glimpse .grow{display:flex;align-items:center;justify-content:space-between;padding:.32rem 0;border-top:1px solid rgba(11,20,32,.08)}
  .lp-glimpse .grow:first-of-type{border-top:none}
  .lp-glimpse .glbl{font-size:.72rem;font-weight:600}
  .lp-glimpse .gtoggle{width:28px;height:16px;border-radius:99px;background:rgba(11,20,32,.14);position:relative;flex-shrink:0}
  .lp-glimpse .gtoggle.on{background:var(--accent)}
  .lp-glimpse .gtoggle i{position:absolute;top:2px;left:2px;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25)}
  .lp-glimpse .gtoggle.on i{left:14px}
  .lp-glimpse .gstats{display:flex;gap:.6rem;margin-bottom:.6rem}
  .lp-glimpse .gstats b{display:block;font-size:1.05rem;font-weight:800;font-variant-numeric:tabular-nums}
  .lp-glimpse .gstats span{font-size:.58rem;color:rgba(11,20,32,.48);text-transform:uppercase;font-weight:700}
  .lp-glimpse .gbars{display:flex;align-items:flex-end;gap:.26rem;height:2.4rem}
  .lp-glimpse .gbars i{flex:1;border-radius:3px 3px 0 0;background:linear-gradient(180deg,var(--accent),color-mix(in srgb, var(--accent) 60%, #10121a));display:block}
  .lp-glimpse .gavatar{width:18px;height:18px;border-radius:50%;background:linear-gradient(135deg,var(--accent),color-mix(in srgb, var(--accent) 60%, #10121a));flex-shrink:0}
  .lp-glimpse .gname-bar{height:8px;border-radius:4px;background:rgba(11,20,32,.14);flex:1}
  .lp-glimpse .gok{width:14px;height:14px;border-radius:50%;background:#12A97B;display:grid;place-items:center;flex-shrink:0}
  .lp-glimpse .gok svg{width:8px;height:8px;stroke:#fff;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
  .lp-glimpse .grade{font-size:.66rem;font-weight:800;padding:.1rem .4rem;border-radius:6px}
  .lp-glimpse .grade-a{background:rgba(18,169,123,.16);color:#0d8462}
  .lp-glimpse .grade-b{background:rgba(232,163,61,.22);color:#8a5a12}

  /* A second, distinct icon set beside the glimpses (product/brand
     glyphs), never repeating the audience-facing set already used on
     the sign in side of the page. Scoped to the split panel
     specifically (dark, warm background): a live screenshot found
     these nearly invisible on the centred layout's light background,
     since white-on-white translucent chips read fine on a dark warm
     gradient but disappear on a light one -- .lp-centered's own chip
     styling below already handles the light-background case. */
  .lp-brandpanel .lp-floaters-alt .fl{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.28);
    color:#fff;backdrop-filter:blur(3px)}

  /* split: identity moves to the panel's true centre on its own glass
     card (was left-anchored inside the flex column at whatever height
     the heading text happened to need); glimpses float behind it. */
  .lp-brandpanel{justify-content:flex-start;padding:0}
  .lp-brandpanel-inner{position:absolute;inset:0}
  .lp-identity-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;text-align:center;
    background:rgba(15,10,4,.42);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.16);
    border-radius:20px;padding:1.6rem 2rem;max-width:20rem;box-shadow:0 24px 48px -18px rgba(0,0,0,.5)}
  .lp-identity-center .bp-logo{margin:0 auto .8rem}
  .lp-identity-center h1{font-size:1.4rem;line-height:1.16;margin:0 auto}
  .lp-identity-center p{margin-top:.4rem;font-size:.88rem;max-width:none}
  .lp-formside{position:relative}

  /* centred: the glimpse collage sits crisp and full brightness across
     the whole background (previously a flat colour), with a spotlight
     glow behind the card so legibility never depends on dimming the
     images themselves, and icons ring the edges plus flank the card. */
  .lp-centered{background:linear-gradient(160deg,color-mix(in srgb, var(--accent) 30%, var(--bg)),var(--bg))}
  .lp-centered-bg{position:absolute;inset:-3rem;z-index:0}
  .lp-centered-spotlight{position:absolute;inset:0;z-index:1;
    background:radial-gradient(30rem 26rem at 50% 50%, var(--bg) 0%, var(--bg) 40%, color-mix(in srgb, var(--bg) 65%, transparent) 62%, transparent 82%)}
  .lp-centered .form-card{position:relative;z-index:2}
  .lp-centered .lp-floaters .fl{width:40px;height:40px}
  /* .lp-brandpanel itself is already hidden below 820px by the
     existing rule above (the split panel disappears entirely on
     mobile), so only the centred layout -- which stays visible --
     needs its own mobile fallback: the ambient collage and spotlight
     are decorative weight a small screen does not need. */
  @media(max-width:820px){.lp-centered-bg,.lp-centered-spotlight{display:none}}
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

  // A second, distinct set for panels that now carry the product
  // glimpse cards too (2026-08-19) -- brand/product glyphs rather than
  // the audience-facing set above, so a page never shows the same icon
  // twice. Positions sit in the confirmed-empty top/bottom edge strips
  // (every glimpse card starts after 6% and ends before 94%, and the
  // centred identity/sign in content only occupies the middle band),
  // found necessary after an edge-of-panel ring formula still clipped
  // the cards it was meant to avoid.
  $edgeFloaters = [
    ['ic'=>'dashboard','top'=>'1%','left'=>'17%','delay'=>'0s'],
    ['ic'=>'mail','top'=>'1%','left'=>'50%','delay'=>'.3s'],
    ['ic'=>'house','top'=>'1%','left'=>'83%','delay'=>'.6s'],
    ['ic'=>'roles','bottom'=>'1%','left'=>'17%','delay'=>'.9s'],
    ['ic'=>'billing','bottom'=>'1%','left'=>'50%','delay'=>'1.2s'],
    ['ic'=>'library','bottom'=>'1%','left'=>'83%','delay'=>'1.5s'],
  ];

  // The centred layout's card sits narrow relative to the full-width
  // page behind it, leaving real empty space either side of it at its
  // own vertical middle -- exactly the two blank strips flagged live.
  $sideFloaters = [
    ['ic'=>'academics','top'=>'46%','left'=>'6%','delay'=>'.2s'],
    ['ic'=>'attendance','top'=>'58%','left'=>'6%','delay'=>'1.1s'],
    ['ic'=>'results','top'=>'46%','right'=>'6%','delay'=>'.7s'],
    ['ic'=>'calendar','top'=>'58%','right'=>'6%','delay'=>'1.6s'],
  ];

  $renderFloaters = function (array $items, string $extraClass = '') {
    $html = '';
    foreach ($items as $f) {
      $vert = isset($f['top']) ? 'top:'.$f['top'] : 'bottom:'.$f['bottom'];
      $horiz = isset($f['left']) ? 'left:'.$f['left'] : 'right:'.$f['right'];
      $html .= '<span class="fl'.($extraClass ? ' '.$extraClass : '').'" style="'.$vert.';'.$horiz.';animation-delay:'.$f['delay'].'">'
        .view('layouts.partials.icon', ['name' => $f['ic']])->render().'</span>';
    }

    return $html;
  };

  $floatIcons = fn () => $renderFloaters($floaters);
  $altFloatIcons = fn () => $renderFloaters($edgeFloaters);
  $sideFloatIcons = fn () => $renderFloaters($sideFloaters);
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
        <div class="lp-brandpanel-inner">
          {{-- Four floating glimpses of the real product. Deliberately
               generic content only (no invented names) -- this is
               decorative chrome on a page real people sign in from. --}}
          <div class="lp-glimpse" style="top:11%;left:5%;--gr:rotate(-4deg);animation-delay:.2s">
            <div class="gdots"><i></i><i></i><i></i></div>
            <h5>Settings</h5>
            <div class="grow"><span class="glbl">Notifications</span><span class="gtoggle on"><i></i></span></div>
            <div class="grow"><span class="glbl">Attendance alerts</span><span class="gtoggle"><i></i></span></div>
          </div>
          <div class="lp-glimpse" style="top:13%;left:58%;width:11.5rem;--gr:rotate(-2deg);animation-delay:.6s">
            <div class="gdots"><i></i><i></i><i></i></div>
            <h5>This term</h5>
            <div class="gstats"><div><b>96%</b><span>Attend.</span></div><div><b>412</b><span>Students</span></div></div>
            <div class="gbars"><i style="height:40%"></i><i style="height:65%"></i><i style="height:50%"></i><i style="height:85%"></i><i style="height:60%"></i><i style="height:95%"></i></div>
          </div>
          <div class="lp-glimpse" style="top:55%;left:5%;--gr:rotate(3deg);animation-delay:1.1s">
            <div class="gdots"><i></i><i></i><i></i></div>
            <h5>Today's attendance</h5>
            <div class="grow" style="border-top:none"><span class="gavatar"></span><span class="gname-bar"></span><span class="gok"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></div>
            <div class="grow"><span class="gavatar"></span><span class="gname-bar"></span><span class="gok"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></div>
            <div class="grow"><span class="gavatar"></span><span class="gname-bar"></span><span class="gok"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></div>
          </div>
          <div class="lp-glimpse" style="bottom:11%;right:5%;width:11.5rem;--gr:rotate(3deg);animation-delay:1.6s">
            <div class="gdots"><i></i><i></i><i></i></div>
            <h5>Report card</h5>
            <div class="grow"><span class="glbl">Mathematics</span><span class="grade grade-a">A1</span></div>
            <div class="grow"><span class="glbl">English Language</span><span class="grade grade-b">B2</span></div>
          </div>
          <div class="lp-floaters lp-floaters-alt">{!! $altFloatIcons() !!}</div>
        </div>
        <div class="lp-identity-center">
          @include('auth.partials.logo-mark', ['b' => $b, 'initial' => $initial, 'markClass' => 'bp-logo', 'sectionLogos' => $sectionLogos ?? []])
          <h1>{{ $b['headline'] }}</h1>
          <p>{{ $b['message'] }}</p>
        </div>
      </div>
      <div class="lp-formside">
        <div class="lp-floaters">{!! $floatIcons() !!}</div>
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
        @include('auth.partials.logo-mark', ['b' => $b, 'initial' => $initial, 'markClass' => 'lp-logo', 'sectionLogos' => $sectionLogos ?? []])
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
          @include('auth.partials.logo-mark', ['b' => $b, 'initial' => $initial, 'markClass' => 'lp-logo', 'sectionLogos' => $sectionLogos ?? []])
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
      <div class="lp-centered-bg">
        {{-- Same four glimpses as split, at full brightness (a live
             report found the first pass had them dimmed and blurred
             into a muddy mess, and clearer than the split panel's own
             version -- the whole point was to match it). --}}
        <div class="lp-glimpse" style="top:11%;left:8%;--gr:rotate(-4deg);animation-delay:0s">
          <div class="gdots"><i></i><i></i><i></i></div>
          <h5>Settings</h5>
          <div class="grow"><span class="glbl">Notifications</span><span class="gtoggle on"><i></i></span></div>
          <div class="grow"><span class="glbl">Attendance alerts</span><span class="gtoggle"><i></i></span></div>
        </div>
        <div class="lp-glimpse" style="top:13%;right:6%;--gr:rotate(3deg);animation-delay:.5s">
          <div class="gdots"><i></i><i></i><i></i></div>
          <h5>This term</h5>
          <div class="gstats"><div><b>96%</b><span>Attend.</span></div><div><b>412</b><span>Students</span></div></div>
          <div class="gbars"><i style="height:40%"></i><i style="height:65%"></i><i style="height:50%"></i><i style="height:85%"></i><i style="height:95%"></i></div>
        </div>
        <div class="lp-glimpse" style="bottom:11%;left:14%;--gr:rotate(3deg);animation-delay:1s">
          <div class="gdots"><i></i><i></i><i></i></div>
          <h5>Today's attendance</h5>
          <div class="grow" style="border-top:none"><span class="gavatar"></span><span class="gname-bar"></span><span class="gok"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></div>
          <div class="grow"><span class="gavatar"></span><span class="gname-bar"></span><span class="gok"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></span></div>
        </div>
        <div class="lp-glimpse" style="bottom:13%;right:10%;width:11.5rem;--gr:rotate(-3deg);animation-delay:1.5s">
          <div class="gdots"><i></i><i></i><i></i></div>
          <h5>Report card</h5>
          <div class="grow"><span class="glbl">Mathematics</span><span class="grade grade-a">A1</span></div>
          <div class="grow"><span class="glbl">English Language</span><span class="grade grade-b">B2</span></div>
        </div>
      </div>
      <div class="lp-centered-spotlight"></div>
      {{-- Icons ring the top/bottom edges and now flank both sides of
           the card too (direct feedback: the two sides read blank). --}}
      <div class="lp-floaters lp-floaters-alt">{!! $altFloatIcons() !!}</div>
      <div class="lp-floaters">{!! $sideFloatIcons() !!}</div>
      <div class="form-card" style="position:relative;z-index:1">
        @include('auth.partials.logo-mark', ['b' => $b, 'initial' => $initial, 'markClass' => 'lp-logo', 'sectionLogos' => $sectionLogos ?? []])
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
