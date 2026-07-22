@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $article->title])
@section('title', $article->title)

@push('head')
<style>
  .kb-steps{list-style:none;counter-reset:kbstep;margin:0;padding:0;display:flex;flex-direction:column;gap:.9rem}
  .kb-steps li{counter-increment:kbstep;display:flex;gap:.9rem;align-items:flex-start}
  .kb-steps li::before{content:counter(kbstep);flex-shrink:0;width:1.8rem;height:1.8rem;border-radius:50%;background:var(--accent);color:var(--accent-ink);font-weight:800;font-size:.85rem;display:grid;place-items:center}
  .kb-steps li span{padding-top:.15rem;line-height:1.6}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <p class="subtitle" style="margin-bottom:.3rem">{{ $article->category->name }}</p>
    <h1 class="title">{{ $article->title }}</h1>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.knowledge-base.index', ['school' => $school->slug]) }}">Back to Help</a>
</div>

<div class="card" style="max-width:44rem">
  @if($article->body)
    <p class="muted" style="line-height:1.7;margin-bottom:{{ $article->steps ? '1.2rem' : '0' }}">{{ $article->body }}</p>
  @endif

  {{-- User request 2026-07-22: "this should be presented more as a procedure or step." A real, structured step list where one exists; falls back to nothing extra for an older admin-added article that only ever had a plain body. --}}
  @if(! empty($article->steps))
    <ol class="kb-steps">
      @foreach($article->steps as $step)
        <li><span>{{ $step }}</span></li>
      @endforeach
    </ol>
  @endif

  {{-- User request 2026-07-22: "add a button that takes them to where to start the process or knowledge under help." --}}
  @if($article->start_route && \Illuminate\Support\Facades\Route::has($article->start_route))
    <div style="margin-top:1.4rem;padding-top:1.2rem;border-top:1px solid var(--line)">
      <a class="btn btn-primary btn-sm" href="{{ route($article->start_route, ['school' => $school->slug]) }}">Start here</a>
    </div>
  @endif
</div>
@endsection
