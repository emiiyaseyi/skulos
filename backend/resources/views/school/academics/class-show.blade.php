@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $class->name])
@section('title', $class->name)

@section('content')
{{-- The class roster (2026-08-16). What is shown depends on the
     viewer: a teacher scoped to their own arms sees only those, a
     parent sees only their own child, and parent contact details
     appear only for a role that may see them -- seeing a child's record
     and seeing their family's contacts are different levels of access. --}}
<div class="card" style="margin-bottom:1.2rem">
  <div class="row-between" style="margin-bottom:.6rem">
    <h3 style="margin:0">Students in this class ({{ $students->count() }})</h3>
    @if($rosterIsScoped)<span class="muted" style="font-size:.82rem">Showing only the students you have access to.</span>@endif
  </div>

  @if($students->isEmpty())
    <p class="muted" style="margin:0">No students in this class yet.</p>
  @else
    {{-- Reuses the existing student bulk endpoint rather than adding a
         second one (2026-08-16). The form sits OUTSIDE the table and
         the checkboxes attach to it by id, because each row already
         holds its own View form and HTML forms cannot nest -- the
         pattern the houses list established. @csrf is present: without
         it every bulk action fails with "your session has expired",
         which is exactly what 26 other bulk forms were doing. --}}
    @if($canManageStudents)
      <form id="rosterBulkForm" method="POST" action="{{ route('school.students.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr>
            @if($canManageStudents)<th style="width:2.2rem"><input type="checkbox" id="roster-select-all" aria-label="Select all students" /></th>@endif
            <th>Name</th><th>Admission no.</th><th>Stream</th>
            @if($canViewParents)<th>Parent/Guardian</th>@endif
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($students as $student)
            @php($primary = $student->guardians->firstWhere('is_primary') ?? $student->guardians->first())
            <tr>
              @if($canManageStudents)
                <td><input type="checkbox" name="student_ids[]" value="{{ $student->id }}" form="rosterBulkForm" aria-label="Select {{ $student->first_name }} {{ $student->last_name }}" /></td>
              @endif
              <td data-label="Name">{{ $student->first_name }} {{ $student->last_name }}</td>
              <td data-label="Admission no." class="muted">{{ $student->admission_number }}</td>
              <td data-label="Stream" class="muted">{{ $student->currentClassArm?->arm?->name ?? '—' }}</td>
              @if($canViewParents)
                <td data-label="Parent/Guardian" class="muted">
                  @if($primary)
                    {{ $primary->name }}
                    @if($primary->phone)<div style="font-size:.82rem">{{ $primary->phone }}</div>@endif
                  @else
                    <span class="muted">Not recorded</span>
                  @endif
                </td>
              @endif
              <td>
                @if($canViewStudents)
                  <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">View</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    @if($canManageStudents)
      <div style="margin-top:.9rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" type="submit" name="action" value="graduated" form="rosterBulkForm">Mark graduated</button>
        <button class="btn btn-ghost btn-sm" type="submit" name="action" value="withdrawn" form="rosterBulkForm">Mark withdrawn</button>
        <button class="btn btn-ghost btn-sm" type="submit" name="action" value="suspended" form="rosterBulkForm">Suspend</button>
        <button class="btn btn-ghost btn-sm" type="submit" name="action" value="active" form="rosterBulkForm">Mark active</button>
        <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" form="rosterBulkForm"
                onclick="return confirm('Remove the selected student(s)? They can be restored from Trashed.')">Delete</button>
      </div>
      <script>
        document.getElementById('roster-select-all')?.addEventListener('change', function () {
          document.querySelectorAll('input[name="student_ids[]"]').forEach(function (box) { box.checked = this.checked; }, this);
        });
        document.getElementById('rosterBulkForm')?.addEventListener('submit', function (e) {
          if (!document.querySelector('input[name="student_ids[]"]:checked')) {
            e.preventDefault();
            alert('Tick at least one student first.');
          }
        });
      </script>
    @endif
  @endif
</div>

<div class="page-head">
  <div>
    <h1 class="title">{{ $class->name }} <span class="badge {{ $class->is_active ? 'badge-ok' : 'badge-warn' }}" style="margin-left:.4rem">{{ $class->is_active ? 'Active' : 'Retired' }}</span></h1>
    <p class="subtitle">{{ $class->section?->name }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.academics.subjects', ['school' => $school->slug, 'classId' => $class->id]) }}">Manage subjects</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.academics.index', ['school' => $school->slug]) }}">Back to Classes &amp; Subjects</a>
  </div>
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.1rem">
  <div class="row-between" style="flex-wrap:wrap;gap:.6rem">
    <form method="POST" action="{{ route('school.academics.classes.update', ['school' => $school->slug, 'classId' => $class->id]) }}" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
      @csrf
      <div class="field" style="margin-bottom:0">
        <label for="c_rename">Class name</label>
        <input id="c_rename" name="name" value="{{ $class->name }}" required />
      </div>
      <div class="field" style="margin-bottom:0;width:6rem">
        <label for="c_sequence">Position</label>
        <input id="c_sequence" name="sequence" type="number" min="1" value="{{ $class->sequence }}" />
      </div>
      <button class="btn btn-ghost btn-sm" type="submit">Save</button>
    </form>
    <div style="display:flex;gap:.5rem">
      <form method="POST" action="{{ route('school.academics.classes.toggle-active', ['school' => $school->slug, 'classId' => $class->id]) }}">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">{{ $class->is_active ? 'Retire' : 'Reactivate' }}</button>
      </form>
      <form method="POST" action="{{ route('school.academics.classes.destroy', ['school' => $school->slug, 'classId' => $class->id]) }}" onsubmit="return confirm('Remove {{ $class->name }}? This only works if it has no students currently enrolled.')">
        @csrf @method('DELETE')
        <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Remove</button>
      </form>
    </div>
  </div>
