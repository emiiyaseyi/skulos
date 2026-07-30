@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assignment->title])
@section('title', $assignment->title)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $assignment->title }}</h1>
    <p class="subtitle">{{ $assignment->subject?->name }} &middot; {{ $assignment->classArm?->label() }} &middot; {{ $assignment->due_on ? 'Due '.$assignment->due_on->format('d M Y') : 'No due date' }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.index', ['school' => $school->slug]) }}">Back to Assignments</a>
</div>

@if($assignment->description)
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.5rem">Details</h3>
    <p>{{ $assignment->description }}</p>
  </div>
@endif

@if($isFamily)
  <div class="card">
    <h3 style="margin-bottom:.6rem">Your submission</h3>
    @if(! $canSubmit)
      <p class="muted">This assignment is not for your class.</p>
    @else
      @if($submission)
        <p class="badge badge-ok" style="margin-bottom:.8rem">Submitted {{ $submission->submitted_at?->format('d M Y, g:ia') }}</p>
        @if($submission->content)<p style="margin-bottom:.6rem">{{ $submission->content }}</p>@endif
        @if($submission->file_path)
          <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.submissions.file', ['school' => $school->slug, 'assignmentId' => $assignment->id, 'submissionId' => $submission->id]) }}" target="_blank" rel="noopener">View attached file</a>
        @endif
        <p class="muted" style="margin-top:.8rem">You can update your submission below at any time.</p>
      @else
        <p class="muted" style="margin-bottom:.8rem">You have not submitted this yet.</p>
      @endif

      <form method="POST" action="{{ route('school.assignments.submit', ['school' => $school->slug, 'assignmentId' => $assignment->id]) }}" enctype="multipart/form-data" style="margin-top:1rem">
        @csrf
        <div class="field">
          <label for="content">Written response (optional if attaching a file)</label>
          <textarea id="content" name="content" rows="5">{{ old('content', $submission?->content) }}</textarea>
          @error('content')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="file">Attach a file (optional)</label>
          <input id="file" name="file" type="file" accept="image/png,image/jpeg,image/webp,.pdf,.doc,.docx" />
          @error('file')<p class="err">{{ $message }}</p>@enderror
        </div>
        <button class="btn btn-primary btn-sm" type="submit">{{ $submission ? 'Update submission' : 'Submit' }}</button>
      </form>
    @endif
  </div>
@else
  <div class="card">
    <div class="row-between" style="margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem">
      <h3 style="margin:0">Submissions</h3>
      <span class="muted" style="font-size:.85rem">{{ $submittedCount }} of {{ $students->count() }} submitted</span>
    </div>
    @if($students->isEmpty())
      <p class="muted">No students in this class yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Admission No.</th><th>Name</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          @foreach($students as $student)
            @php($submission = $submissions->get($student->id))
            <tr>
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td>
                @if($submission)
                  <span class="badge badge-ok">Submitted</span>
                @else
                  <span class="badge badge-warn">Not submitted</span>
                @endif
              </td>
              <td class="muted" style="white-space:nowrap">{{ $submission?->submitted_at?->format('d M Y, g:ia') ?? 'N/A' }}</td>
              <td style="white-space:nowrap">
                @if($submission?->content)
                  <span class="muted" title="{{ $submission->content }}">{{ \Illuminate\Support\Str::limit($submission->content, 40) }}</span>
                @endif
                @if($submission?->file_path)
                  <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.submissions.file', ['school' => $school->slug, 'assignmentId' => $assignment->id, 'submissionId' => $submission->id]) }}" target="_blank" rel="noopener">File</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>
@endif
@endsection
