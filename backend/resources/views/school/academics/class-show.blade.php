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
      @if($canManage && $multiStream)
        <form method="POST" action="{{ route('school.academics.arms.rename', ['school' => $school->slug, 'classArmId' => $arm->id]) }}" style="display:inline-flex;gap:.3rem">
          @csrf
          <input name="name" placeholder="Rename stream" value="{{ $arm->arm?->name }}" style="width:9rem;padding:.3rem .5rem" />
          <button class="btn btn-ghost btn-sm" type="submit">Rename</button>
        </form>
      @endif
    </div>
    <div class="grid cols-2">
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
