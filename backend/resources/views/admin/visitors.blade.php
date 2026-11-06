@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Visitor Analytics'])
@section('title', 'Visitor Analytics')
@section('heading', 'Visitor Analytics')
@section('crumb', 'Who is coming to skulosapp.com, and from where')

@push('head')
<style>
  .va-figs{display:grid;grid-template-columns:repeat(4,1fr);gap:1.1rem;margin-bottom:1.5rem}
  @media(max-width:1000px){.va-figs{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:640px){.va-figs{grid-template-columns:1fr}}
  .va-fig{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:1.2rem 1.3rem;box-shadow:var(--shadow)}
  .va-fig b{font-size:1.6rem;font-weight:800;display:block}
  .va-fig span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)}
  .va-tables{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
  @media(max-width:900px){.va-tables{grid-template-columns:1fr}}
</style>
@endpush

@section('content')

@unless($hasAnyData)
  <div class="card">
    <p>No visits recorded yet. This page fills in as soon as a real visitor loads a marketing-site page with the tracking beacon on it (every page under <code>site/</code> after this feature's own deploy).</p>
  </div>
@endunless

<div class="stack" style="flex-direction:row;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap;align-items:center">
  @foreach([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $key => $label)
    <a class="btn btn-sm {{ !$customRange && $days === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('admin.visitors.index', ['days' => $key]) }}">{{ $label }}</a>
  @endforeach
  <form method="GET" action="{{ route('admin.visitors.index') }}" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
    <input type="date" name="from" value="{{ $from }}" style="padding:.4rem .6rem;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink)" />
    <span class="muted">to</span>
    <input type="date" name="to" value="{{ $to }}" style="padding:.4rem .6rem;border-radius:8px;border:1px solid var(--line);background:var(--panel);color:var(--ink)" />
    <button type="submit" class="btn btn-sm {{ $customRange ? 'btn-primary' : 'btn-ghost' }}">Custom range</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-top:0">Create a share link</h3>
  <p class="muted" style="margin-top:-.5rem">Pick a page and name this outreach (e.g. a WhatsApp group, a post), then share the generated link instead of the plain page address &mdash; it's what lets this screen tell that one apart from any other.</p>
  <div class="grid cols-2" style="align-items:end">
    <div class="field">
      <label for="va_page">Page</label>
      <select id="va_page">
        <option value="/">Home</option>
        <option value="/trial.html">Start a Trial</option>
        <option value="/pricing.html">Pricing</option>
        <option value="/about.html">About</option>
        <option value="/faq.html">FAQ</option>
        <option value="/contact.html">Contact</option>
      </select>
    </div>
    <div class="field">
      <label for="va_campaign">Campaign name</label>
      <input id="va_campaign" placeholder="e.g. lagos-owners-nov" />
    </div>
  </div>
  <div class="field" style="margin-top:.5rem">
    <label for="va_link">Share this link</label>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <input id="va_link" readonly style="flex:1;min-width:16rem" value="https://{{ config('app.domain') }}/" />
      <button type="button" class="btn btn-sm btn-ghost" id="va_copy">Copy</button>
    </div>
    <div class="hint" id="va_copied" style="display:none;color:var(--ok,#12A97B)">Copied.</div>
  </div>
</div>
<script>
(function(){
  var page = document.getElementById('va_page'), campaign = document.getElementById('va_campaign'),
      link = document.getElementById('va_link'), copyBtn = document.getElementById('va_copy'), copied = document.getElementById('va_copied');
  var domain = 'https://{{ config("app.domain") }}';
  function slugify(s){ return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, ''); }
  function rebuild(){
    var slug = slugify(campaign.value);
    link.value = domain + page.value + (slug ? '?utm_campaign=' + slug : '');
    copied.style.display = 'none';
  }
  page.addEventListener('change', rebuild);
  campaign.addEventListener('input', rebuild);
  copyBtn.addEventListener('click', function(){
    link.select();
    navigator.clipboard && navigator.clipboard.writeText(link.value).then(function(){
      copied.style.display = 'block';
    });
  });
  rebuild();
})();
</script>

<div class="va-figs">
  <div class="va-fig"><b>{{ number_format($totalViews) }}</b><span>Page views</span></div>
  <div class="va-fig"><b>{{ number_format($uniqueSessions) }}</b><span>Unique visitors</span></div>
  <div class="va-fig">
    <b>@if($avgSpanSeconds !== null){{ $avgSpanSeconds >= 60 ? floor($avgSpanSeconds / 60).'m '.($avgSpanSeconds % 60).'s' : $avgSpanSeconds.'s' }}@else &mdash; @endif</b>
    <span>Avg. time per visit (multi-page only)</span>
  </div>
  <div class="va-fig"><b>{{ $singlePageShare }}%</b><span>Left after one page</span></div>
</div>

<div class="va-tables">
  <div class="card">
    <h3 style="margin-top:0">Top pages</h3>
    @if($topPages->isEmpty())
      <p class="muted">No data for this period.</p>
    @else
      <table class="table-stack">
        <thead><tr><th>Page</th><th>Views</th></tr></thead>
        <tbody>
          @foreach($topPages as $row)
            <tr><td data-label="Page">{{ $row->path }}</td><td data-label="Views">{{ number_format($row->views) }}</td></tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-top:0">Traffic sources &amp; campaigns</h3>
    <p class="muted" style="margin-top:-.5rem">A link shared with <code>?utm_campaign=name</code> groups by that name here &mdash; use this to see which group share, post, or outreach link actually brought people in, and where to focus the next one.</p>
    @if($sources->isEmpty())
      <p class="muted">No data for this period.</p>
    @else
      <table class="table-stack">
        <thead><tr><th>Source</th><th>Views</th><th>Visitors</th></tr></thead>
        <tbody>
          @foreach($sources as $row)
            <tr>
              <td data-label="Source">{{ $row->source ?: 'Direct / Unknown' }}</td>
              <td data-label="Views">{{ number_format($row->views) }}</td>
              <td data-label="Visitors">{{ number_format($row->sessions) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

@endsection
