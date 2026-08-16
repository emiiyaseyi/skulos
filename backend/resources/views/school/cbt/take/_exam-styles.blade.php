{{-- The take screen's own CSS, kept out of session.blade.php so that
     file stays readable. Brand-colour driven via the --exam-brand
     custom property set on #lgShell (school branding, or a per-
     assessment override — see CbtAssessment::brandColor()). Mobile-
     first per the project's own standing design rule: the side toolbar
     collapses into the document flow below the question on narrow
     screens rather than requiring horizontal scrolling or a hidden
     drawer. --}}
<style>
  /* See layouts/app.blade.php: the blank area below content on
     Chrome for iOS was overscroll, not extra page height. */
  html,body{overscroll-behavior-y:none}
  #lgShell{
    --exam-ink:#1c2430; --exam-muted:#66707d; --exam-line:#e4e8ee; --exam-bg:#f4f6f9;
    --exam-card:#ffffff; --exam-danger:#c0392b; --exam-ok:#1f8a53;
    display:flex; flex-direction:column; min-height:100vh;min-height:100dvh;min-height:100svh; background:var(--exam-bg); color:var(--exam-ink);
    font-size:calc(1rem * var(--lg-zoom, 1));
  }
  @media (prefers-color-scheme: dark){
    #lgShell{ --exam-ink:#e7ebf2; --exam-muted:#9aa4b2; --exam-line:#2b3340; --exam-bg:#12161d; --exam-card:#1a1f28; }
  }
  :root[data-theme="dark"] #lgShell{ --exam-ink:#e7ebf2; --exam-muted:#9aa4b2; --exam-line:#2b3340; --exam-bg:#12161d; --exam-card:#1a1f28; }
  :root[data-theme="light"] #lgShell{ --exam-ink:#1c2430; --exam-muted:#66707d; --exam-line:#e4e8ee; --exam-bg:#f4f6f9; --exam-card:#ffffff; }

  #lgShell *{ box-sizing:border-box; }

  /* .lg-panel/.lg-overlay both set their own display:flex below, which
     otherwise silently wins over the browser's built-in [hidden]{display:
     none} rule (equal specificity, author stylesheet loads later) — the
     calculator, notes pad, and submit-confirmation modal would all
     render open on page load regardless of their hidden attribute
     without this. */
  #lgShell [hidden]{ display:none !important; }

  /* Top bar */
  .lg-topbar{
    position:sticky; top:0; z-index:20; display:flex; align-items:center; gap:1rem;
    background:var(--exam-card); border-bottom:1px solid var(--exam-line); padding:.6rem .9rem;
    flex-wrap:wrap;
  }
  .lg-topbar-left{ min-width:0; }
  .lg-title{ font-weight:700; font-size:.98rem; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:14rem; }
  .lg-sub{ font-size:.72rem; color:var(--exam-muted); }
  .lg-topbar-mid{ flex:1; min-width:8rem; display:flex; flex-direction:column; gap:.25rem; }
  .lg-progress-text{ font-size:.72rem; color:var(--exam-muted); }
  .lg-progress-track{ height:.4rem; border-radius:999px; background:var(--exam-line); overflow:hidden; }
  .lg-progress-bar{ height:100%; width:0%; background:var(--exam-brand); transition:width .25s ease; }
  .lg-topbar-right{ margin-left:auto; }
  .lg-timer{
    font-variant-numeric:tabular-nums; font-weight:800; font-size:1rem; padding:.35rem .7rem;
    border-radius:8px; background:var(--exam-bg); border:1px solid var(--exam-line); white-space:nowrap;
  }
  .lg-timer.lg-timer-low{ color:#fff; background:var(--exam-danger); border-color:var(--exam-danger); animation:lgPulse 1s infinite; }
  @keyframes lgPulse{ 50%{ opacity:.6; } }

  /* Body layout: question column + toolbar column, stacking on mobile */
  .lg-body{ flex:1; display:flex; gap:1rem; padding:.9rem; max-width:76rem; margin:0 auto; width:100%; }
  .lg-question-scroll{ flex:1; min-width:0; display:flex; flex-direction:column; gap:.9rem; padding-bottom:6rem; }
  .lg-instructions{
    background:var(--exam-card); border:1px solid var(--exam-line); border-left:4px solid var(--exam-brand);
    border-radius:8px; padding:.8rem; font-size:.85rem; color:var(--exam-muted); white-space:pre-line;
  }

  /* Section banner — announced before that bank's questions begin */
  .lg-section-banner{ display:flex; align-items:center; justify-content:center; min-height:60vh; }
  .lg-section-banner-card{
    text-align:center; background:var(--exam-card); border:1px solid var(--exam-line); border-radius:14px;
    padding:2.2rem 1.6rem; max-width:26rem; width:100%;
  }
  .lg-section-eyebrow{ font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:var(--exam-muted); margin-bottom:.5rem; }
  .lg-section-banner-card h2{ font-size:1.3rem; margin-bottom:.4rem; }
  .lg-section-banner-card p{ margin-bottom:1.1rem; font-size:.85rem; }

  /* One question */
  .lg-question.cbt-question{ background:var(--exam-card); border:1px solid var(--exam-line); border-radius:12px; padding:1.1rem; }
  .lg-qhead{ display:flex; align-items:center; gap:.6rem; margin-bottom:.8rem; flex-wrap:wrap; }
  .lg-qnum{
    width:1.9rem; height:1.9rem; border-radius:50%; background:var(--exam-brand); color:#fff;
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; flex-shrink:0;
  }
  .lg-qtype{ font-size:.68rem; letter-spacing:.05em; color:var(--exam-muted); border:1px solid var(--exam-line); border-radius:999px; padding:.15rem .5rem; }
  .lg-qmarks{ margin-left:auto; font-size:.78rem; color:var(--exam-muted); font-weight:600; }
  .lg-qtext{ font-size:1.05rem; line-height:1.55; margin-bottom:.9rem; }
  .lg-qimage{ max-width:100%; border-radius:8px; margin-bottom:.9rem; }
  .lg-hint{ font-size:.78rem; color:var(--exam-muted); margin-bottom:.5rem; }

  .lg-option{
    display:flex; align-items:center; gap:.6rem; padding:.65rem .8rem; border:1px solid var(--exam-line);
    border-radius:10px; margin-bottom:.5rem; cursor:pointer; transition:border-color .15s, background .15s;
  }
  .lg-option:hover{ border-color:var(--exam-brand); }
  .lg-option:has(input:checked){ border-color:var(--exam-brand); background:color-mix(in srgb, var(--exam-brand) 10%, transparent); }
  .lg-option input{ accent-color:var(--exam-brand); width:1.05rem; height:1.05rem; flex-shrink:0; }
  .lg-option-letter{
    width:1.5rem; height:1.5rem; border-radius:50%; border:1px solid var(--exam-line); flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:700; color:var(--exam-muted);
  }
  .lg-option-text{ font-size:.92rem; }

  .lg-input, .lg-textarea, .lg-select{
    width:100%; border:1px solid var(--exam-line); border-radius:8px; padding:.6rem .7rem; font-size:.92rem;
    background:var(--exam-card); color:var(--exam-ink); font-family:inherit;
  }
  .lg-textarea{ resize:vertical; }
  .lg-essay-note{ font-size:.75rem; color:var(--exam-muted); margin-top:.4rem; }
  .lg-match-row{ display:flex; align-items:center; gap:.6rem; margin-bottom:.5rem; flex-wrap:wrap; }
  .lg-match-stem{ flex:1 1 10rem; font-size:.92rem; }
  .lg-match-arrow{ color:var(--exam-muted); }
  .lg-match-row .lg-select{ flex:1 1 12rem; width:auto; }
  .lg-savestate{ font-size:.72rem; color:var(--exam-muted); margin-top:.7rem; min-height:1em; }

  /* Side toolbar */
  .lg-toolbar{ width:15rem; flex-shrink:0; display:flex; flex-direction:column; gap:.6rem; align-self:flex-start; position:sticky; top:4.2rem; }
  .lg-tool-card{ background:var(--exam-card); border:1px solid var(--exam-line); border-radius:10px; padding:.8rem; }
  .lg-tool-head{ font-weight:700; font-size:.82rem; margin-bottom:.5rem; }
  .lg-tool-rows > div{ display:flex; justify-content:space-between; font-size:.8rem; padding:.2rem 0; color:var(--exam-muted); }
  .lg-tool-rows strong{ color:var(--exam-ink); }
  .lg-tool-btn{
    background:var(--exam-card); border:1px solid var(--exam-line); border-radius:8px; padding:.55rem .7rem;
    font-size:.82rem; font-weight:600; cursor:pointer; text-align:left; color:var(--exam-ink);
  }
  .lg-tool-btn:hover{ border-color:var(--exam-brand); }
  .lg-tool-break{ color:var(--exam-danger); }
  .lg-tool-note{ font-size:.72rem; color:var(--exam-muted); }
  .lg-zoom{ display:flex; align-items:center; justify-content:space-between; border:1px solid var(--exam-line); border-radius:8px; padding:.4rem .6rem; }
  .lg-zoom button{ background:none; border:none; font-weight:800; cursor:pointer; color:var(--exam-ink); font-size:.9rem; padding:.2rem .4rem; }
  #lgZoomLabel{ font-size:.78rem; color:var(--exam-muted); }

  /* Navigator */
  .lg-navpanel{
    position:fixed; right:1rem; bottom:5rem; z-index:30; width:19rem; max-width:calc(100vw - 2rem);
    background:var(--exam-card); border:1px solid var(--exam-line); border-radius:12px; padding:.9rem;
    box-shadow:0 10px 30px rgba(0,0,0,.18); max-height:70vh; overflow-y:auto;
  }
  .lg-navpanel-head{ display:flex; justify-content:space-between; align-items:center; font-weight:700; font-size:.82rem; margin-bottom:.6rem; }
  .lg-legend{ display:flex; gap:.5rem; font-size:.68rem; color:var(--exam-muted); flex-wrap:wrap; }
  .lg-legend span{ display:flex; align-items:center; gap:.25rem; }
  .lg-dot{ width:.55rem; height:.55rem; border-radius:50%; display:inline-block; }
  .lg-dot-answered{ background:var(--exam-ok); }
  .lg-dot-todo{ background:var(--exam-line); border:1px solid var(--exam-muted); }
  .lg-dot-flagged{ background:#e0a300; }
  .lg-navgrid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(2.3rem,1fr)); gap:.4rem; }
  .lg-navitem{
    aspect-ratio:1; border-radius:8px; border:1px solid var(--exam-line); background:var(--exam-bg);
    display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; cursor:pointer;
    color:var(--exam-ink);
  }
  .lg-navitem.is-answered{ background:var(--exam-ok); border-color:var(--exam-ok); color:#fff; }
  .lg-navitem.is-flagged{ box-shadow:0 0 0 2px #e0a300 inset; }
  .lg-navitem.is-current{ outline:2px solid var(--exam-brand); outline-offset:1px; }

  /* Bottom bar */
  .lg-bottombar{
    position:fixed; left:0; right:0; bottom:0; z-index:20; display:flex; gap:.5rem; padding:.6rem .9rem;
    background:var(--exam-card); border-top:1px solid var(--exam-line);
  }
  .lg-btn{
    border-radius:8px; padding:.6rem .8rem; font-size:.84rem; font-weight:700; cursor:pointer; border:1px solid transparent;
    flex:1; text-align:center;
  }
  .lg-btn-ghost{ background:var(--exam-bg); border-color:var(--exam-line); color:var(--exam-ink); }
  .lg-btn-nav{ background:var(--exam-bg); border-color:var(--exam-line); color:var(--exam-ink); flex:0 0 auto; }
  .lg-btn-primary{ background:var(--exam-brand); color:#fff; flex:1.4; }
  .lg-btn-submit{ background:var(--exam-ok); color:#fff; }

  /* Floating panels: notes, calculator */
  .lg-panel{
    position:fixed; right:1rem; bottom:5rem; z-index:30; width:18rem; max-width:calc(100vw - 2rem);
    background:var(--exam-card); border:1px solid var(--exam-line); border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.18);
    display:flex; flex-direction:column; overflow:hidden;
  }
  .lg-panel-head{ display:flex; justify-content:space-between; align-items:center; padding:.6rem .8rem; border-bottom:1px solid var(--exam-line); font-weight:700; font-size:.82rem; }
  .lg-panel-close{ background:none; border:none; font-size:1.1rem; cursor:pointer; color:var(--exam-muted); line-height:1; }
  .lg-panel-body{ border:none; border-radius:0; min-height:10rem; padding:.7rem; font-size:.85rem; }
  .lg-panel-calc .lg-calc-display{
    margin:.7rem; padding:.6rem .7rem; text-align:right; font-size:1.3rem; font-variant-numeric:tabular-nums;
    border-radius:8px;
  }
  .lg-calc-keys{ display:grid; grid-template-columns:repeat(4,1fr); gap:.4rem; padding:0 .7rem .7rem; }
  .lg-calc-keys button{
    padding:.6rem 0; border-radius:8px; border:1px solid var(--exam-line); background:var(--exam-bg);
    font-weight:700; cursor:pointer; color:var(--exam-ink);
  }

  /* Overlays: break, submit confirm */
  .lg-overlay{
    position:fixed; inset:0; z-index:40; background:rgba(10,14,20,.72); color:#fff; display:flex;
    flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:1.2rem;
  }
  .lg-overlay-note{ font-size:.8rem; opacity:.75; margin-top:.4rem; }
  .lg-confirm-card{ background:var(--exam-card); color:var(--exam-ink); border-radius:14px; padding:1.6rem; max-width:22rem; width:100%; }
  .lg-confirm-card h2{ margin-bottom:.5rem; font-size:1.15rem; }
  .lg-confirm-card p{ font-size:.88rem; color:var(--exam-muted); margin-bottom:1.2rem; }
  .lg-confirm-actions{ display:flex; gap:.6rem; }

  /* Mobile: toolbar drops below the question, bottom bar stays fixed */
  @media (max-width: 900px){
    .lg-body{ flex-direction:column; padding:.7rem; }
    .lg-toolbar{ width:100%; position:static; flex-direction:row; flex-wrap:wrap; }
    .lg-tool-card{ flex:1 1 100%; }
    .lg-title{ max-width:9rem; }
    .lg-question-scroll{ padding-bottom:5.5rem; }
    .lg-navpanel, .lg-panel{ left:.6rem; right:.6rem; width:auto; bottom:4.6rem; }
  }
</style>
