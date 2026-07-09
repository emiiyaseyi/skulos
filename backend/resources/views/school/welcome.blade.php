@extends('layouts.app', ['accent' => $accent])
@section('title', $school->name)
@section('bare', true)
@section('content')
@php($b = $school->branding())
<div class="auth-shell">
  <div class="auth-card" style="text-align:center">
    @if($b['logo'])
      <img src="{{ \Illuminate\Support\Facades\Storage::url($b['logo']) }}" alt="{{ $school->name }}" style="width:72px;height:72px;object-fit:contain;border-radius:16px;margin:0 auto 1rem" />
    @else
      <div class="auth-logo" style="margin-inline:auto">{{ strtoupper(substr($school->name,0,1)) }}</div>
    @endif
    <h1 class="title" style="font-size:1.5rem">{{ $b['headline'] }}</h1>
    <p class="muted" style="margin:.4rem 0 1.5rem">{{ $b['message'] }}</p>
    <a class="btn btn-primary btn-block" href="{{ route('school.login', ['school' => $school->slug]) }}">Sign in</a>
    <p class="hint" style="margin-top:1rem">Powered by SKULOS</p>
  </div>
</div>
@endsection
