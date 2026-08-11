@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Application Form'])
@section('title', 'Application Form')
@section('bare', true)

@push('head')
<style>
  .adm-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
</style>
@endpush

@section('content')
<div style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head" style="justify-content:center;text-align:center">
    <div>
      @php($logo = $school->branding()['logo'] ?? null)
      <div class="adm-logo">
        @if($logo)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $school->name }} logo" />
        @else
          {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
        @endif
      </div>
      <h1 class="title">Supporting Documents</h1>
      <p class="subtitle">Step {{ $stepIndex + 1 }} of {{ $totalSteps }}</p>
    </div>
  </div>

  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  <div class="card">
    <p class="muted" style="margin-bottom:1rem">Upload whichever of these you already have. All are optional, you can add or replace one later from your dashboard.</p>
    <form method="POST" action="{{ route('school.admissions.documents.save', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      @foreach($documentTypes as $key => $label)
        @php($doc = $existing[$key] ?? null)
        <div class="field">
          <label for="doc_{{ $key }}">{{ $label }}</label>
          @if($doc && $doc->status === 'verified')
            <div class="badge badge-ok" style="display:inline-block">Verified</div>
          @else
            <input id="doc_{{ $key }}" name="{{ $key }}" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" />
            @if($doc && $doc->status === 'pending')
              <div class="hint">Uploaded, awaiting review. Choose a file above to replace it.</div>
            @elseif($doc && $doc->status === 'rejected')
              <div class="hint" style="color:var(--bad)">Not accepted{{ $doc->notes ? ': '.$doc->notes : '.' }} Please upload a replacement.</div>
            @endif
          @endif
        </div>
      @endforeach
      <button class="btn btn-primary btn-block" type="submit">Finish</button>
    </form>
  </div>
</div>
@endsection
