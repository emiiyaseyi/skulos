@extends('layouts.app', ['portal' => 'admissions', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Application Form'])
@section('title', 'Application Form')
@section('bare', true)

@section('content')
<div style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head">
    <div>
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
