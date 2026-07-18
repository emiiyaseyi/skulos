@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => $label])
@section('title', $label)
@section('heading', $label)
@section('crumb', 'Editing '.$file)

@push('head')
<style>
  .html-editor{width:100%;min-height:65vh;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.82rem;line-height:1.5;tab-size:2}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $label }}</h1>
    <p class="subtitle">Raw HTML source for {{ $file }}. Saving updates the file on this server only, publishing it live still needs a commit and push.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.marketing-pages.index') }}">Back to Marketing Pages</a>
</div>

<div class="card">
  <form method="POST" action="{{ route('admin.marketing-pages.update', ['page' => $file]) }}">
    @csrf
    <div class="field">
      <textarea name="content" class="html-editor" spellcheck="false">{{ old('content', $content) }}</textarea>
      @error('content')<p class="err">{{ $message }}</p>@enderror
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:1rem">Save</button>
  </form>
</div>
@endsection
