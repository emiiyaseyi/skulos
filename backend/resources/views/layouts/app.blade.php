<!doctype html>
<html lang="en" @isset($theme) data-theme="{{ $theme }}" @endisset>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<title>@yield('title', 'SKULOS')</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}" />
<script>
(function(){try{var s=localStorage.getItem('skulos-theme');var t=s||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
@php
  // Computed early (not just in the shell block below) so the <style>
  // block can pick a portal-aware default --accent before any markup
  // renders. A school's own saved brand colour ($accent, from Branding)
  // always wins regardless of portal, this only changes the unbranded
  // fallback.
  $portal = $portal ?? (auth()->user()?->is_super_admin ? 'admin' : 'school');
  $defaultAccent = $portal === 'school' ? '#C88A2E' : '#E8A33D';
  // Always the same dynamic, WCAG-aware computation for every accent, a
  // school's own custom colour or the unbranded default alike (never
  // hardcode ink for a colour, per the standing "text on a colour must
  // always be readable" rule). The default school amber (#C88A2E,
  // luminance 0.306) already computes to white through this, matching the
  // lookbook's near-white CTA text, with no special-casing needed.
  $accentInk = \App\Support\ColorContrast::inkFor($accent ?? $defaultAccent);
@endphp
<style>
:root{
  --accent: {{ $accent ?? $defaultAccent }};
  --accent-ink: {{ $accentInk }};
  --bg:#EEF1F6; --panel:#FFFFFF; --ink:#0B1420; --mut:rgba(11,20,32,.60); --mut-dim:rgba(11,20,32,.42);
  --line:rgba(11,20,32,.10); --soft:rgba(11,20,32,.035); --ok:#12A97B; --warn:#E5901C; --bad:#E14F63;
  --side:#0C1826; --side-ink:#E7ECF3; --side-mut:rgba(231,236,243,.55); --side-line:rgba(231,236,243,.10); --side-active:rgba(255,255,255,.08);
  --shadow:0 1px 2px rgba(15,23,35,.06),0 8px 24px -14px rgba(15,23,35,.18);
  --font:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  --radius:14px;
}
@media (prefers-color-scheme:dark){:root{
  --bg:#070C14; --panel:#0E1B2B; --ink:#F3F5F8; --mut:rgba(243,245,248,.62); --mut-dim:rgba(243,245,248,.40);
  --line:rgba(243,245,248,.10); --soft:rgba(243,245,248,.04); --shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);
  --side:#0A1420;
}}
:root[data-theme="light"]{--bg:#EEF1F6;--panel:#FFFFFF;--ink:#0B1420;--mut:rgba(11,20,32,.60);--mut-dim:rgba(11,20,32,.42);--line:rgba(11,20,32,.10);--soft:rgba(11,20,32,.035);--shadow:0 1px 2px rgba(15,23,35,.06),0 8px 24px -14px rgba(15,23,35,.18);--side:#0C1826;}
:root[data-theme="dark"]{--bg:#070C14;--panel:#0E1B2B;--ink:#F3F5F8;--mut:rgba(243,245,248,.62);--mut-dim:rgba(243,245,248,.40);--line:rgba(243,245,248,.10);--soft:rgba(243,245,248,.04);--shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);--side:#0A1420;}

/* ---- School Administration theme (Design Lookbook v4, applied 2026-07-14) ----
   Scoped to .shell.school-theme (portal=school only), never touches the
   platform admin portal's dark navy/blue-grey look or nav-admin.blade.php.
   A school's own saved brand colour ($accent) still overrides --accent
   the same as before, this only changes the surrounding palette. */
/* --panel is the lookbook's "--card" (#FFFDF6, what every card/metric/
   data-fig sits on); --shell is its "--paper" (#FBF6EA, the app shell and
   sidebar's own background, one shade deeper than the page canvas). The
   sidebar is LIGHT paper, not a dark rail, matching the approved lookbook
   artifact exactly, not the platform admin portal's dark navy sidebar. */
.school-theme{
  --bg:#FFFFFF; --panel:#FFFFFF; --shell:#FFFFFF; --ink:#1A1613;
  --mut:rgba(26,22,19,.68); --mut-dim:rgba(26,22,19,.58);
  --line:#E4D9BE; --line-deep:#C9B98F; --soft:rgba(26,22,19,.035);
  --ok:#4A6B3A; --warn:#B5731A; --bad:#B23A2A;
  --side:var(--shell); --side-ink:var(--ink); --side-mut:rgba(26,22,19,.62); --side-line:var(--line); --side-active:#F5EEDC;
  --shadow:0 1px 2px rgba(26,22,19,.07),0 10px 26px -16px rgba(26,22,19,.22);
  --font:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  --font-serif:Georgia,'Iowan Old Style','Palatino Linotype','Book Antiqua',serif;
  --radius:10px;
}
@media(prefers-color-scheme:dark){.school-theme{
  --bg:#171310; --panel:#211C17; --shell:#171310; --ink:#F0E9D8;
  --mut:rgba(240,233,216,.68); --mut-dim:rgba(240,233,216,.55);
  --line:rgba(240,233,216,.10); --line-deep:rgba(240,233,216,.18); --soft:rgba(240,233,216,.04);
  --side-mut:rgba(240,233,216,.62); --side-active:#211C17; --shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);
}}
:root[data-theme="light"] .school-theme{--bg:#FFFFFF;--panel:#FFFFFF;--shell:#FFFFFF;--ink:#1A1613;--mut:rgba(26,22,19,.68);--mut-dim:rgba(26,22,19,.58);--line:#E4D9BE;--line-deep:#C9B98F;--soft:rgba(26,22,19,.035);--side-mut:rgba(26,22,19,.62);--side-active:#F5EEDC;--shadow:0 1px 2px rgba(26,22,19,.07),0 10px 26px -16px rgba(26,22,19,.22);}
:root[data-theme="dark"] .school-theme{--bg:#171310;--panel:#211C17;--shell:#171310;--ink:#F0E9D8;--mut:rgba(240,233,216,.68);--mut-dim:rgba(240,233,216,.55);--line:rgba(240,233,216,.10);--line-deep:rgba(240,233,216,.18);--soft:rgba(240,233,216,.04);--side-mut:rgba(240,233,216,.62);--side-active:#211C17;--shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);}

/* numbers and headline-weight text set in serif per the lookbook,
   uppercase sans labels stay on the base --font everywhere else */
.school-theme h1.title,.school-theme .appbar h2,.school-theme .card h3,
.school-theme .stat b,.school-theme .auth-logo,.school-theme .side-brand{font-family:var(--font-serif)}
/* card icon + title centred (standing design system rule) applied to the
   shared stat-card primitive */
.school-theme .stat-card{flex-direction:column;text-align:center;gap:.5rem}
.school-theme .stat-ic{margin:0 auto}
.school-theme .stat b{font-size:1.9rem}
/* sidebar labels stay left, monogram icons move right (lookbook nav rule,
   an explicit exception to "no left alignment") */
.school-theme:not(.collapsed) .side-link .ic{margin-left:auto}
.school-theme .side-badge{border-radius:8px}
.school-theme .side{border-right:1px solid var(--side-line)}
.school-theme .side-link.active{color:var(--ink)}
.school-theme .side-link.active .ic{color:inherit}
.school-theme .btn-ghost{background:var(--panel);border-color:var(--line-deep)}

*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--font);background:var(--bg);color:var(--ink);line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

/* ---- app shell ---- */
.shell{display:grid;grid-template-columns:264px 1fr;min-height:100vh}
.side{background:var(--side);color:var(--side-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
.side-brand{display:flex;align-items:center;gap:.6rem;padding:1.15rem 1.3rem;font-weight:800;font-size:1.3rem;letter-spacing:-.01em}
.side-brand em{font-style:normal;color:var(--accent)}
.side-school{display:flex;align-items:center;gap:.6rem;padding:.4rem 1rem 1rem}
.side-badge{width:34px;height:34px;border-radius:9px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;flex-shrink:0}
.side-school b{font-size:.9rem;display:block;line-height:1.2}
.side-school span{font-size:.72rem;color:var(--side-mut)}
.side-nav{flex:1;padding:.4rem .7rem 1rem}
.side-group{font-size:.66rem;text-transform:uppercase;letter-spacing:.09em;color:var(--side-mut);font-weight:800;padding:.9rem .6rem .35rem}
.side-group-btn{width:100%;background:none;border:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;text-align:left;font-size:.66rem;text-transform:uppercase;letter-spacing:.09em;color:var(--side-mut);font-weight:800;padding:.9rem .6rem .35rem}
.side-group-btn span{flex:1}
.side-group-btn .chev{width:13px;height:13px;flex-shrink:0;transition:transform .15s ease}
.side-group-btn[aria-expanded="true"] .chev{transform:rotate(90deg)}
.side-submenu{display:grid;grid-template-rows:0fr;transition:grid-template-rows .18s ease}
.side-submenu.open{grid-template-rows:1fr}
.side-submenu>div{overflow:hidden}
.side-link{display:flex;align-items:center;gap:.7rem;padding:.6rem .65rem;border-radius:10px;color:var(--side-mut);font-weight:600;font-size:.9rem;transition:background .15s ease,color .15s ease}
.side-link:hover{background:var(--side-active);color:var(--side-ink)}
.side-link.active{background:var(--side-active);color:#fff}
.side-link.active .ic{color:var(--accent)}
.side-link .ic{width:18px;height:18px;flex-shrink:0;color:var(--side-mut)}
.side-link .soon{margin-left:auto;font-size:.6rem;background:rgba(255,255,255,.08);color:var(--side-mut);padding:.1rem .35rem;border-radius:5px;font-weight:700}
.side-foot{padding:.9rem 1rem;border-top:1px solid var(--side-line);display:flex;flex-direction:column;gap:.5rem}
.side-foot-link{display:flex;align-items:center;gap:.6rem;color:inherit;text-decoration:none}
.side-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent),#b8790f);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;flex-shrink:0}
.side-foot .who{flex:1;min-width:0}
.side-foot b{font-size:.82rem;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.side-foot span{font-size:.7rem;color:var(--side-mut)}
.side-foot-approvals{font-size:.72rem;color:var(--side-mut);text-decoration:underline;padding-left:2.6rem}

.main{min-width:0;display:flex;flex-direction:column}
.scrim{display:none}
/* collapsed sidebar (desktop): icon rail */
.shell.collapsed{grid-template-columns:74px 1fr}
.shell.collapsed .side-brand{justify-content:center;font-size:1.1rem;padding-inline:0}
.shell.collapsed .side-brand .brandfull{display:none}
.shell.collapsed .side-school{justify-content:center;padding-inline:0}
.shell.collapsed .side-school div,.shell.collapsed .side-group,.shell.collapsed .side-group-btn,.shell.collapsed .side-link span:not(.ic),.shell.collapsed .side-link .soon,.shell.collapsed .side-foot .who{display:none}
.shell.collapsed .side-submenu{grid-template-rows:1fr}
.shell.collapsed .side-nav{padding-inline:.55rem}
.shell.collapsed .side-link{justify-content:center;padding-inline:0}
.shell.collapsed .side-foot{justify-content:center}
.brandshort{display:none}
.shell.collapsed .brandshort{display:inline}
.collapse-btn{display:inline-grid}
@media(max-width:1000px){.collapse-btn{display:none}}
.impersonate-bar{position:sticky;top:0;z-index:35;background:var(--warn);color:#241503;display:flex;align-items:center;justify-content:center;gap:1rem;padding:.55rem 1rem;font-size:.85rem;font-weight:600;flex-wrap:wrap;text-align:center}
.impersonate-bar .btn{background:#241503;color:#fff;border:none;padding:.35rem .8rem;border-radius:8px;font-weight:700}
.appbar{position:sticky;top:0;z-index:30;background:color-mix(in srgb,var(--panel) 90%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:1rem;padding:.8rem 1.6rem}
.appbar h2{font-size:1rem;font-weight:800}
.appbar .crumb{font-size:.78rem;color:var(--mut-dim)}
.appbar-spacer{flex:1}
.iconbtn{width:2.3rem;height:2.3rem;border-radius:10px;border:1px solid var(--line);background:var(--panel);color:var(--ink);cursor:pointer;display:inline-grid;place-items:center;position:relative}
.iconbtn:hover{background:var(--soft)}
.iconbtn .badge-dot{position:absolute;top:6px;right:7px;width:8px;height:8px;border-radius:50%;background:var(--bad);border:2px solid var(--panel)}
.menu-toggle{display:none}
.content{padding:1.9rem 1.6rem 3rem}

/* ---- primitives ---- */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
h1.title{font-size:1.55rem;font-weight:800;letter-spacing:-.01em}
.subtitle{color:var(--mut);font-size:.92rem;margin-top:.2rem}
.grid{display:grid;gap:1.1rem}
.cols-2{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:repeat(3,1fr)}.cols-4{grid-template-columns:repeat(4,1fr)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:1.4rem;box-shadow:var(--shadow)}
.card h3{font-size:1rem;font-weight:800;margin-bottom:.2rem}
.card p{color:var(--mut);font-size:.9rem}
.stat-card{display:flex;align-items:center;gap:1rem}
.stat-ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 15%,transparent);color:var(--accent);flex-shrink:0}
.stat-ic svg{width:22px;height:22px}
.stat b{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;display:block;line-height:1.1}
.stat span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
label{display:block;font-weight:700;font-size:.82rem;margin-bottom:.35rem}
input,textarea,select{width:100%;padding:.65rem .8rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-family:inherit;font-size:.92rem}
input:focus,textarea:focus,select:focus{outline:2px solid color-mix(in srgb,var(--accent) 55%,transparent);outline-offset:1px;border-color:var(--accent)}
.field{margin-bottom:1rem}
.hint{font-size:.78rem;color:var(--mut-dim);margin-top:.3rem}
.err{color:var(--bad);font-size:.8rem;margin-top:.3rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.6rem 1.1rem;border-radius:10px;border:1px solid transparent;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;transition:transform .15s ease,background .15s ease,box-shadow .15s ease}
.btn-primary{background:var(--accent);color:var(--accent-ink)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px -10px color-mix(in srgb,var(--accent) 70%,transparent)}
.btn-ghost{background:transparent;color:var(--ink);border-color:var(--line)}
.btn-ghost:hover{background:var(--soft)}
.btn-sm{padding:.4rem .8rem;font-size:.82rem;border-radius:8px}
.btn-block{width:100%}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:.8rem .6rem;border-bottom:1px solid var(--line);font-size:.9rem}
th{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);font-weight:800}
tr:last-child td{border-bottom:none}
.badge{font-size:.72rem;font-weight:800;padding:.2rem .55rem;border-radius:999px}
.badge-ok{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok)}
.badge-warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:var(--warn)}
.badge-bad{background:color-mix(in srgb,var(--bad) 16%,transparent);color:var(--bad)}
.flash{padding:.85rem 1.1rem;border-radius:12px;margin-bottom:1.2rem;font-weight:600;font-size:.9rem;border:1px solid}
.flash-ok{background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:color-mix(in srgb,var(--ok) 40%,transparent);color:var(--ok)}
.flash-bad{background:color-mix(in srgb,var(--bad) 12%,transparent);border-color:color-mix(in srgb,var(--bad) 40%,transparent);color:var(--bad)}
.idle-scrim{position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:200;display:none;place-items:center;padding:1.25rem}
.idle-scrim.show{display:grid}
.idle-card{width:100%;max-width:22rem;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.8rem;box-shadow:var(--shadow);text-align:center}
.idle-card .idle-count{font-size:2.4rem;font-weight:800;color:var(--bad);margin:.6rem 0 1rem;font-variant-numeric:tabular-nums}
.idle-card h3{font-size:1.1rem;font-weight:800}
.idle-card p{color:var(--mut);margin-top:.3rem}
.idle-actions{display:flex;gap:.6rem;margin-top:1.3rem}
.idle-actions button{flex:1}
.muted{color:var(--mut)}.stack{display:flex;flex-direction:column;gap:.4rem}
.row-between{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.pager{display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}
.pager-status{font-size:.85rem}
.subject-disclosure summary{outline:none}
.subject-disclosure summary::-webkit-details-marker{display:none}
.subject-disclosure summary::before{content:'\25B8';display:inline-block;width:1rem;color:var(--mut);transition:transform .12s ease}
.subject-disclosure[open] summary::before{transform:rotate(90deg)}
.notif{display:flex;gap:.75rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
.notif:last-child{border-bottom:none}
.notif .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);margin-top:.45rem;flex-shrink:0}
.notif .dot.read{background:var(--mut-dim)}
.notif b{font-size:.9rem}.notif small{color:var(--mut-dim)}

/* ---- auth/bare pages ---- */
.auth-shell{min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem}
.auth-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2rem;box-shadow:var(--shadow)}
.auth-logo{width:56px;height:56px;border-radius:14px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.4rem;margin-bottom:1rem}
.brand{font-weight:800;font-size:1.35rem;letter-spacing:-.01em}.brand em{font-style:normal;color:var(--accent)}

@media(max-width:1000px){
  .shell{grid-template-columns:1fr}
  .side{position:fixed;left:0;top:0;width:264px;z-index:60;transform:translateX(-100%);transition:transform .25s ease}
  .side.open{transform:none}
  .menu-toggle{display:inline-grid}
  .content{padding:1.4rem 1.1rem 3rem}
  .cols-4{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:1fr}.cols-2{grid-template-columns:1fr}
  .scrim{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:55;display:none}
  .scrim.show{display:block}
}
</style>
@stack('head')
</head>
<body>
@hasSection('bare')
  @yield('content')
@else
@php
  $sSlug = isset($school) ? $school->slug : null;
  $cur = request()->route()?->getName();
  $idleSettings = app(\App\Services\Security\IdleTimeoutService::class)->effective();
  $link = function($routeName, $params, $label, $icon, $active=false, $soon=false) {
    return compact('routeName','params','label','icon','active','soon');
  };
@endphp
<div class="shell{{ $portal === 'school' ? ' school-theme' : '' }}" id="shell">
  <aside class="side" id="sideNav">
    <div class="side-brand"><span class="brandfull">SK<em>U</em>LOS</span><span class="brandshort">S<em style="color:var(--accent)"></em></span></div>
    @isset($school)
      <div class="side-school">
        <span class="side-badge">{{ strtoupper(substr($school->name,0,1)) }}</span>
        <div><b>{{ \Illuminate\Support\Str::limit($school->name, 20) }}</b><span>{{ $school->slug }}.{{ config('app.domain') }}</span></div>
      </div>
    @endisset

    <nav class="side-nav">
      @include('layouts.partials.nav-'.$portal, ['school' => $school ?? null, 'cur' => $cur])
    </nav>

    <div class="side-foot">
      @if(isset($school) && \Illuminate\Support\Facades\Route::has('school.profile.edit'))
        <a class="side-foot-link" href="{{ route('school.profile.edit', ['school' => $school->slug]) }}">
          <span class="side-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'U',0,1)) }}</span>
          <div class="who"><b>{{ auth()->user()->name }}</b><span>My profile</span></div>
        </a>
        @if(app(\App\Services\Rbac\Rbac::class)->check(auth()->user(), $school, 'staff.update'))
          <a class="side-foot-approvals" href="{{ route('school.profile.approvals', ['school' => $school->slug]) }}">Profile approvals</a>
        @endif
      @else
        <span class="side-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'U',0,1)) }}</span>
        <div class="who"><b>{{ auth()->user()->name }}</b><span>{{ $portal === 'admin' ? 'Platform admin' : 'Signed in' }}</span></div>
      @endif
    </div>
  </aside>

  <div class="scrim" id="scrim"></div>

  <div class="main">
    @if(session('impersonator_id'))
      <div class="impersonate-bar">
        <span>You are viewing as <strong>{{ auth()->user()->name }}</strong> for support.</span>
        <form method="POST" action="{{ route('session.end-impersonation') }}">
          @csrf
          <button class="btn btn-sm" type="submit">End and return to my account</button>
        </form>
      </div>
    @endif
    <header class="appbar">
      <button class="iconbtn menu-toggle" id="menuToggle" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <button class="iconbtn collapse-btn" id="collapseBtn" aria-label="Collapse menu" title="Collapse menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3v18M4 7l-2 5 2 5"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
      </button>
      <div>
        <h2>@yield('heading', $pageTitle ?? 'Dashboard')</h2>
        @hasSection('crumb')<div class="crumb">@yield('crumb')</div>@endif
      </div>
      <div class="appbar-spacer"></div>
      @if(($portal ?? null) === 'school' && isset($school) && $school)
        <form method="GET" action="{{ route('school.search.index', ['school' => $school->slug]) }}" style="display:flex;align-items:center;gap:.3rem">
          <input type="search" name="q" placeholder="Search students, staff, parents" aria-label="Search" style="width:15rem;padding:.5rem .7rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:.85rem" />
        </form>
      @endif
      @isset($notifCount)
        <span class="iconbtn" title="Notifications">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          @if($notifCount > 0)<span class="badge-dot"></span>@endif
        </span>
      @endisset
      <button class="iconbtn" id="themeToggle" aria-label="Theme">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
      <form method="POST" action="{{ url('/logout') }}">@csrf<button class="btn btn-ghost btn-sm">Sign out</button></form>
    </header>
    <div class="content">
      @if(session('status'))<div class="flash flash-ok">{{ session('status') }}</div>@endif
      @if(session('error'))<div class="flash flash-bad">{{ session('error') }}</div>@endif
      @if($errors->any())
        <div class="flash flash-bad">
          @foreach($errors->all() as $message)
            <div>{{ $message }}</div>
          @endforeach
        </div>
      @endif
      @yield('content')
    </div>
  </div>
</div>

{{-- Broadcast pop-up: on the school portal only (needs an authenticated
     tenant user), surface any unacknowledged broadcast as a modal the user
     acknowledges. Guarded so it never runs on the admin portal or bare
     pages, where there is no tenant Notification table. --}}
@if(($portal ?? null) === 'school' && isset($school) && $school && auth()->check())
  @include('layouts.partials.broadcast-popup', ['school' => $school])
@endif

{{-- Idle session security timeout (user request 2026-07-09, made admin
     configurable 2026-07-11: platform default + optional per-school
     override + an off switch, previously hardcoded). --}}
@if($idleSettings['enabled'])
<div class="idle-scrim" id="idleScrim" role="alertdialog" aria-modal="true" aria-labelledby="idleTitle">
  <div class="idle-card">
    <h3 id="idleTitle">Are you still there?</h3>
    <p>For your security, you will be signed out soon due to inactivity.</p>
    <div class="idle-count" id="idleCount">15</div>
    <div class="idle-actions">
      <button class="btn btn-ghost" type="button" id="idleLogoutBtn">Log out</button>
      <button class="btn btn-primary" type="button" id="idleResumeBtn">Resume</button>
    </div>
  </div>
</div>
<form method="POST" action="{{ url('/logout') }}" id="idleLogoutForm" style="display:none">@csrf</form>
@endif
@endif

<script>
document.getElementById('themeToggle')?.addEventListener('click',function(){
  var r=document.documentElement,cur=r.getAttribute('data-theme')||'light',next=cur==='light'?'dark':'light';
  r.setAttribute('data-theme',next);try{localStorage.setItem('skulos-theme',next)}catch(e){}
});
(function(){
  var t=document.getElementById('menuToggle'),s=document.getElementById('sideNav'),sc=document.getElementById('scrim');
  if(t){
    function close(){s.classList.remove('open');sc.classList.remove('show');}
    t.addEventListener('click',function(){s.classList.toggle('open');sc.classList.toggle('show');});
    sc?.addEventListener('click',close);
  }
  var shell=document.getElementById('shell'),cb=document.getElementById('collapseBtn');
  if(shell&&cb){
    try{ if(localStorage.getItem('skulos-side')==='collapsed') shell.classList.add('collapsed'); }catch(e){}
    cb.addEventListener('click',function(){
      shell.classList.toggle('collapsed');
      try{ localStorage.setItem('skulos-side', shell.classList.contains('collapsed')?'collapsed':'open'); }catch(e){}
    });
  }
  // Keep the selected nav item in view on every full page load (user
  // request 2026-07-11: clicking a menu item further down the list used
  // to lose your place, since each click is a full server navigation and
  // the sidebar always reopened scrolled to the top). "nearest" only
  // scrolls if the active item is actually out of view, so it never
  // jumps unnecessarily when it is already visible.
  var activeLink=document.querySelector('.side-nav .side-link.active');
  activeLink?.scrollIntoView({block:'nearest'});
})();
(function(){
  var scrim=document.getElementById('idleScrim');
  if(!scrim) return; // bare pages (login, etc.) have no authenticated session to guard

  var idleMs=({{ (int) ($idleSettings['idle_seconds'] ?? 30) }})*1000;
  var warnMs=({{ (int) ($idleSettings['warning_seconds'] ?? 15) }})*1000;
  var countEl=document.getElementById('idleCount'),
      resumeBtn=document.getElementById('idleResumeBtn'),
      logoutBtn=document.getElementById('idleLogoutBtn'),
      logoutForm=document.getElementById('idleLogoutForm');
  var idleTimer=null, countdownTimer=null, secondsLeft=0;

  function doLogout(){ logoutForm.submit(); }

  function tickCountdown(){
    secondsLeft--;
    countEl.textContent=secondsLeft;
    if(secondsLeft<=0){ clearInterval(countdownTimer); doLogout(); }
  }

  function showWarning(){
    secondsLeft=Math.round(warnMs/1000);
    countEl.textContent=secondsLeft;
    scrim.classList.add('show');
    countdownTimer=setInterval(tickCountdown,1000);
  }

  function resetIdleTimer(){
    clearTimeout(idleTimer);
    idleTimer=setTimeout(showWarning,idleMs);
  }

  function resume(){
    clearInterval(countdownTimer);
    scrim.classList.remove('show');
    resetIdleTimer();
    fetch('{{ route('session.keep-alive') }}', {
      method:'POST',
      headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'','Accept':'application/json'}
    }).catch(function(){});
  }

  resumeBtn.addEventListener('click',resume);
  logoutBtn.addEventListener('click',doLogout);

  ['mousemove','keydown','click','scroll','touchstart'].forEach(function(evt){
    document.addEventListener(evt,function(){
      if(!scrim.classList.contains('show')) resetIdleTimer();
    },{passive:true});
  });

  resetIdleTimer();
})();
</script>
@stack('scripts')
</body>
</html>
