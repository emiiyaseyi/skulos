@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Help & Knowledge Base'])
@section('title', 'Help & Knowledge Base')

@push('head')
<style>
  .kb-search-card{background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 70%,#000));border-radius:14px;padding:1.5rem;margin-bottom:1.25rem}
  .kb-search-card h2{color:var(--accent-ink);font-size:1.15rem;margin-bottom:.3rem;text-align:center}
  .kb-search-card p{color:var(--accent-ink);opacity:.9;font-size:.85rem;margin-bottom:1rem;text-align:center}
  .kb-search-card form{max-width:26rem;margin:0 auto}
  .kb-search-card input{border:none}
  .kb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem}
  .kb-cat{display:flex;flex-direction:column}
  .kb-cat-head{display:flex;align-items:center;gap:.6rem;margin-bottom:.9rem}
  .kb-cat-icon{width:2.3rem;height:2.3rem;border-radius:10px;background:var(--soft);display:grid;place-items:center;flex-shrink:0;color:var(--accent)}
  .kb-cat-icon .ic{width:1.2rem;height:1.2rem}
  .kb-cat-head h3{margin:0;font-size:1rem}
  .kb-article-link{display:block;padding:.6rem 0;border-bottom:1px solid var(--line);text-decoration:none}
  .kb-article-link:last-child{border-bottom:none}
  .kb-article-link strong{display:block;color:var(--ink);font-size:.92rem;margin-bottom:.15rem}
  .kb-article-link span{display:block;color:var(--mut);font-size:.78rem;line-height:1.4}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Help &amp; Knowledge Base</h1>
    <p class="subtitle">Short guides on how to do things in SKULOS.</p>
  </div>
</div>

{{-- User request 2026-07-22: "let's make this section much more presentable and great." --}}
<div class="kb-search-card">
  <h2>What do you need help with?</h2>
  <p>Search across every guide, or browse by topic below.</p>
  <form method="GET">
    <input id="q" name="q" value="{{ $q }}" placeholder="e.g. attendance, report card, timetable" />
  </form>
</div>

@if($categories->isEmpty())
  <div class="card">
    <p class="muted">
      @if($q !== '')
        No articles match "{{ $q }}".
      @else
        Nothing here yet.
      @endif
    </p>
  </div>
@else
  <div class="kb-grid">
    @foreach($categories as $category)
      <div class="card kb-cat">
        <div class="kb-cat-head">
          <div class="kb-cat-icon">@include('layouts.partials.icon', ['name' => config('skulos.knowledge_base_category_icons')[$category->name] ?? 'support'])</div>
          <h3>{{ $category->name }}</h3>
        </div>
        <div>
          @foreach($category->articles as $article)
            <a class="kb-article-link" href="{{ route('school.knowledge-base.show', ['school' => $school->slug, 'slug' => $article->slug]) }}">
              <strong>{{ $article->title }}</strong>
              @if($article->body)<span>{{ \Illuminate\Support\Str::limit($article->body, 80) }}</span>@endif
            </a>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
@endif
@endsection
