@extends('layouts.app', ['portal' => 'school', 'pageTitle' => $subject->name])
@section('title', $subject->name)
@section('heading', $subject->name)
@section('crumb', 'Who teaches this subject, class by class')
@section('content')

@if($staff->isEmpty())
  <div class="flash flash-bad" role="alert" style="margin-bottom:1.2rem">
    Add your staff first. No teacher can be assigned to this subject until there are staff on record.
    <a href="{{ route('school.staff.index', ['school' => $school->slug]) }}" style="margin-left:.4rem">Go to Staff</a>
  </div>
@endif

@if($canManage)
  <div class="card" style="margin-bottom:1.2rem">
    <h3>Subject details</h3>
    <form method="POST" action="{{ route('school.subjects.update', ['school' => $school->slug, 'subjectId' => $subject->id]) }}">
      @csrf
      <div class="grid cols-2" style="gap:.8rem">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" value="{{ $subject->name }}" required maxlength="100" />
        </div>
        <div class="field">
          <label for="code">Code</label>
          <input id="code" name="code" value="{{ $subject->code }}" maxlength="20" />
        </div>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save</button>
    </form>
  </div>
@endif

@if($arms->isEmpty())
  <div class="card">
    <p class="muted">
      No class takes this subject yet. Attach it to classes from Classes &amp; Subjects, then come
      back here to assign teachers.
    </p>
  </div>
@else
  @if($canManage && $staff->isNotEmpty() && $uncovered->isNotEmpty())
    {{-- Assign one teacher to the classes ticked for them, then repeat.
         Deliberately not "one teacher for the whole subject": a subject
         is usually split across several teachers, and the count left
         uncovered is what tells an admin whether they are finished. --}}
    <div class="card" style="margin-bottom:1.2rem">
      <h3 style="margin-bottom:.3rem">Assign a teacher</h3>
      <p class="muted" style="margin-bottom:.9rem">
        {{ $uncovered->count() }} class(es) still have no teacher for this subject. Pick a teacher,
        tick the classes they take, and save. Repeat until none are left.
      </p>
      <form method="POST" action="{{ route('school.subjects.assign-teacher', ['school' => $school->slug, 'subjectId' => $subject->id]) }}">
        @csrf
        <div class="field" style="max-width:22rem">
          <label for="staff_id">Teacher</label>
          <select id="staff_id" name="staff_id" required>
            <option value="">Choose a teacher</option>
            @foreach($staff as $member)
              <option value="{{ $member->id }}">{{ $member->first_name }} {{ $member->last_name }}</option>
            @endforeach
          </select>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem">
          <b style="font-size:.9rem">Classes without a teacher</b>
          <button type="button" class="btn btn-ghost btn-sm" id="pickAllUncovered">Select all</button>
        </div>
        {{-- Grouped by section (2026-09-11, user directive) rather than
             one flat grid -- a subject offered across Nursery, Primary,
             and Secondary alike is otherwise a long, unlabeled list of
             checkboxes with no way to tell which section a name belongs
             to at a glance. --}}
        @foreach($uncovered->groupBy(fn ($arm) => $arm->schoolClass?->section?->name ?? 'Other') as $sectionName => $armsInSection)
          <div style="margin-bottom:.7rem">
            <div class="muted" style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;margin-bottom:.35rem">{{ $sectionName }}</div>
            <div class="grid cols-3" style="gap:.35rem">
              @foreach($armsInSection as $arm)
                <label class="module-check">
                  <input type="checkbox" name="class_arm_ids[]" value="{{ $arm->id }}" data-uncovered />
                  {{ $arm->label() }}
                </label>
              @endforeach
            </div>
          </div>
        @endforeach
        <button class="btn btn-primary btn-sm" type="submit">Assign to selected classes</button>
      </form>
    </div>
  @endif

  <div class="card">
    <h3 style="margin-bottom:.8rem">Every class taking {{ $subject->name }}</h3>
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Class</th><th>Section</th><th>Teacher</th><th></th></tr></thead>
        <tbody>
          @foreach($arms as $arm)
            @php($assignment = $teacherByArm->get($arm->id))
            <tr>
              <td data-label="Class">{{ $arm->label() }}</td>
              <td data-label="Section" class="muted">{{ $arm->schoolClass?->section?->name ?? '—' }}</td>
              <td data-label="Teacher">
                @if($assignment?->staff)
                  {{ $assignment->staff->first_name }} {{ $assignment->staff->last_name }}
                @else
                  <span class="badge badge-warn">No teacher yet</span>
                @endif
              </td>
              <td>
                @if($canManage && $assignment)
                  <form method="POST" action="{{ route('school.subjects.unassign-teacher', ['school' => $school->slug, 'subjectId' => $subject->id]) }}">
                    @csrf
                    <input type="hidden" name="class_arm_id" value="{{ $arm->id }}" />
                    <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

<script>
  document.getElementById('pickAllUncovered')?.addEventListener('click', function () {
    var boxes = document.querySelectorAll('input[data-uncovered]');
    var allOn = Array.prototype.every.call(boxes, function (b) { return b.checked; });
    boxes.forEach(function (b) { b.checked = !allOn; });
    this.textContent = allOn ? 'Select all' : 'Clear all';
  });
</script>
@endsection
