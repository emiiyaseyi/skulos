<!doctype html>
<html lang="en" @isset($theme) data-theme="{{ $theme }}" @endisset>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<title>@yield('title', 'SKULOS')</title>
<script>
(function(){try{var s=localStorage.getItem('skulos-theme');var t=s||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
<style>
:root{
  --accent: {{ $accent ?? '#E8A33D' }};
  --bg:#F5F7FA; --panel:#FFFFFF; --ink:#0B1420; --mut:rgba(11,20,32,.62); --mut-dim:rgba(11,20,32,.45);
  --line:rgba(11,20,32,.12); --soft:rgba(11,20,32,.04); --ok:#12A97B; --warn:#E5901C; --bad:#E14F63;
  --shadow:0 10px 30px -12px rgba(15,23,35,.18);
  --font:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}
@media (prefers-color-scheme:dark){:root{
  --bg:#0A1420; --panel:#0F1E30; --ink:#F3F5F8; --mut:rgba(243,245,248,.66); --mut-dim:rgba(243,245,248,.42);
  --line:rgba(243,245,248,.13); --soft:rgba(243,245,248,.05); --shadow:0 14px 34px -14px rgba(0,0,0,.55);
}}
:root[data-theme="light"]{--bg:#F5F7FA;--panel:#FFFFFF;--ink:#0B1420;--mut:rgba(11,20,32,.62);--mut-dim:rgba(11,20,32,.45);--line:rgba(11,20,32,.12);--soft:rgba(11,20,32,.04);--shadow:0 10px 30px -12px rgba(15,23,35,.18);}
:root[data-theme="dark"]{--bg:#0A1420;--panel:#0F1E30;--ink:#F3F5F8;--mut:rgba(243,245,248,.66);--mut-dim:rgba(243,245,248,.42);--line:rgba(243,245,248,.13);--soft:rgba(243,245,248,.05);--shadow:0 14px 34px -14px rgba(0,0,0,.55);}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--font);background:var(--bg);color:var(--ink);line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:var(--accent);text-decoration:none}
.wrap{max-width:1140px;margin:0 auto;padding:0 1.25rem}
.topbar{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--panel) 88%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.topbar-row{display:flex;align-items:center;justify-content:space-between;padding:.85rem 0;gap:1rem}
.brand{font-weight:800;font-size:1.35rem;letter-spacing:-.01em;color:var(--ink)}
.brand em{font-style:normal;color:var(--accent)}
.top-actions{display:flex;align-items:center;gap:.6rem}
.chip{font-size:.75rem;font-weight:700;color:var(--mut);background:var(--soft);border:1px solid var(--line);padding:.3rem .6rem;border-radius:999px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.6rem 1.1rem;border-radius:10px;border:1px solid transparent;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;transition:transform .15s ease,background .15s ease,box-shadow .15s ease}
.btn-primary{background:var(--accent);color:#241503}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px -10px color-mix(in srgb,var(--accent) 70%,transparent)}
.btn-ghost{background:transparent;color:var(--ink);border-color:var(--line)}
.btn-ghost:hover{background:var(--soft)}
.btn-sm{padding:.4rem .8rem;font-size:.82rem;border-radius:8px}
.btn-block{width:100%}
.icon-btn{width:2.4rem;height:2.4rem;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer;display:inline-flex;align-items:center;justify-content:center}
main{padding:2rem 0 3rem}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
h1.title{font-size:1.7rem;font-weight:800;letter-spacing:-.01em}
.subtitle{color:var(--mut);font-size:.92rem;margin-top:.2rem}
.grid{display:grid;gap:1.1rem}
.cols-2{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:repeat(3,1fr)}.cols-4{grid-template-columns:repeat(4,1fr)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.4rem;box-shadow:var(--shadow)}
.card h3{font-size:1rem;font-weight:800;margin-bottom:.2rem}
.card p{color:var(--mut);font-size:.9rem}
.stat b{font-size:1.7rem;font-weight:800;font-variant-numeric:tabular-nums;display:block}
.stat span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
label{display:block;font-weight:700;font-size:.82rem;margin-bottom:.35rem}
input,textarea,select{width:100%;padding:.65rem .8rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-family:inherit;font-size:.92rem}
input:focus,textarea:focus,select:focus{outline:2px solid color-mix(in srgb,var(--accent) 55%,transparent);outline-offset:1px;border-color:var(--accent)}
.field{margin-bottom:1rem}
.hint{font-size:.78rem;color:var(--mut-dim);margin-top:.3rem}
.err{color:var(--bad);font-size:.8rem;margin-top:.3rem}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:.7rem .6rem;border-bottom:1px solid var(--line);font-size:.9rem}
th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);font-weight:800}
.badge{font-size:.72rem;font-weight:800;padding:.2rem .55rem;border-radius:999px}
.badge-ok{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok)}
.badge-warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:var(--warn)}
.flash{padding:.85rem 1.1rem;border-radius:12px;margin-bottom:1.2rem;font-weight:600;font-size:.9rem;border:1px solid}
.flash-ok{background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:color-mix(in srgb,var(--ok) 40%,transparent);color:var(--ok)}
.flash-bad{background:color-mix(in srgb,var(--bad) 12%,transparent);border-color:color-mix(in srgb,var(--bad) 40%,transparent);color:var(--bad)}
.auth-shell{min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem}
.auth-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2rem;box-shadow:var(--shadow)}
.auth-logo{width:56px;height:56px;border-radius:14px;background:var(--accent);color:#241503;display:grid;place-items:center;font-weight:800;font-size:1.4rem;margin-bottom:1rem}
.muted{color:var(--mut)}
.stack{display:flex;flex-direction:column;gap:.4rem}
.row-between{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.notif{display:flex;gap:.75rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
.notif:last-child{border-bottom:none}
.notif .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);margin-top:.45rem;flex-shrink:0}
.notif .dot.read{background:var(--mut-dim)}
.notif b{font-size:.9rem}.notif small{color:var(--mut-dim)}
@media(max-width:820px){.cols-4{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:1fr}.cols-2{grid-template-columns:1fr}}
</style>
@stack('head')
</head>
<body>
@hasSection('bare')
  @yield('content')
@else
<header class="topbar">
  <div class="wrap topbar-row">
    <a class="brand" href="{{ $homeUrl ?? url('/') }}">SK<em>U</em>LOS</a>
    <div class="top-actions">
      @auth
        <span class="chip">{{ auth()->user()->name }}</span>
      @endauth
      <button class="icon-btn" id="themeToggle" aria-label="Switch theme" title="Switch theme">◐</button>
      @auth
        <form method="POST" action="{{ url('/logout') }}">@csrf<button class="btn btn-ghost btn-sm">Sign out</button></form>
      @endauth
    </div>
  </div>
</header>
<main>
  <div class="wrap">
    @if(session('status'))<div class="flash flash-ok">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="flash flash-bad">{{ session('error') }}</div>@endif
    @yield('content')
  </div>
</main>
@endif
<script>
document.getElementById('themeToggle')?.addEventListener('click',function(){
  var r=document.documentElement,cur=r.getAttribute('data-theme')||'light',next=cur==='light'?'dark':'light';
  r.setAttribute('data-theme',next);try{localStorage.setItem('skulos-theme',next)}catch(e){}
});
</script>
@stack('scripts')
</body>
</html>
