@extends('layouts.app', ['portal' => 'school', 'pageTitle' => 'Subjects'])
@section('title', 'Subjects')
@section('heading', 'Subjects')
@section('crumb', 'Every subject this school teaches, and whether each class taking it has a teacher')
@section('content')

@if($staffCount === 0)
  {{-- The warning the platform owner asked for: every teacher-facing
       action on this page is impossible without staff, and the
       workbook's dropdown would have nothing to offer. --}}
  <div class="flash flash-bad" role="alert" style="margin-bottom:1.2rem">
    Add your staff first. Until then no teacher can be assigned to a subject, and the assignment
    workbook has no names to choose from.
    <a href="{{ route('school.staff.index', ['school' => $school->slug]) }}" style="margin-left:.4rem">Go to Staff</a>
  </div>
@endif

@if($canManage)
  <div class="grid cols-2" style="gap:1.2rem;margin-bottom:1.2rem">
    <div class="card">
      <h3>Add a subject</h3>
      <form method="POST" action="{{ route('school.subjects.store', ['school' => $school->slug]) }}">
        @csrf
        <div class="grid cols-2" style="gap:.8rem">
          <div class="field">
            <label for="name">Subject name</label>
            <input id="name" name="name" required maxlength="100" placeholder="e.g. Mathematics" />
          </div>
          <div class="field">
            <label for="code">Code</label>
            <input id="code" name="code" maxlength="20" placeholder="Optional" />
          </div>
        </div>
        <div class="field">
          <label for="category">Group on the report card</label>
          <input id="category" name="category" maxlength="80" placeholder="Optional, e.g. Languages" />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add subject</button>
      </form>
    </div>

    <div class="card">
      <h3>Assign teachers in bulk</h3>
      <p class="muted" style="margin-bottom:.8rem">
        Downloads one row per class and subject, with the teacher as a dropdown of your own staff.
        Fill it in, then upload it back.
      </p>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.9rem">
        <a class="btn btn-ghost btn-sm" href="{{ route('school.subjects.workbook', ['school' => $school->slug]) }}">Download workbook</a>
      </div>
      <form method="POST" action="{{ route('school.subjects.workbook.upload', ['school' => $school->slug]) }}" enctype="multipart/form-data">
        @csrf
        <div class="field" style="margin-bottom:.5rem">
          <label for="file">Upload the completed workbook</label>
          <input id="file" name="file" type="file" accept=".xlsx,.xls" required />
          <div class="hint">Rows with a blank teacher are left as they are.</div>
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">Upload</button>
      </form>
    </div>
  </div>
@endif

<div class="card">
  <h3 style="margin-bottom:.8rem">All subjects</h3>
  @if($rows->isEmpty())
    <p class="muted">No subjects yet. Add one above, or attach the standard list from a class under Classes &amp; Subjects.</p>
  @else
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr><th>Subject</th><th>Code</th><th>Report group</th><th>Classes taking it</th><th>With a teacher</th><th>Still uncovered</th><th></th></tr>
        </thead>
        <tbody>
          @foreach($rows as $row)
            <tr>
              <td data-label="Subject">
                <a href="{{ route('school.subjects.show', ['school' => $school->slug, 'subjectId' => $row['subject']->id]) }}">{{ $row['subject']->name }}</a>
              </td>
              <td data-label="Code" class="muted">{{ $row['subject']->code ?: '—' }}</td>
              <td data-label="Report group" class="muted">{{ $row['subject']->category ?: '—' }}</td>
              <td data-label="Classes taking it" class="muted">{{ $row['arms'] }}</td>
              <td data-label="With a teacher" class="muted">{{ $row['covered'] }}</td>
              <td data-label="Still uncovered">
                @if($row['arms'] === 0)
                  <span class="muted">Not offered yet</span>
                @elseif($row['uncovered'] === 0)
                  <span class="badge badge-ok">All covered</span>
                @else
                  <span class="badge badge-warn">{{ $row['uncovered'] }} class(es)</span>
                @endif
              </td>
              <td>
                <a class="btn btn-ghost btn-sm" href="{{ route('school.subjects.show', ['school' => $school->slug, 'subjectId' => $row['subject']->id]) }}">Teachers</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
