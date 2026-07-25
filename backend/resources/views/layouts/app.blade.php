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
  /* The accent hue used AS foreground text/icon colour on a light surface
     (a stat card icon, a wordmark letter), not as a fill. var(--accent)
     alone isn't guaranteed readable there the same way it isn't as a
     background fill without --accent-ink: a school that picks a pale
     brand colour makes this text unreadable (audit finding, 2026-07-17).
     Darkened toward black in light mode, lightened toward white in dark
     mode, so it always has real contrast against its surface regardless
     of how light or dark the chosen accent itself is. */
  --accent-text: color-mix(in srgb, var(--accent) 62%, black);
}
@media (prefers-color-scheme:dark){:root{
  --bg:#070C14; --panel:#0E1B2B; --ink:#F3F5F8; --mut:rgba(243,245,248,.62); --mut-dim:rgba(243,245,248,.40);
  --line:rgba(243,245,248,.10); --soft:rgba(243,245,248,.04); --shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);
  --side:#0A1420; --accent-text: color-mix(in srgb, var(--accent) 70%, white);
}}
:root[data-theme="light"]{--bg:#EEF1F6;--panel:#FFFFFF;--ink:#0B1420;--mut:rgba(11,20,32,.60);--mut-dim:rgba(11,20,32,.42);--line:rgba(11,20,32,.10);--soft:rgba(11,20,32,.035);--shadow:0 1px 2px rgba(15,23,35,.06),0 8px 24px -14px rgba(15,23,35,.18);--side:#0C1826;--accent-text: color-mix(in srgb, var(--accent) 62%, black);}
:root[data-theme="dark"]{--bg:#070C14;--panel:#0E1B2B;--ink:#F3F5F8;--mut:rgba(243,245,248,.62);--mut-dim:rgba(243,245,248,.40);--line:rgba(243,245,248,.10);--soft:rgba(243,245,248,.04);--shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);--side:#0A1420;--accent-text: color-mix(in srgb, var(--accent) 70%, white);}

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
  /* Was #4A6B3A/#B5731A/#B23A2A, a genuinely muted, low saturation set:
     the dark mode brightening above fixed unreadable, this is a separate
     complaint (user report 2026-07-16, after the dark mode fix: "THIS
     DESIGN ISSUES WAS NOT FIXED", a screenshot of the same pale badge
     look in light mode) that the light mode badges themselves still read
     as weak/faded even where they are technically legible, so both
     themes now use the same vivid, more saturated hue family instead of
     two different tuned sets. */
  --ok:#1E9E5A; --warn:#C97A0A; --bad:#D93A2B;
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
  /* The light-paper --ok/--warn/--bad (#4A6B3A/#B5731A/#B23A2A) read as
     near-black-on-black here, both as badge/flash text and as the
     translucent tint of their own pill background (user report
     2026-07-16, screenshot: "in dark mode the green text colour is
     poor"). Brightened for this background, same hue family so a badge
     still reads as ok/warn/bad at a glance, not flattened to one colour. */
  --ok:#52C78A; --warn:#E5A83D; --bad:#E86A5C;
}}
:root[data-theme="light"] .school-theme{--bg:#FFFFFF;--panel:#FFFFFF;--shell:#FFFFFF;--ink:#1A1613;--mut:rgba(26,22,19,.68);--mut-dim:rgba(26,22,19,.58);--line:#E4D9BE;--line-deep:#C9B98F;--soft:rgba(26,22,19,.035);--side-mut:rgba(26,22,19,.62);--side-active:#F5EEDC;--shadow:0 1px 2px rgba(26,22,19,.07),0 10px 26px -16px rgba(26,22,19,.22);--ok:#1E9E5A;--warn:#C97A0A;--bad:#D93A2B;}
:root[data-theme="dark"] .school-theme{--bg:#171310;--panel:#211C17;--shell:#171310;--ink:#F0E9D8;--mut:rgba(240,233,216,.68);--mut-dim:rgba(240,233,216,.55);--line:rgba(240,233,216,.10);--line-deep:rgba(240,233,216,.18);--soft:rgba(240,233,216,.04);--side-mut:rgba(240,233,216,.62);--side-active:#211C17;--shadow:0 1px 2px rgba(0,0,0,.4),0 12px 30px -16px rgba(0,0,0,.6);--ok:#52C78A;--warn:#E5A83D;--bad:#E86A5C;}

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
/* Unlike the platform admin portal, this theme's sidebar (--side) is
   light paper, not dark navy, so the plain var(--accent) used as text
   there (fine on admin's dark sidebar) needs the same darkened treatment
   --accent-text gives it everywhere else on a light surface (audit
   finding, 2026-07-17). Scoped to .school-theme only so admin's already-
   readable dark-sidebar accent text is untouched. */
.school-theme .side-brand em,.school-theme .side-link.active .ic{color:var(--accent-text)}

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
.side-search{position:relative;margin:.1rem 1rem .7rem}
{{-- input[type="search"] here, not just "input" (found 2026-07-22, same
     root cause already fixed once on .ss-search input): the platform-wide
     field rule `input:not([type="checkbox"]):not([type="radio"])` has two
     :not() clauses, each counting as an attribute selector, so its
     specificity (0,2,1) already beat a plain ".side-search input" (0,1,1),
     silently winning the padding fight and squashing this search box's
     text/cursor back into the icon too ("THE SEARCH BAR STILL HAS THE
     ICON, TEXT BLINKER AND TEXT OVERLAPPING OVER THEMSELVES"). --}}
.side-search input[type="search"]{width:100%;background:var(--side-active);border:1px solid var(--side-line);border-radius:10px;padding:.5rem .7rem .5rem 2.15rem;font-size:.82rem;color:var(--side-ink);font-family:inherit}
.side-search input::placeholder{color:var(--side-mut)}
.side-search input:focus{outline:2px solid var(--accent);outline-offset:1px}
.side-search input::-webkit-search-cancel-button{cursor:pointer}
.side-search-ic{position:absolute;left:.68rem;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--side-mut);pointer-events:none}
.side-search-ic svg{width:100%;height:100%}
.side-search-empty{display:none;padding:.3rem .9rem 1rem;font-size:.78rem;color:var(--side-mut)}
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
.shell.collapsed .side-search{display:none}
.shell.collapsed .side-school div,.shell.collapsed .side-group,.shell.collapsed .side-group-btn,.shell.collapsed .side-link span:not(.ic),.shell.collapsed .side-link .soon,.shell.collapsed .side-foot .who{display:none}
.shell.collapsed .side-submenu{grid-template-rows:1fr}
.shell.collapsed .side-nav{padding-inline:.55rem}
.shell.collapsed .side-link{justify-content:center;padding-inline:0}
.shell.collapsed .side-foot{justify-content:center}
.brandshort{display:none}
.shell.collapsed .brandshort{display:inline}
.collapse-btn{display:inline-grid}
/* .appbar .collapse-btn (two classes) so this reliably beats the later,
   equal-specificity, unconditional .iconbtn{display:inline-grid} rule
   below — a plain .collapse-btn{display:none} here lost that fight since
   source order decides ties and .iconbtn comes after it, so this button
   (a desktop-only "collapse the sidebar to icons" toggle, meaningless
   once the sidebar is already an off-canvas drawer on mobile) stayed
   visible right next to the real menu button on a phone (user report
   2026-07-25, screenshot showing both side by side: "maybe we dont need
   it in mobile view"). */
@media(max-width:1000px){.appbar .collapse-btn{display:none}}
.impersonate-bar{position:sticky;top:0;z-index:35;background:var(--warn);color:#241503;display:flex;align-items:center;justify-content:center;gap:1rem;padding:.55rem 1rem;font-size:.85rem;font-weight:600;flex-wrap:wrap;text-align:center}
.impersonate-bar .btn{background:#241503;color:#fff;border:none;padding:.35rem .8rem;border-radius:8px;font-weight:700}
.appbar{position:sticky;top:0;z-index:30;background:color-mix(in srgb,var(--panel) 90%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:1rem;padding:.8rem 1.6rem}
/* min-width:0 overrides the flex default (min-width:auto), which refuses
   to shrink a flex item below its content's natural width — without it
   this title and the fixed width search input below force the whole
   appbar wider than the viewport on a phone, a horizontal scroll bug
   found live 2026-07-25 across nearly every page. */
.appbar-title{min-width:0;flex:1 1 auto;overflow:hidden}
.appbar h2{font-size:1rem;font-weight:800;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.appbar .crumb{font-size:.78rem;color:var(--mut-dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.appbar-spacer{flex:1}
{{--
  !important on width here and in the two overrides below: a global rule
  earlier in this same stylesheet (input:not([type=checkbox])
  :not([type=radio]){width:100%}) has higher specificity than a single
  class selector (two :not() attribute selectors plus the input type
  beat one plain class), so it silently won over every one of these
  width rules regardless of source order or media query, and the search
  box never actually shrank on a phone no matter how small a value was
  set here (found live 2026-07-25 by reading the element's own computed
  style: it reported 160px, matching neither the desktop nor the phone
  rule below).
--}}
.appbar-search{width:15rem !important;min-width:0;padding:.5rem .7rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-size:.85rem}
.appbar-signout{display:inline-flex;align-items:center;gap:.4rem}
.appbar-signout-icon{display:none}
.iconbtn{width:2.75rem;height:2.75rem;border-radius:10px;border:1px solid var(--line);background:var(--panel);color:var(--ink);cursor:pointer;display:inline-grid;place-items:center;position:relative}
.iconbtn:hover{background:var(--soft)}
.iconbtn .badge-dot{position:absolute;top:6px;right:7px;width:8px;height:8px;border-radius:50%;background:var(--bad);border:2px solid var(--panel)}
.notif-bell{position:relative}
.notif-bell > summary{list-style:none;cursor:pointer}
.notif-bell > summary::-webkit-details-marker{display:none}
.notif-bell-panel{position:absolute;top:calc(100% + 8px);right:0;width:20rem;max-width:calc(100vw - 2rem);max-height:24rem;overflow-y:auto;z-index:80;background:var(--panel);border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:.7rem}
.notif-bell-panel .notif{padding:.55rem 0}
.menu-toggle{display:none}
.content{padding:1.9rem 1.6rem 3rem}

/* ---- primitives ---- */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
h1.title{font-size:1.55rem;font-weight:800;letter-spacing:-.01em}
.subtitle{color:var(--mut);font-size:.92rem;margin-top:.2rem}
.grid{display:grid;gap:1.1rem}
.grid > *{min-width:0}
.cols-2{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:repeat(3,1fr)}.cols-4{grid-template-columns:repeat(4,1fr)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:1.4rem;box-shadow:var(--shadow)}
.card h3{font-size:1rem;font-weight:800;margin-bottom:.2rem}
.card p{color:var(--mut);font-size:.9rem}
.stat-card{display:flex;align-items:center;gap:1rem}
.stat-ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:color-mix(in srgb,var(--accent) 15%,transparent);color:var(--accent-text);flex-shrink:0}
.stat-ic svg{width:22px;height:22px}
.stat b{font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;display:block;line-height:1.1}
.stat span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
label{display:block;font-weight:700;font-size:.82rem;margin-bottom:.35rem}
input:not([type="checkbox"]):not([type="radio"]),textarea,select{width:100%;padding:.65rem .8rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-family:inherit;font-size:.92rem}
input:focus,textarea:focus,select:focus{outline:2px solid color-mix(in srgb,var(--accent) 55%,transparent);outline-offset:1px;border-color:var(--accent)}
/* A plain checkbox/radio must never inherit the text-input width:100%
   rule above (found 2026-07-19: it silently turned every checkbox in the
   app into a huge, heavily padded box with the tiny native glyph
   floating somewhere inside it, e.g. Notifications' bulk-select
   checkboxes). Sized to its native small footprint, everywhere. */
input[type="checkbox"],input[type="radio"]{width:1.05rem;height:1.05rem;flex-shrink:0;accent-color:var(--accent)}
.field{margin-bottom:1rem}
/* Searchable select (platform-wide directive 2026-07-20: every long list
   anywhere in the app, country/state/LGA, staff/student/parent/role
   pickers, gets a type-to-filter combobox, not a plain native <select> a
   person has to scroll through). Auto-applied app-wide by the script near
   the end of this file, no per-page wiring, so it reaches every current
   AND future long select without a manual sweep. The original <select>
   stays in the DOM (visually hidden, tabindex=-1) as the real form field;
   this is a pure progressive enhancement over it. */
.ss-combo{position:relative}
.ss-combo select.ss-native{position:absolute;opacity:0;width:1px;height:1px;overflow:hidden;pointer-events:none}
.ss-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.65rem .8rem;border-radius:10px;border:1px solid var(--line);background:var(--bg);color:var(--ink);font-family:inherit;font-size:.92rem;text-align:left;cursor:pointer}
.ss-toggle:focus{outline:2px solid color-mix(in srgb,var(--accent) 55%,transparent);outline-offset:1px;border-color:var(--accent)}
.ss-toggle[aria-expanded="true"]{border-color:var(--accent)}
.ss-toggle:disabled{opacity:.55;cursor:not-allowed}
.ss-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ss-label.ss-placeholder{color:var(--mut-dim);font-weight:400}
.ss-caret{width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:6px solid var(--mut);flex-shrink:0;margin-left:.5rem}
.ss-panel{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:80;background:var(--panel);border:1px solid var(--line);border-radius:10px;box-shadow:var(--shadow);padding:.4rem;display:flex;flex-direction:column}
/* .ss-panel's own display:flex has the same CSS specificity as the
   browser's default [hidden]{display:none}, so the author rule silently
   won and panel.hidden=true in JS never actually hid anything (found live
   2026-07-21: "the dropdown close when one select an item... but the
   search bar is still showing"). This class+attribute selector outranks
   the plain class rule above, restoring hidden's real effect. */
.ss-panel[hidden]{display:none}
.ss-search{position:relative;margin-bottom:.4rem}
{{-- input[type="text"] here, not just "input" (found 2026-07-21): the
     platform-wide field rule `input:not([type="checkbox"]):not([type="radio"])`
     has two :not() clauses, each counting as an attribute selector, so its
     specificity (0,2,1) already beat a plain ".ss-search input" (0,1,1),
     silently winning the padding-left fight and squashing the search box's
     text/cursor back into the icon. Matching that specificity (0,2,1) here
     and relying on source order (this rule is declared later) restores it,
     the same tie-breaking approach already used for .ss-panel[hidden]. --}}
.ss-search input[type="text"]{padding-left:2.15rem;margin:0}
.ss-search::before{content:'';position:absolute;left:.72rem;top:50%;width:.7rem;height:.7rem;border:2px solid var(--mut-dim);border-radius:50%;transform:translateY(-65%);pointer-events:none}
.ss-search::after{content:'';position:absolute;left:1.32rem;top:50%;width:.42rem;height:2px;background:var(--mut-dim);transform:translateY(45%) rotate(45deg);pointer-events:none}
.ss-list{list-style:none;margin:0;padding:0;overflow-y:auto;max-height:13rem}
.ss-list li[role="option"]{padding:.5rem .6rem;border-radius:8px;font-size:.88rem;cursor:pointer}
.ss-list li[role="option"]:hover,.ss-list li[role="option"].ss-active{background:var(--soft)}
.ss-list li[aria-selected="true"]{font-weight:700;color:var(--accent-text)}
.ss-list .ss-empty{padding:.5rem .6rem;font-size:.85rem;color:var(--mut-dim);cursor:default}
.hint{font-size:.78rem;color:var(--mut-dim);margin-top:.3rem}
.err{color:var(--bad);font-size:.8rem;margin-top:.3rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.6rem 1.1rem;border-radius:10px;border:1px solid transparent;font-weight:700;font-size:.9rem;cursor:pointer;font-family:inherit;transition:transform .15s ease,background .15s ease,box-shadow .15s ease}
.btn-primary{background:var(--accent);color:var(--accent-ink)}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px -10px color-mix(in srgb,var(--accent) 70%,transparent)}
.btn-ghost{background:transparent;color:var(--ink);border-color:var(--line)}
.btn-ghost:hover{background:var(--soft)}
.btn-sm{padding:.4rem .8rem;font-size:.82rem;border-radius:8px;min-height:2.75rem}
.btn-block{width:100%}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:.8rem .6rem;border-bottom:1px solid var(--line);font-size:.9rem}
th{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);font-weight:800}
tr:last-child td{border-bottom:none}
.badge{font-size:.72rem;font-weight:800;padding:.2rem .55rem;border-radius:999px;white-space:nowrap;display:inline-block}
/* Same-hue-on-pale-tint text fails WCAG AA in light mode (~2.7-3.7:1
   against the ~4.5:1 a chip this small needs), the "faded badge" user
   report was fixed for dark mode only, not light (audit finding,
   2026-07-17). Darkened toward black for guaranteed contrast against the
   pale tint background; the dark-mode override below (white on a
   stronger tint) is unaffected. */
.badge-ok{background:color-mix(in srgb,var(--ok) 18%,transparent);color:color-mix(in srgb,var(--ok) 55%,black)}
.badge-warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:color-mix(in srgb,var(--warn) 55%,black)}
.badge-bad{background:color-mix(in srgb,var(--bad) 18%,transparent);color:color-mix(in srgb,var(--bad) 55%,black)}
/* Dark mode: white text on a fuller-strength tinted pill, not the same
   hue for both text and background (user report, twice: "in dark mode
   the green text colour is poor", then again "the wrong choice of green
   text colour in dark mode" after a same-hue brightening pass still
   wasn't what was wanted). Same-hue-on-tint is the harder contrast case
   to get right regardless of how bright the hue is; white on a stronger
   tint is the standard status-chip pattern and reads cleanly against
   this app's near-black dark backgrounds. Background strength bumped
   from ~16-18% to 35% to keep the badge visually anchored now that the
   text itself carries none of the status colour. */
@media(prefers-color-scheme:dark){
  .badge-ok{background:color-mix(in srgb,var(--ok) 35%,transparent);color:#fff}
  .badge-warn{background:color-mix(in srgb,var(--warn) 35%,transparent);color:#fff}
  .badge-bad{background:color-mix(in srgb,var(--bad) 35%,transparent);color:#fff}
}
:root[data-theme="dark"] .badge-ok{background:color-mix(in srgb,var(--ok) 35%,transparent);color:#fff}
:root[data-theme="dark"] .badge-warn{background:color-mix(in srgb,var(--warn) 35%,transparent);color:#fff}
:root[data-theme="dark"] .badge-bad{background:color-mix(in srgb,var(--bad) 35%,transparent);color:#fff}
/* The dark-mode rules above only ever checked the OS/browser's actual
   colour preference, never whether the viewer had explicitly picked
   "light" with the theme toggle (user report 2026-07-18: badges looked
   "faded" and washed out). An OS set to dark with the app explicitly set
   to light matched the @media query above with no counteracting rule to
   win it back, applying dark mode's white-text-on-a-weak-tint formula on
   top of a light (white/near-white) panel, a low-contrast mismatch, not
   the intended light-mode look at all. Explicit here for the same
   attribute-selector-beats-bare-media-query reason :root[data-theme="dark"]
   above already relies on. */
:root[data-theme="light"] .badge-ok{background:color-mix(in srgb,var(--ok) 18%,transparent);color:color-mix(in srgb,var(--ok) 55%,black)}
:root[data-theme="light"] .badge-warn{background:color-mix(in srgb,var(--warn) 18%,transparent);color:color-mix(in srgb,var(--warn) 55%,black)}
:root[data-theme="light"] .badge-bad{background:color-mix(in srgb,var(--bad) 18%,transparent);color:color-mix(in srgb,var(--bad) 55%,black)}
.flash{padding:.85rem 1.1rem;border-radius:12px;margin-bottom:1.2rem;font-weight:600;font-size:.9rem;border:1px solid}
/* Same fix as .badge above: darkened text for guaranteed contrast in
   light mode (audit finding, 2026-07-17), this is the payment
   submission confirmation/error text, the most important feedback on
   the Finance screens. Dark mode explicitly kept on the plain, already
   bright var(--ok)/var(--bad) it always used, unaffected by this. */
.flash-ok{background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:color-mix(in srgb,var(--ok) 40%,transparent);color:color-mix(in srgb,var(--ok) 55%,black)}
.flash-bad{background:color-mix(in srgb,var(--bad) 12%,transparent);border-color:color-mix(in srgb,var(--bad) 40%,transparent);color:color-mix(in srgb,var(--bad) 55%,black)}
@media(prefers-color-scheme:dark){.flash-ok{color:var(--ok)}.flash-bad{color:var(--bad)}}
:root[data-theme="dark"] .flash-ok{color:var(--ok)}
:root[data-theme="dark"] .flash-bad{color:var(--bad)}
/* Same explicit-theme-vs-OS-preference gap as .badge above: without
   these, an OS set to dark with the app explicitly set to light kept the
   plain bright var(--ok)/var(--bad) text meant for a dark panel, faded
   and low-contrast against light mode's white/near-white background. */
:root[data-theme="light"] .flash-ok{color:color-mix(in srgb,var(--ok) 55%,black)}
:root[data-theme="light"] .flash-bad{color:color-mix(in srgb,var(--bad) 55%,black)}
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
.notif{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem .75rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
.notif:last-child{border-bottom:none}
.notif .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);margin-top:.45rem;flex-shrink:0}
.notif .dot.read{background:var(--mut-dim)}
.notif b{font-size:.9rem}.notif small{color:var(--mut-dim)}
.notif > div{min-width:0;overflow-wrap:break-word}
.notif > .badge{flex-shrink:0;margin-left:auto}

/* ---- auth/bare pages ---- */
.auth-shell{min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem}
.auth-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2rem;box-shadow:var(--shadow)}
.auth-logo{width:56px;height:56px;border-radius:14px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.4rem;margin-bottom:1rem}
.brand{font-weight:800;font-size:1.35rem;letter-spacing:-.01em}.brand em{font-style:normal;color:var(--accent-text)}

@media(max-width:1000px){
  .shell{grid-template-columns:1fr}
  .side{position:fixed;left:0;top:0;width:264px;z-index:60;transform:translateX(-100%);transition:transform .25s ease}
  .side.open{transform:none}
  .menu-toggle{display:inline-grid}
  .content{padding:1.4rem 1.1rem 3rem}
  .cols-4{grid-template-columns:repeat(2,1fr)}.cols-3{grid-template-columns:1fr}.cols-2{grid-template-columns:1fr}
  .scrim{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:55;display:none}
  .scrim.show{display:block}
  .appbar-search{width:9rem !important}
}
/* Phone width (found live 2026-07-25, screenshots of the appbar's search
   box, bell, theme toggle and sign out button running off the right edge
   of the screen on a real phone): the spacer that pushes icons to the
   right on a wide desktop window only wastes space here, and the search
   box shrinks again, small enough to tap and still open the real search
   page but no longer fighting the title for room. */
@media(max-width:480px){
  .appbar{gap:.2rem;padding:.6rem .5rem}
  .appbar-spacer{display:none}
  .appbar-search{width:3.2rem !important;padding:.45rem .3rem}
  .appbar .iconbtn{width:1.8rem;height:1.8rem;border-radius:7px}
  .appbar .iconbtn svg{width:13px;height:13px}
  .appbar h2{font-size:.85rem}
  /* Sign out as an icon, same square footprint as the other appbar
     buttons, not a text button (user report 2026-07-25: the text wrapped
     to two lines, "Sign" over "out", making the button visibly taller
     than everything beside it). Desktop is untouched, this is scoped to
     the same breakpoint as the rest of this mobile header work. */
  .appbar-signout{width:1.8rem;height:1.8rem;padding:0;justify-content:center;border-radius:7px}
  .appbar-signout-icon{display:inline-flex}
  .appbar-signout-icon svg{width:13px;height:13px}
  .appbar-signout-text{display:none}
  /* right:0 anchors the panel to the bell icon's own right edge, correct
     when the bell is the last icon in the row, but the theme toggle and
     sign out button sit after it here, so the bell is not actually flush
     with the screen edge, and the panel opened to the left far enough to
     run off the left edge of the screen (user report 2026-07-25,
     screenshot: the panel's own "N" in "Notifications" clipped off).
     Anchoring to the viewport itself instead of the bell keeps it fully
     on screen regardless of where the bell sits in the row. */
  .notif-bell-panel{position:fixed;top:4.2rem;left:.8rem;right:.8rem;width:auto;max-width:none}
}
/* Mobile friendly data tables ("genuinely usable on a phone, not just
   responsive-looking" standing rule): a wide, many-column table (score
   entry, class broadsheets, ...) is a real chore to use one-handed even
   with a horizontal scroll wrapper. Below true phone width, a table
   opted into .table-stack collapses from a side-scrolled grid into one
   card per row, each cell read top to bottom labelled by the column its
   header would have shown (thead is hidden, so that label has to come
   from data-label="..." set per <td> in the view instead). Opt in only
   where a table is genuinely wide, a plain 2-3 column table already
   reads fine as a normal scrollable table and does not need this. */
@media(max-width:640px){
  /* A 4 card stat row still crams down to an awkward 2x2 at the 1000px
     rule above (2 narrow columns, a longer value like a student's name
     wraps across several lines, screenshot-verified 2026-07-22 on the
     Term Broadsheets insight cards). One column each below true phone
     width, matching the same collapse .cols-3/.cols-2 already get. */
  .cols-4{grid-template-columns:1fr}
  /* The messaging FAB (messaging-widget.blade.php's .mw-fab) is a fixed
     bottom-right circle, 2.8rem tall with a 1rem offset from the viewport
     edge at this same breakpoint, a ~3.8rem footprint the page's own
     3rem bottom padding does not fully clear, screenshot-verified
     2026-07-22 sitting on top of the first card on a long scrolled page.
     Extra clearance here, not a change to the FAB itself, since every
     page's content area sharing this one padding rule is a more durable
     fix than chasing down every screen whose last element could land in
     that corner. */
  .content{padding-bottom:5.5rem}
  .table-stack, .table-stack tbody, .table-stack tr, .table-stack td{display:block;width:100%}
  .table-stack thead{display:none}
  .table-stack tr{border:1px solid var(--line);border-radius:12px;padding:.8rem;margin-bottom:.8rem}
  .table-stack tr:last-child{margin-bottom:0}
  .table-stack td{border-bottom:none;padding:.35rem 0;display:flex;align-items:center;justify-content:flex-end;gap:.6rem;text-align:right}
  .table-stack td[data-label]::before{content:attr(data-label);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);font-weight:800;text-align:left;margin-right:auto}
  .table-stack td:not([data-label]){justify-content:flex-start}
  .table-stack td select, .table-stack td input, .table-stack td textarea{max-width:11rem}
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
  // Subscription countdown popup (user request 2026-07-17: "pops up on
  // the final 21 days to the due date daily"), only for the 5 named
  // school-side roles, computed once here since every school portal
  // page shares this layout. Client side (localStorage) decides whether
  // it has already been shown today, this only ever supplies the real
  // data; a day it should not show at all (no due date, outside the
  // window, wrong role) renders nothing, never a fake countdown.
  $subscriptionPopup = null;
  if ($portal === 'school' && isset($school) && auth()->user() && $cur !== 'school.subscription.index'
      && in_array(optional(auth()->user()->roleIn($school))->slug, config('skulos.subscription_visible_role_slugs', []), true)) {
    $subs = app(\App\Services\Billing\SubscriptionService::class);
    if ($subs->isWithinReminderWindow($school)) {
      $subscriptionPopup = [
        'daysUntilDue' => $subs->daysUntilDue($school),
        'amountDue' => $subs->amountDue($school),
        'dueDate' => $school->next_billing_date?->format('d M Y'),
      ];
    }
  }
  // Self-hosted license grace-period banner (user request 2026-07-18):
  // shown for the whole school while a failed/unreachable check-in's
  // grace period is still running, so the school gets a disclosed
  // warning before App\Services\Licensing\LicenseService locks the
  // portal out entirely. Only ever true on an actual self-hosted
  // deployment (config('skulos.self_hosted')), never on the main
  // platform.
  $licenseGraceDaysLeft = (config('skulos.self_hosted') && $portal === 'school' && isset($school) && $school->license_grace_started_at)
    ? app(\App\Services\Licensing\LicenseService::class)->graceDaysRemaining($school)
    : null;
@endphp
<div class="shell{{ $portal === 'school' ? ' school-theme' : '' }}" id="shell">
  <aside class="side" id="sideNav">
    <div class="side-brand"><span class="brandfull">SK<em>U</em>LOS</span><span class="brandshort">S<em style="color:var(--accent-text)"></em></span></div>
    @isset($school)
      <div class="side-school">
        @if($portal === 'school')
          <button type="button" id="navToggleAllBtn" class="side-toggle-all" aria-expanded="false" aria-label="Expand or collapse all menu sections">
            <svg class="chev-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
          </button>
        @endif
        <span class="side-badge">{{ strtoupper(substr($school->name,0,1)) }}</span>
        <div><b>{{ \Illuminate\Support\Str::limit($school->name, 20) }}</b><span>{{ $school->slug }}.{{ config('app.domain') }}</span></div>
      </div>
    @endisset

    <div class="side-search">
      <span class="side-search-ic">@include('layouts.partials.icon', ['name' => 'search'])</span>
      <input type="search" id="navSearchInput" placeholder="Search menus and features" aria-label="Search menus and features">
    </div>
    <div class="side-search-empty" id="navSearchEmpty">No menus or features match your search.</div>

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
        {{-- A relative path, not route('session.end-impersonation'): that
             route now lives inside the school-subdomain group (2026-07-21
             impersonation fix) and needs a 'school' domain parameter this
             shared layout cannot always supply (this banner only ever
             renders on a school portal page, but the layout itself is
             also used by the platform admin portal, where no school is in
             scope). The browser already submits a relative action to
             whatever host this page is actually on, which is exactly the
             one host this route needs to run on. --}}
        <form method="POST" action="/session/end-impersonation">
          @csrf
          <button class="btn btn-sm" type="submit">End and return to my account</button>
        </form>
      </div>
    @endif
    @if($licenseGraceDaysLeft !== null)
      <div class="impersonate-bar" style="background:var(--bad);color:#fff">
        <span>Your SKULOS license could not be verified. Access will lock in {{ $licenseGraceDaysLeft }} day{{ $licenseGraceDaysLeft === 1 ? '' : 's' }} unless this is resolved. Contact SKULOS to fix this.</span>
      </div>
    @endif
    <header class="appbar">
      <button class="iconbtn menu-toggle" id="menuToggle" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
      </button>
      <button class="iconbtn collapse-btn" id="collapseBtn" aria-label="Collapse menu" title="Collapse menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3v18M4 7l-2 5 2 5"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
      </button>
      <div class="appbar-title">
        <h2>@yield('heading', $pageTitle ?? 'Dashboard')</h2>
        @hasSection('crumb')<div class="crumb">@yield('crumb')</div>@endif
      </div>
      <div class="appbar-spacer"></div>
      @if(($portal ?? null) === 'school' && isset($school) && $school)
        <form method="GET" action="{{ route('school.search.index', ['school' => $school->slug]) }}" style="display:flex;align-items:center;gap:.3rem">
          <input type="search" name="q" placeholder="Search students, staff, parents" aria-label="Search" class="appbar-search" />
        </form>
      @endif
      @if(($portal ?? null) === 'school' && isset($school) && $school && auth()->check())
        @include('layouts.partials.notification-bell', ['school' => $school])
      @endif
      <button class="iconbtn" id="themeToggle" aria-label="Theme">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
      </button>
      <form method="POST" action="{{ url('/logout') }}">
        @csrf
        <button class="btn btn-ghost btn-sm appbar-signout" aria-label="Sign out" title="Sign out">
          <svg class="appbar-signout-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
          <span class="appbar-signout-text">Sign out</span>
        </button>
      </form>
    </header>
    <div class="content">
      @if(session('status'))<div class="flash flash-ok" role="status" aria-live="polite">{{ session('status') }}</div>@endif
      @if(session('error'))<div class="flash flash-bad" role="alert">{{ session('error') }}</div>@endif
      @if($errors->any())
        <div class="flash flash-bad" role="alert">
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
<script>
/**
 * Shared focus management for the three modal-like overlays (broadcast
 * pop-up, subscription pop-up, idle-timeout warning): each declared
 * role="alertdialog" aria-modal="true" but never actually moved focus
 * into itself, trapped Tab, or offered Escape, so a keyboard/screen-
 * reader user's focus and Tab order stayed on the visually-hidden page
 * behind the scrim (audit finding H-6/M-8, 2026-07-17). Defined here,
 * before any popup partial is included below, so every popup's own
 * inline script can call it immediately without waiting for
 * DOMContentLoaded.
 */
window.SkulosDialog = {
  open: function(scrim, opts) {
    opts = opts || {};
    var previouslyFocused = document.activeElement;
    var focusableSelector = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';
    function focusables() {
      return Array.prototype.slice.call(scrim.querySelectorAll(focusableSelector))
        .filter(function(el){ return el.offsetParent !== null; });
    }
    var initial = (opts.autofocus && typeof opts.autofocus.focus === 'function') ? opts.autofocus : focusables()[0];
    initial?.focus();

    function onKeydown(e) {
      if (e.key === 'Escape') { if (opts.onEscape) opts.onEscape(); return; }
      if (e.key !== 'Tab') return;
      var list = focusables();
      if (!list.length) return;
      var first = list[0], last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    scrim.addEventListener('keydown', onKeydown);

    return function close() {
      scrim.removeEventListener('keydown', onKeydown);
      if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus();
    };
  }
};
</script>
@if(($portal ?? null) === 'school' && isset($school) && $school && auth()->check())
  @include('layouts.partials.broadcast-popup', ['school' => $school])
  @include('layouts.partials.messaging-widget', ['school' => $school])
  @include('layouts.partials.subscription-popup', ['school' => $school, 'subscriptionPopup' => $subscriptionPopup])
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
  // Nav search (user directive, asked three times: "ADD A SEARCH BOX
  // ABOVE THE MENU BAR WHERE ONE CAN SEARCH FOR MENUS AND FEATURES TO
  // EASILY LOCATE THE FEATURE/MENU ONE IS LOOKING FOR"). Plain client
  // side text filter over the already rendered nav, no server round
  // trip, works the same for the school portal's collapsible grouped
  // menu and the platform admin portal's flat list, since both share
  // the same clickable nav link markup underneath.
  (function(){
    var input = document.getElementById('navSearchInput');
    var nav = document.querySelector('.side-nav');
    var empty = document.getElementById('navSearchEmpty');
    if (!input || !nav) return;
    var links = Array.prototype.slice.call(nav.querySelectorAll('.side-link'));
    links.forEach(function(link){
      var soon = link.querySelector('.soon');
      var text = link.textContent || '';
      if (soon) text = text.replace(soon.textContent, '');
      link.dataset.navLabel = text.trim().toLowerCase();
    });
    var sections = Array.prototype.slice.call(nav.querySelectorAll('.side-section'));
    var dividers = Array.prototype.slice.call(nav.querySelectorAll('.side-group'));

    function reset(){
      links.forEach(function(l){ l.style.display = ''; });
      sections.forEach(function(s){ s.style.display = ''; });
      dividers.forEach(function(d){ d.style.display = ''; });
      if (empty) empty.style.display = 'none';
    }

    input.addEventListener('input', function(){
      var q = input.value.trim().toLowerCase();
      if (!q) { reset(); return; }

      var anyVisible = false;
      links.forEach(function(l){
        var match = l.dataset.navLabel.indexOf(q) !== -1;
        l.style.display = match ? '' : 'none';
        if (match) anyVisible = true;
      });

      // Grouped, collapsible school nav: hide a whole group with no
      // match, force open one that has any.
      sections.forEach(function(section){
        var visible = Array.prototype.slice.call(section.querySelectorAll('.side-link'))
          .some(function(l){ return l.style.display !== 'none'; });
        section.style.display = visible ? '' : 'none';
        if (visible) {
          var panel = section.querySelector('.side-submenu');
          if (panel) panel.classList.add('open');
        }
      });

      // Flat admin nav: a .side-group divider has no wrapping element,
      // just sits before its own run of .side-link siblings up to the
      // next divider, so walk siblings to decide if it has a match.
      dividers.forEach(function(div, i){
        var stopAt = dividers[i + 1];
        var visible = false;
        var sib = div.nextElementSibling;
        while (sib && sib !== stopAt) {
          if (sib.classList.contains('side-link') && sib.style.display !== 'none') { visible = true; break; }
          sib = sib.nextElementSibling;
        }
        div.style.display = visible ? '' : 'none';
      });

      if (empty) empty.style.display = anyVisible ? 'none' : '';
    });
  })();

  // Keep the selected nav item in view on every full page load (user
  // request 2026-07-11: clicking a menu item further down the list used
  // to lose your place, since each click is a full server navigation and
  // the sidebar always reopened scrolled to the top).
  //
  // Switched from block:'nearest' to block:'start' (user report
  // 2026-07-22: "when the menu opens, the current menu tab should not go
  // to the bottom of the menu section but should be at the top"):
  // 'nearest' only scrolls the minimum distance needed, which for an item
  // below the fold means it stops the instant the item's bottom edge
  // touches the container's bottom edge, landing it flush against the
  // bottom rather than somewhere comfortable to read. 'start' always
  // aligns the active item's top edge with the top of the scrollable nav.
  // Direct sideEl.scrollTop math, not activeLink.scrollIntoView() (real
  // bug found live 2026-07-24, user report: "users dashboard page is
  // not starting from the very top"): scrollIntoView()'s own ancestor
  // walk does not reliably stop at .side (a position:sticky element,
  // not a plain scroll parent) when its content does not currently
  // overflow it, so it kept escaping to scroll the whole window instead
  // of the sidebar alone, most visible on Dashboard (the very first,
  // already near the top, nav item) where the whole page nonetheless
  // opened scrolled down by however far that link's own natural
  // document offset was. Setting .side's own scrollTop directly can
  // only ever move the sidebar, never the page.
  var activeLink=document.querySelector('.side-nav .side-link.active');
  var sideEl=document.querySelector('.side');
  if (activeLink && sideEl) {
    sideEl.scrollTop = activeLink.getBoundingClientRect().top - sideEl.getBoundingClientRect().top + sideEl.scrollTop;
  }
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
  var countdownTimer=null, lastActivityAt=Date.now(), warningDeadlineAt=null;

  // Each tab previously tracked activity purely in memory, so a user
  // actively working in one tab still got force-logged-out by a
  // different, idle background tab's independent timer (audit finding
  // H-3, 2026-07-17: the server session is shared, but nothing told a
  // stale tab that a different tab had just proven the user is present).
  // Broadcast every activity/resume timestamp via localStorage, which
  // fires a 'storage' event in every OTHER tab (never the one that wrote
  // it), so any tab's activity resets every tab's countdown.
  var ACTIVITY_KEY='skulos-last-activity';
  function broadcastActivity(ts){ try{ localStorage.setItem(ACTIVITY_KEY, String(ts)); }catch(e){} }
  try{
    var storedActivity=parseInt(localStorage.getItem(ACTIVITY_KEY)||'0',10);
    if(isFinite(storedActivity) && storedActivity>lastActivityAt) lastActivityAt=storedActivity;
  }catch(e){}

  function doLogout(){
    // Refresh the CSRF token immediately before submitting, not just
    // whatever was baked into this hidden form at page load: this form
    // exists specifically to fire after a real stretch of inactivity, and
    // a meta tag written by a same-tab fetch/response in the meantime
    // (e.g. a successful "Resume" earlier in the same visit) may hold a
    // newer token than the one this form was rendered with.
    var freshToken=document.querySelector('meta[name="csrf-token"]')?.content;
    var tokenInput=logoutForm.querySelector('input[name="_token"]');
    if(freshToken && tokenInput) tokenInput.value=freshToken;
    logoutForm.submit();
  }

  // The only place that decides idle state, driven entirely by wall
  // clock timestamps (Date.now()), never by counting how many timer
  // ticks actually fired. First fix (2026-07-16) only re-checked elapsed
  // time when the tab regained visibility, which caught the "warning
  // never even appears while backgrounded" case, but the countdown
  // itself, once already showing, still relied on setInterval ticking
  // once per real second to decrement a counter; a backgrounded/
  // unfocused tab's interval is exactly what browsers throttle or fully
  // suspend, so the "23" on screen would visibly freeze until the tab
  // was refocused (user report 2026-07-16, second time: "still does not
  // work until one come back to the tab... should also work when one
  // moves away from the tab or to another window"). warningDeadlineAt is
  // an absolute timestamp anchored to lastActivityAt (idleMs + warnMs
  // ahead of it), so calling this late, whether from a throttled tick, a
  // regained-visibility event, or a regained window focus event (a
  // switch to a different OS application does not reliably fire
  // visibilitychange in every browser the way switching browser tabs
  // does, focus/blur covers that gap), always yields the exact correct
  // remaining seconds, or an immediate logout if the whole warning
  // window already elapsed unseen.
  var closeDialogFocus=null;

  var loggingOut=false;

  function evaluate(){
    var now=Date.now();
    if(warningDeadlineAt===null){
      if(now-lastActivityAt<idleMs) return;
      warningDeadlineAt=lastActivityAt+idleMs+warnMs;
      scrim.classList.add('show');
      closeDialogFocus=window.SkulosDialog?.open(scrim, {autofocus: resumeBtn, onEscape: resume});
    }
    var secondsLeft=Math.ceil((warningDeadlineAt-now)/1000);
    // Always update the displayed number first, even on the path to
    // logout (user report 2026-07-21, second time: countdown stuck at
    // "1" and never actually logged out). The old code returned as soon
    // as secondsLeft hit zero, before ever writing "0" (or anything past
    // "1") to the screen, so the last thing visibly shown stayed frozen
    // at whatever the previous tick displayed regardless of what
    // happened next — doLogout() may well have kept firing, just with no
    // visible sign it was doing anything.
    countEl.textContent = secondsLeft > 0 ? secondsLeft : 0;
    if(secondsLeft<=0 && ! loggingOut){
      loggingOut=true;
      doLogout();
    }
  }

  // Recursive setTimeout, not setInterval (user report 2026-07-21:
  // countdown visibly froze on screen for a stretch before the real
  // timeout still correctly fired). setInterval keeps firing on its own
  // fixed schedule regardless of how long a tick took to run, so ticks
  // pile up and lag under main-thread contention (a heavy fetch, a big
  // DOM update from something else on the page, ...); each call here
  // instead schedules the next one fresh off the actual current time, so
  // a late tick self-corrects instead of compounding. evaluate() itself
  // was already correct either way, since it always computes secondsLeft
  // from absolute timestamps, not a decrementing counter — this fixes
  // the on-screen number actually refreshing every second, not the
  // security behavior, which was never wrong.
  //
  // Stops rescheduling once logout has actually been triggered (second
  // 2026-07-21 fix, same report): the old version kept calling tick()
  // forever even after doLogout() fired, which — if the logout form
  // submission was ever slow, blocked, or silently failed for any reason
  // — meant doLogout() got invoked again on every subsequent tick with no
  // visible feedback either way, indistinguishable on screen from doing
  // nothing at all.
  var tickRunning=false;

  function tick(){
    evaluate();
    if(loggingOut){ tickRunning=false; return; }
    countdownTimer=setTimeout(tick,1000);
  }

  function startTicking(){
    clearBgDeadlineTimer();
    if(tickRunning) return;
    tickRunning=true;
    countdownTimer=setTimeout(tick,1000);
  }

  // Third pass on this same report (2026-07-21, third time: "got to 0 and
  // was stuck for a while... only log out when one go back to the tab").
  // The two fixes above made evaluate() itself correct and made the
  // on-screen number stop visibly freezing, but the actual logout still
  // depended on tick()'s 1-second setTimeout LOOP eventually firing again,
  // and that is exactly what browsers throttle or fully suspend once a
  // tab is backgrounded (Chrome in particular can delay a repeating short
  // timer in a hidden tab by a minute or more). A single setTimeout
  // scheduled for one specific, precise delay is throttled far less
  // aggressively than a tight repeating loop, so instead of continuing
  // the 1s tick loop while hidden, one exact-deadline timer is scheduled
  // the moment the tab goes hidden, cleared and replaced by the normal
  // visible tick loop the moment it comes back.
  var bgDeadlineTimer=null;

  function clearBgDeadlineTimer(){
    if(bgDeadlineTimer){ clearTimeout(bgDeadlineTimer); bgDeadlineTimer=null; }
  }

  function scheduleBackgroundDeadline(){
    clearBgDeadlineTimer();
    if(loggingOut) return;
    tickRunning=false;
    if(countdownTimer){ clearTimeout(countdownTimer); countdownTimer=null; }
    var deadline=warningDeadlineAt!==null ? warningDeadlineAt : (lastActivityAt+idleMs+warnMs);
    var remaining=Math.max(0, deadline-Date.now());
    bgDeadlineTimer=setTimeout(function(){
      bgDeadlineTimer=null;
      evaluate();
      // Activity from another tab (the 'storage' listener below) can
      // push the deadline out again while still hidden; reschedule for
      // the new one rather than leaving nothing pending.
      if(! loggingOut && document.visibilityState==='hidden') scheduleBackgroundDeadline();
    }, remaining);
  }

  function resetIdleTimer(){
    lastActivityAt=Date.now();
    warningDeadlineAt=null;
    loggingOut=false;
    scrim.classList.remove('show');
    closeDialogFocus?.();
    closeDialogFocus=null;
    // tick()'s own loop stops rescheduling once loggingOut was set; a
    // resume after that point (Resume clicked, or activity from another
    // tab) needs to explicitly restart it, not just clear the flag. While
    // hidden, restart via the background-safe deadline timer instead of
    // the visible tick loop, for the same reason startTicking() avoids it.
    if(document.visibilityState==='hidden') scheduleBackgroundDeadline();
    else startTicking();
  }

  function resume(){
    resetIdleTimer();
    broadcastActivity(lastActivityAt);
    fetch('{{ route('session.keep-alive') }}', {
      method:'POST',
      headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'','Accept':'application/json'}
    }).catch(function(){});
  }

  resumeBtn.addEventListener('click',resume);
  logoutBtn.addEventListener('click',doLogout);

  ['mousemove','keydown','click','scroll','touchstart'].forEach(function(evt){
    document.addEventListener(evt,function(){
      if(warningDeadlineAt===null){
        lastActivityAt=Date.now();
        broadcastActivity(lastActivityAt);
      }
    },{passive:true});
  });

  // Fires in every OTHER tab when one tab writes ACTIVITY_KEY. A newer
  // timestamp than this tab already knows about means real activity just
  // happened somewhere else, so this tab is not actually idle: adopt it,
  // and if this tab's own warning was already showing, cancel it.
  window.addEventListener('storage', function(e){
    if(e.key!==ACTIVITY_KEY || !e.newValue) return;
    var ts=parseInt(e.newValue,10);
    if(!isFinite(ts) || ts<=lastActivityAt) return;
    lastActivityAt=ts;
    if(warningDeadlineAt!==null){ resetIdleTimer(); return; }
    // Still in the pre-warning phase but a background-safe deadline timer
    // is already scheduled off the old (now stale, too-early) deadline;
    // put a fresh one in its place rather than letting it fire early.
    if(document.visibilityState==='hidden' && bgDeadlineTimer) scheduleBackgroundDeadline();
  });

  document.addEventListener('visibilitychange', function(){
    if(document.visibilityState==='visible'){
      clearBgDeadlineTimer();
      evaluate();
      if(! loggingOut) startTicking();
    } else {
      scheduleBackgroundDeadline();
    }
  });
  // A switch to a different OS application, not just a different browser
  // tab, does not reliably fire visibilitychange in every browser (the
  // tab can stay technically "visible" while not actually focused); focus
  // and blur cover that gap the same way, since the background-timer
  // throttling this is working around applies to unfocused windows too.
  window.addEventListener('focus', function(){
    clearBgDeadlineTimer();
    evaluate();
    if(! loggingOut) startTicking();
  });
  window.addEventListener('blur', function(){
    if(document.visibilityState!=='hidden') scheduleBackgroundDeadline();
  });

  if(document.visibilityState==='hidden') scheduleBackgroundDeadline();
  else startTicking();
})();
</script>

{{-- Searchable select, platform-wide (user directive 2026-07-20): country,
     state, LGA, and any staff/student/parent/role picker, or any other
     long list anywhere in the app, gets a type-to-filter combobox
     automatically. A plain <select> with more than SS_THRESHOLD options
     is enhanced on load; short ones (status filters, term pickers, three
     way toggles) are deliberately left alone, search would be friction
     there, not help. Opt a specific select out with data-no-search;
     multi-selects are left untouched (out of scope for this pass). --}}
<script>
(function(){
  var SS_THRESHOLD = 8;

  // Every enhanced select registers itself here (2026-07-21 fix: multiple
  // panels on the same page — e.g. Class, Country, State on the student
  // form — could end up open at once, not closing each other, "does not
  // leave the screen when one click on it"). One shared registry plus one
  // shared document click listener, instead of each instance running its
  // own independent copy of the same "am I outside this wrap?" check,
  // removes any possibility of instances interfering with or shadowing
  // each other's close logic.
  var ssInstances = [];

  function closeAllPanelsExcept(keep){
    ssInstances.forEach(function(inst){
      if (inst !== keep && ! inst.panel.hidden) inst.close();
    });
  }

  document.addEventListener('click', function(e){
    ssInstances.forEach(function(inst){
      if (! inst.wrap.contains(e.target)) inst.close();
    });
  });

  function enhance(select){
    if (select.dataset.ssEnhanced || select.multiple || 'noSearch' in select.dataset) return;
    if (select.options.length <= SS_THRESHOLD) return;
    select.dataset.ssEnhanced = '1';

    var wrap = document.createElement('div');
    wrap.className = 'ss-combo';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('ss-native');
    select.tabIndex = -1;

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ss-toggle';
    toggle.setAttribute('aria-haspopup', 'listbox');
    toggle.setAttribute('aria-expanded', 'false');
    if (select.disabled) toggle.disabled = true;

    var assocLabel = select.id ? document.querySelector('label[for="'+select.id+'"]') : null;
    if (assocLabel) {
      if (! assocLabel.id) assocLabel.id = 'ss-label-'+select.id;
      toggle.setAttribute('aria-labelledby', assocLabel.id);
      assocLabel.addEventListener('click', function(){ toggle.focus(); openPanel(); });
    }

    var label = document.createElement('span');
    label.className = 'ss-label';
    var caret = document.createElement('span');
    caret.className = 'ss-caret';
    caret.setAttribute('aria-hidden', 'true');
    toggle.appendChild(label);
    toggle.appendChild(caret);

    var panel = document.createElement('div');
    panel.className = 'ss-panel';
    panel.hidden = true;

    var searchWrap = document.createElement('div');
    searchWrap.className = 'ss-search';
    var search = document.createElement('input');
    search.type = 'text';
    search.setAttribute('role', 'searchbox');
    search.setAttribute('aria-label', 'Search options');
    search.placeholder = 'Search...';
    search.autocomplete = 'off';
    searchWrap.appendChild(search);

    var list = document.createElement('ul');
    list.className = 'ss-list';
    list.setAttribute('role', 'listbox');
    if (! select.id) select.id = 'ss-select-'+Math.random().toString(36).slice(2);
    list.id = 'ss-list-'+select.id;
    toggle.setAttribute('aria-controls', list.id);

    panel.appendChild(searchWrap);
    panel.appendChild(list);
    wrap.appendChild(toggle);
    wrap.appendChild(panel);

    var activeIndex = -1;

    function visibleOptions(){
      return Array.prototype.filter.call(list.children, function(li){ return ! li.hidden && li.getAttribute('role') === 'option'; });
    }

    function setActive(li){
      var current = list.querySelector('.ss-active');
      if (current) current.classList.remove('ss-active');
      activeIndex = -1;
      if (li) {
        li.classList.add('ss-active');
        toggle.setAttribute('aria-activedescendant', li.id);
        li.scrollIntoView({block: 'nearest'});
        activeIndex = visibleOptions().indexOf(li);
      } else {
        toggle.removeAttribute('aria-activedescendant');
      }
    }

    function buildOptions(){
      list.innerHTML = '';
      Array.prototype.forEach.call(select.options, function(opt, idx){
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.id = list.id+'-'+idx;
        li.dataset.value = opt.value;
        li.textContent = opt.textContent;
        if (opt.value === select.value) li.setAttribute('aria-selected', 'true');
        list.appendChild(li);
      });
    }

    function syncLabel(){
      var opt = select.options[select.selectedIndex];
      label.textContent = opt ? opt.textContent : '';
      label.classList.toggle('ss-placeholder', ! opt || ! opt.value);
      toggle.disabled = select.disabled;
    }

    function filterList(q){
      q = q.trim().toLowerCase();
      var any = false;
      Array.prototype.forEach.call(list.querySelectorAll('[role="option"]'), function(li){
        var match = ! q || li.textContent.toLowerCase().indexOf(q) !== -1;
        li.hidden = ! match;
        if (match) any = true;
      });
      var empty = list.querySelector('.ss-empty');
      if (! any) {
        if (! empty) {
          empty = document.createElement('li');
          empty.className = 'ss-empty';
          empty.textContent = 'No matches';
          list.appendChild(empty);
        }
      } else if (empty) {
        empty.remove();
      }
      setActive(null);
    }

    function openPanel(){
      if (toggle.disabled) return;
      closeAllPanelsExcept(instance);
      buildOptions();
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      search.value = '';
      filterList('');
      var selected = list.querySelector('[aria-selected="true"]');
      if (selected) setActive(selected);
      search.focus();
    }

    function closePanel(){
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.removeAttribute('aria-activedescendant');
    }

    var instance = {wrap: wrap, panel: panel, close: closePanel};
    ssInstances.push(instance);

    function chooseValue(value){
      select.value = value;
      select.dispatchEvent(new Event('change', {bubbles: true}));
      syncLabel();
      closePanel();
      toggle.focus();
    }

    toggle.addEventListener('click', function(){
      if (panel.hidden) openPanel(); else closePanel();
    });
    search.addEventListener('input', function(){ filterList(search.value); });
    // Without this, mousedown on an <li> blurs the still-focused search
    // input first (li isn't itself focusable), firing wrap's focusout
    // handler and racing closePanel() against the option's own click —
    // the classic combobox bug where the panel closes on you before the
    // click you were making on an option ever gets counted (found live
    // 2026-07-21: "unable to select an item... it just closes the list").
    // Blocking the default keeps focus put, so the click always lands.
    list.addEventListener('mousedown', function(e){
      if (e.target.closest('[role="option"]')) e.preventDefault();
    });
    list.addEventListener('click', function(e){
      var li = e.target.closest('[role="option"]');
      if (li) chooseValue(li.dataset.value);
    });
    search.addEventListener('keydown', function(e){
      var opts;
      if (e.key === 'Escape') { closePanel(); toggle.focus(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        opts = visibleOptions();
        var target = activeIndex >= 0 && opts[activeIndex] ? opts[activeIndex] : opts[0];
        if (target) chooseValue(target.dataset.value);
        return;
      }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        opts = visibleOptions();
        if (! opts.length) return;
        var next = activeIndex + (e.key === 'ArrowDown' ? 1 : -1);
        if (next < 0) next = opts.length - 1;
        if (next >= opts.length) next = 0;
        setActive(opts[next]);
      }
    });
    wrap.addEventListener('focusout', function(){
      window.requestAnimationFrame(function(){
        if (! wrap.contains(document.activeElement)) closePanel();
      });
    });
    // Catches a select repopulated programmatically (e.g. the country to
    // state to LGA cascading picker replacing <option>s after a fetch)
    // without a user driven 'change' event to hook into.
    new MutationObserver(syncLabel).observe(select, {childList: true});

    syncLabel();
  }

  function enhanceAll(root){
    (root || document).querySelectorAll('select').forEach(enhance);
  }

  document.addEventListener('DOMContentLoaded', function(){ enhanceAll(document); });
  window.SkulosSearchable = {enhanceAll: enhanceAll};
})();
</script>

{{-- Shared "mark notification read" handler (user report 2026-07-21:
     "CLICKING ON THE MARK READ BUTTON DOES NOT NEED TO RELOAD THE PAGE"),
     one delegated listener for both the header bell dropdown and the
     dashboard's own Notifications widget, both a plain
     <button data-mark-read-url="..."> rather than a submitting <form>. --}}
<script>
(function(){
  document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-mark-read-url]');
    if (! btn) return;
    e.preventDefault();
    var row = btn.closest('.notif');
    fetch(btn.dataset.markReadUrl, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      },
    }).then(function(res){
      if (! res.ok) return;
      if (row) row.querySelector('.dot')?.classList.add('read');
      btn.remove();
    });
  });

  // The bell panel is a <details>, closed on any click outside it,
  // matching how every other dropdown/panel in this app behaves.
  document.addEventListener('click', function(e){
    document.querySelectorAll('.notif-bell[open]').forEach(function(bell){
      if (! bell.contains(e.target)) bell.removeAttribute('open');
    });
  });
})();
</script>
@stack('scripts')
</body>
</html>
