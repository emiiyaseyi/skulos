@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Help & Knowledge Base'])
@section('title', 'Help & Knowledge Base')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Help &amp; Knowledge Base</h1>
    <p class="subtitle">Short guides on how to do things in SKULOS.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem;max-width:28rem">
  <form method="GET">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input id="q" name="q" value="{{ $q }}" placeholder="e.g. attendance, report card, timetable" />
    </div>
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
  @foreach($categories as $category)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.7rem">{{ $category->name }}</h3>
      <div style="display:flex;flex-direction:column;gap:.5rem">
        @foreach($category->articles as $article)
          <a href="{{ route('school.knowledge-base.show', ['school' => $school->slug, 'slug' => $article->slug]) }}" style="padding:.5rem 0;border-bottom:1px solid var(--line)">{{ $article->title }}</a>
        @endforeach
      </div>
    </div>
  @endforeach
@endif
@endsection