</div>
@endif

@php($multiStream = $class->arms->count() > 1)

@foreach($class->arms as $arm)
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.5rem">
      <h3 style="margin:0">{{ $arm->label() }}{{ $multiStream ? '' : ' teachers' }}</h3>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        @if($canImportStudents)
          {{-- Class-scoped bulk import (2026-09-11): lets an admin
               upload a list of students straight into this one stream,
               with no Class column to repeat on every row -- separate
               from the school-wide import under Students, which still
               asks for a Class per row since it spans every class. --}}
          <a class="btn btn-ghost btn-sm" href="{{ route('school.students.import.class', ['school' => $school->slug, 'armId' => $arm->id]) }}">Import students</a>
        @endif
        @if($canManage && $multiStream)
          <form method="POST" action="{{ route('school.academics.arms.rename', ['school' => $school->slug, 'classArmId' => $arm->id]) }}" style="display:inline-flex;gap:.3rem">
            @csrf
            <input name="name" placeholder="Rename stream" value="{{ $arm->arm?->name }}" style="width:9rem;padding:.3rem .5rem" />
            <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
          </form>
        @endif
      </div>
    </div>
    <div class="grid cols-2">
      <div>
        <div class="muted" style="margin-bottom:.3rem">Class teacher</div>
        @if($canManage)
          <form method="POST" action="{{ route('school.academics.arms.assign-teacher', ['school' => $school->slug, 'armId' => $arm->id]) }}" style="display:flex;gap:.4rem">
            @csrf
            <input type="hidden" name="role" value="class_teacher" />
            <select name="staff_id" onchange="this.form.submit()">
              <option value="">Not set</option>
              @foreach($staffOptions as $staff)
                <option value="{{ $staff->id }}" @selected($arm->class_teacher_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
              @endforeach
            </select>
          </form>
        @else
          <div>{{ $arm->classTeacher?->fullName() ?? 'Not set' }}</div>
        @endif
      </div>
      <div>
        <div class="muted" style="margin-bottom:.3rem">Assistant class teacher</div>
        @if($canManage)
          <form method="POST" action="{{ route('school.academics.arms.assign-teacher', ['school' => $school->slug, 'armId' => $arm->id]) }}" style="display:flex;gap:.4rem">
            @csrf
            <input type="hidden" name="role" value="assistant_teacher" />
            <select name="staff_id" onchange="this.form.submit()">
              <option value="">Not set</option>
              @foreach($staffOptions as $staff)
                <option value="{{ $staff->id }}" @selected($arm->assistant_teacher_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
              @endforeach
            </select>
          </form>
        @else
          <div>{{ $arm->assistantTeacher?->fullName() ?? 'Not set' }}</div>
        @endif
      </div>
    </div>
  </div>
@endforeach

<div class="card">
  <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.6rem">
    <h3>Subjects offered</h3>
    @if($canManage)
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn btn-ghost btn-sm" href="{{ route('school.academics.subject-teachers.template', ['school' => $school->slug]) }}">Download teacher assignments (CSV)</a>
        <form method="POST" action="{{ route('school.academics.subject-teachers.bulk-assign', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:.4rem;align-items:center">
          @csrf
          <input type="file" name="file" accept=".csv,text/csv" required style="max-width:12rem" />
          <button class="btn btn-ghost btn-sm" type="submit">Upload</button>
        </form>
      </div>
    @endif
  </div>
  @if($class->subjects->isEmpty())
    <p class="muted">No subjects offered yet. <a href="{{ route('school.academics.subjects', ['school' => $school->slug, 'classId' => $class->id]) }}">Manage subjects</a> to add some.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Subject</th><th>Teacher</th></tr></thead>
      <tbody>
        @foreach($class->subjects as $subject)
          <tr>
            <td><strong>{{ $subject->name }}</strong></td>
            <td class="muted">
              @if($canManage)
                <form method="POST" action="{{ route('school.academics.subjects.assign-teacher', ['school' => $school->slug, 'classId' => $class->id, 'subjectId' => $subject->id]) }}" style="display:inline-flex;gap:.4rem">
                  @csrf
                  <select name="staff_id" onchange="this.form.submit()">
                    <option value="">Not assigned</option>
                    @foreach($staffOptions as $staff)
                      <option value="{{ $staff->id }}" @selected(($staffIdBySubject[$subject->id] ?? null) === $staff->id)>{{ $staff->fullName() }}</option>
                    @endforeach
                  </select>
                </form>
                @if(($teachersBySubject[$subject->id] ?? '') && str_contains($teachersBySubject[$subject->id], ','))
                  <div style="font-size:.75rem;margin-top:.2rem">All: {{ $teachersBySubject[$subject->id] }}</div>
                @endif
              @else
                {{ $teachersBySubject[$subject->id] ?? 'Not assigned' }}
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
