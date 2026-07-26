@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $announcement->title])
@section('title', $announcement->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $announcement->title }}</h1>
    <p class="subtitle">{{ ucfirst($announcement->audience) }} &middot; {{ $announcement->published_at?->format('d M Y, g:i a') ?? 'Not sent' }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.announcements.index', ['school' => $school->slug]) }}">Back to Announcements</a>
</div>

<div class="card">
  <p style="white-space:pre-wrap">{{ $announcement->body }}</p>
</div>
@endsection
