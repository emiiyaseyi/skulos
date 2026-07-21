@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $article->title])
@section('title', $article->title)

@section('content')
<div class="page-head">
  <div>
    <p class="subtitle" style="margin-bottom:.3rem">{{ $article->category->name }}</p>
    <h1 class="title">{{ $article->title }}</h1>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.knowledge-base.index', ['school' => $school->slug]) }}">Back to Help</a>
</div>

<div class="card" style="max-width:44rem">
  <div style="white-space:pre-line;line-height:1.7">{{ $article->body }}</div>
</div>
@endsection
