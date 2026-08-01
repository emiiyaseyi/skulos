{{--
  The Assessment Hub's shared page banner and design tokens, matching
  the reference repo's own look: a dark gradient header with an icon,
  title and one-line subtitle, then optional quick links.

  Colours deliberately derive from the SCHOOL's own accent rather than
  hardcoding the reference's green, so a school that has branded SKULOS
  purple does not suddenly get a green exam console. --accent-ink is
  the app's existing readable-text-on-accent variable (see
  App\Support\ColorContrast), so the banner text stays legible whatever
  colour a school picks.

  Usage:
    @include('school.cbt._banner', [
      'icon' => '🛡',
      'title' => 'Admin Backend',
      'subtitle' => 'Every assessment in the school, in one place.',
      'links' => [['label' => 'Score Matrix', 'url' => '...', 'tone' => 'teal']],
    ])
--}}
@php
  $tones = [
    'primary' => 'var(--accent)',
    'green' => '#198754',
    'teal' => '#17a2b8',
    'cyan' => '#0dcaf0',
    'purple' => '#6f42c1',
    'orange' => '#fd7e14',
    'amber' => '#ffc107',
    'red' => '#dc3545',
    'grey' => '#6c757d',
    'plain' => null,
  ];
@endphp

<div class="cbt-banner">
  <div class="cbt-banner-title">{!! $icon ?? '' !!} {{ $title }}</div>
  @isset($subtitle)
    <div class="cbt-banner-sub">{{ $subtitle }}</div>
  @endisset
</div>

@if(!empty($links))
  <div class="cbt-quicklinks">
    <span class="cbt-quicklinks-label">Quick links</span>
    @foreach($links as $link)
      @php($bg = $tones[$link['tone'] ?? 'plain'] ?? null)
      <a href="{{ $link['url'] }}" class="cbt-ql"
         @if($bg) style="background:{{ $bg }};color:#fff;border-color:{{ $bg }}" @endif>
        {!! $link['icon'] ?? '' !!} {{ $link['label'] }}
      </a>
    @endforeach
  </div>
@endif

@once
@push('head')
<style>
  .cbt-banner{
    background:linear-gradient(135deg,var(--accent) 0%,color-mix(in srgb,var(--accent) 65%,#000) 100%);
    color:var(--accent-ink,#fff);border-radius:12px;padding:1.1rem 1.25rem;text-align:center;margin-bottom:1rem;
  }
  .cbt-banner-title{font-size:1.35rem;font-weight:800;letter-spacing:-.01em}
  .cbt-banner-sub{font-size:.88rem;opacity:.85;margin-top:.2rem}

  .cbt-quicklinks{
    display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;
    border:1px solid var(--line);border-radius:10px;padding:.6rem .7rem;margin-bottom:1.1rem;
  }
  .cbt-quicklinks-label{
    font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
    color:var(--mut-dim,#6c757d);margin-right:.2rem;
  }
  .cbt-ql{
    display:inline-block;padding:.34rem .7rem;border-radius:7px;font-size:.82rem;font-weight:700;
    text-decoration:none;color:inherit;border:1px solid var(--line);background:var(--surface,#fff);white-space:nowrap;
  }
  .cbt-ql:hover{filter:brightness(.94)}

  /* Underline tab strip, as the reference uses for its console tabs. */
  .cbt-tabs{display:flex;flex-wrap:wrap;gap:.15rem;border-bottom:2px solid var(--line);margin-bottom:1.1rem}
  .cbt-tab{
    display:inline-flex;align-items:center;gap:.35rem;padding:.55rem .8rem;text-decoration:none;color:inherit;
    font-size:.86rem;font-weight:600;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;
  }
  .cbt-tab:hover{background:var(--surface-2,#f6f6f6)}
  .cbt-tab-active{border-bottom-color:var(--accent);color:var(--accent);font-weight:800}
  .cbt-tab-badge{
    display:inline-block;min-width:1.3rem;text-align:center;padding:.05rem .35rem;border-radius:999px;
    font-size:.7rem;font-weight:800;background:var(--mut-dim,#6c757d);color:#fff;
  }

  /* Solid colour stat blocks. */
  .cbt-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(8rem,1fr));gap:.7rem;margin-bottom:1.1rem}
  .cbt-stat{border-radius:10px;padding:1rem .8rem;text-align:center;color:#fff}
  .cbt-stat b{display:block;font-size:1.75rem;font-weight:800;line-height:1.1}
  .cbt-stat span{font-size:.76rem;opacity:.92}

  /* Person cell: name over a muted contact line, as the reference lists takers. */
  .cbt-person strong{display:block;font-weight:700}
  .cbt-person small{color:var(--mut-dim,#6c757d);font-size:.76rem}

  /* Attempt number pill. */
  .cbt-attempt{
    display:inline-block;background:#0d6efd;color:#fff;border-radius:999px;
    padding:.1rem .5rem;font-size:.74rem;font-weight:800;
  }
  .cbt-attempt-voided{background:var(--mut-dim,#6c757d)}

  /* Per-question state pills on the grading screens. */
  .cbt-qpill{
    display:inline-block;border-radius:6px;padding:.3rem .5rem;font-size:.74rem;font-weight:800;
    color:#fff;text-align:center;min-width:3.2rem;
  }
  .cbt-qpill small{display:block;font-weight:600;font-size:.62rem;opacity:.9;text-transform:uppercase}
  .cbt-qpill-full{background:#198754}
  .cbt-qpill-partial{background:#ffc107;color:#5c4600}
  .cbt-qpill-zero{background:#dc3545}
  .cbt-qpill-rg{background:#0d6efd}
  .cbt-qpill-na{background:#dc3545;opacity:.75}

  .cbt-legend{display:flex;flex-wrap:wrap;gap:.9rem;align-items:center;margin-bottom:.9rem;font-size:.8rem}
  .cbt-legend span{display:inline-flex;align-items:center;gap:.35rem}

  @media(max-width:640px){
    .cbt-banner-title{font-size:1.1rem}
    .cbt-tabs{overflow-x:auto;flex-wrap:nowrap}
  }
</style>
@endpush
@endonce
