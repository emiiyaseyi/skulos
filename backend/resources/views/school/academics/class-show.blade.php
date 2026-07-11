@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $class->name])
@section('title', $class->name)

@section('content')
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
      <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
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

@foreach($class->arms as $arm)
  <div class="card" style="margin-bottom:1.1rem">
    <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.5rem">
      <h3>{{ $arm->label() }}</h3>
      <span class="muted">{{ ($studentsByArm[$arm->id] ?? collect())->count() }} student(s)</span>
    </div>

    <div class="grid cols-2" style="margin-bottom:1rem">
      <div>
        <div class="muted" style="font-size:.8rem;margin-bottom:.3rem">Class teacher</div>
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
        <div class="muted" style="font-size:.8rem;margin-bottom:.3rem">Assistant class teacher</div>
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

    @php($students = $studentsByArm[$arm->id] ?? collect())
    @if($students->isEmpty())
      <p class="muted">No students in this stream yet.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Admission No.</th><th>Name</th><th>Gender</th></tr></thead>
        <tbody>
          @foreach($students as $student)
            <tr>
              <td class="muted">{{ $student->admission_number }}</td>
              <td><a href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">{{ $student->fullName() }}</a></td>
              <td class="muted">{{ $student->gender ?: 'Not set' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>
@endforeach

<div class="card">
  <h3 style="margin-bottom:.7rem">Subjects offered</h3>
  @if($class->subjects->isEmpty())
    <p class="muted">No subjects offered yet. <a href="{{ route('school.academics.subjects', ['school' => $school->slug, 'classId' => $class->id]) }}">Manage subjects</a> to add some.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Subject</th><th>Teacher(s)</th></tr></thead>
      <tbody>
        @foreach($class->subjects as $subject)
          <tr>
            <td><strong>{{ $subject->name }}</strong></td>
            <td class="muted">{{ $teachersBySubject[$subject->id] ?? 'Not assigned' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
