@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admission Document Review'])
@section('title', 'Admission Document Review')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admission Document Review</h1>
    <p class="subtitle">Confirm or reject a supporting document an applicant uploaded (birth certificate, last report card, etc).</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.pipeline', ['school' => $school->slug]) }}">Applicants</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ session('error') }}</div>
@endif

<div class="card">
  @if($documents->isEmpty())
    <p class="muted">No documents uploaded yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Applicant</th><th>Document</th><th>File</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($documents as $document)
          <tr>
            <td data-label="Applicant"><strong>{{ $document->applicant?->name }}</strong><div class="muted">{{ $document->applicant?->admission_number }}</div></td>
            <td class="muted" data-label="Document">{{ $documentTypes[$document->document_type] ?? $document->document_type }}</td>
            <td data-label="File">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.document-review.file', ['school' => $school->slug, 'documentId' => $document->id]) }}" target="_blank" rel="noopener">View</a>
            </td>
            <td data-label="Status">
              <span class="badge {{ $document->status === 'verified' ? 'badge-ok' : ($document->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($document->status) }}</span>
            </td>
            <td style="white-space:nowrap">
              @if($document->status === 'pending')
                <form method="POST" action="{{ route('school.admissions.document-review.verify', ['school' => $school->slug, 'documentId' => $document->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Verify</button>
                </form>
                <details style="display:inline-block">
                  <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Reject</summary>
                  <form method="POST" action="{{ route('school.admissions.document-review.reject', ['school' => $school->slug, 'documentId' => $document->id]) }}" style="margin-top:.5rem;min-width:14rem">
                    @csrf
                    <textarea name="notes" rows="2" placeholder="Reason (optional)"></textarea>
                    <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:.4rem">Confirm reject</button>
                  </form>
                </details>
              @else
                <span class="muted">{{ $document->notes ?: 'Decided' }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $documents->links() }}</div>
  @endif
</div>
@endsection
