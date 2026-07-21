@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Students'])
@section('title', 'Students')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Students</h1>
    <p class="subtitle">{{ number_format($total) }} on record.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canManage)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.students.trashed', ['school' => $school->slug]) }}">Trashed</a>
    @endif
    @if($canCreate)
      <a class="btn btn-primary btn-sm" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add student</a>
    @endif
    @if($canImport)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.students.import', ['school' => $school->slug]) }}">Bulk import</a>
      <a class="btn btn-ghost btn-sm" href="{{ route('school.students.export', ['school' => $school->slug, 'q' => $search ?: null]) }}">Export CSV</a>
    @endif
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:22rem">
    <input type="search" name="q" value="{{ $search }}" placeholder="Search by name or admission number" />
  </form>
</div>

@if($students !== null)
  <div class="card">
    @if($students->isEmpty())
      <p class="muted">{{ $search ? 'No students match your search.' : 'No students assigned to you yet.' }}</p>
    @else
      @if($canManage)
        <form id="bulkStudentsForm" method="POST" action="{{ route('school.students.bulk-action', ['school' => $school->slug]) }}"></form>
      @endif
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="student-select-all" aria-label="Select all students" /></th>@endif
          <th>Admission No.</th><th>Name</th><th>Gender</th><th>Status</th>
        </tr></thead>
        <tbody>
          @foreach($students as $student)
            <tr class="row-link" onclick="window.location='{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}'" style="cursor:pointer">
              @if($canManage)
                <td onclick="event.stopPropagation()"><input type="checkbox" form="bulkStudentsForm" class="student-row-check" name="student_ids[]" value="{{ $student->id }}" aria-label="Select {{ $student->fullName() }}" /></td>
              @endif
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td>{{ $student->gender ? ucfirst($student->gender) : 'Not set' }}</td>
              <td><span class="badge {{ $student->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($student->status) }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <div style="margin-top:1rem">{{ $students->links() }}</div>

      @if($canManage)
        <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
          <span class="muted" style="font-size:.85rem">Tick any number of students above, then choose an action.</span>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button form="bulkStudentsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="active">Mark active</button>
            <button form="bulkStudentsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="graduated">Mark graduated</button>
            <button form="bulkStudentsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="withdrawn">Mark withdrawn</button>
            <button form="bulkStudentsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="suspended">Suspend</button>
            <button form="bulkStudentsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Move the selected students to Trashed? You can restore them from there afterwards.')">Delete selected</button>
          </div>
        </div>
        <script>
          (function(){
            var all = document.getElementById('student-select-all');
            var boxes = document.querySelectorAll('.student-row-check');
            all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
            document.getElementById('bulkStudentsForm')?.addEventListener('submit', function(e){
              var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
              if (! anyChecked) { e.preventDefault(); alert('Tick at least one student first.'); }
            });
          })();
        </script>
      @endif
    @endif
  </div>
@elseif($subjectGroups !== null)
  @if($subjectGroups->isEmpty())
    <div class="card"><p class="muted">No subjects assigned to you yet.</p></div>
  @else
    @foreach($subjectGroups as $sectionName => $subjects)
      <div class="card" style="margin-bottom:1.1rem">
        <h3 style="margin-bottom:.7rem">{{ $sectionName }}</h3>
        @foreach($subjects as $subjectName => $group)
          {{-- Collapsed by default (user request 2026-07-12: a teacher with several subjects should see them all at a glance without scrolling through every student table first). --}}
          <details class="subject-disclosure" style="margin-bottom:.6rem">
            <summary style="cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;padding:.6rem .2rem;border-bottom:1px solid var(--line)">
              <span><strong>{{ $subjectName }}</strong> <span class="muted" style="font-size:.85rem">&middot; {{ $group['students']->count() }} student(s)</span></span>
              <span class="muted" style="font-size:.85rem">{{ $group['classArms']->map->label()->implode(', ') }}</span>
            </summary>
            <div style="padding-top:.6rem">
            @if($group['students']->isEmpty())
              <p class="muted">No students in these classes yet.</p>
            @else
              <div style="overflow-x:auto">
              <table>
                <thead><tr><th>Admission No.</th><th>Name</th><th>Class</th></tr></thead>
                <tbody>
                  @foreach($group['students'] as $student)
                    <tr class="row-link" onclick="window.location='{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}'" style="cursor:pointer">
                      <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
                      <td><strong>{{ $student->fullName() }}</strong></td>
                      <td class="muted">{{ $student->currentClassArm?->label() }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
              </div>
            @endif
            </div>
          </details>
        @endforeach
      </div>
    @endforeach
  @endif
@else
  @if($sections->isEmpty())
    <div class="card"><p class="muted">No classes yet. Set up your class structure under Classes &amp; Subjects, then students grouped by class will show here.</p></div>
  @else
    @foreach($sections as $section)
      <div class="card" style="margin-bottom:1.1rem">
        <h3 style="margin-bottom:.7rem">{{ $section->name }}</h3>
        @if($section->classes->isEmpty())
          <p class="muted">No classes in this section yet.</p>
        @else
          <div style="overflow-x:auto">
          <table>
            <thead><tr><th>Class</th><th>Students</th><th></th></tr></thead>
            <tbody>
              @foreach($section->classes as $class)
                {{-- Triggered on arm COUNT, not on whether an arm happens
                     to be named: storeArm() converts a class's original
                     default arm in place for the very first stream added
                     (so a class with exactly one arm is a flat single
                     stream row either way, named or not), only the
                     second stream onward creates a genuinely separate
                     ClassArm row. Counting arms rather than filtering by
                     name also means a legacy/orphaned unnamed arm from
                     before that conversion logic existed still shows up
                     as its own row with its own real students, never
                     silently dropped. --}}
                @if($class->arms->count() > 1)
                  {{-- A class actually split into streams carries no data
                       of its own on this row (user directive 2026-07-21:
                       "when stream is used, the class name has no data as
                       the data falls under the stream") — it is a heading
                       only, each real stream underneath is where the
                       students actually are. --}}
                  <tr>
                    <td>
                      <strong>{{ $class->name }}</strong>
                      @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                    </td>
                    <td></td>
                    <td></td>
                  </tr>
                  @foreach($class->arms->sortBy(fn($arm) => $arm->orderKey()) as $arm)
                    <tr>
                      <td style="padding-left:1.6rem" class="muted">{{ $arm->label() }}</td>
                      <td class="muted">{{ $classCounts[$arm->id] ?? 0 }}</td>
                      <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
                    </tr>
                  @endforeach
                @else
                  @php($classTotal = $class->arms->sum(fn($arm) => $classCounts[$arm->id] ?? 0))
                  <tr>
                    <td>
                      <strong>{{ $class->name }}</strong>
                      @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                    </td>
                    <td class="muted">{{ $classTotal }}</td>
                    <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
                  </tr>
                @endif
              @endforeach
            </tbody>
          </table>
          </div>
        @endif
      </div>
    @endforeach
  @endif

  @if($unassigned->isNotEmpty())
    <div class="card">
      <h3 style="margin-bottom:.7rem">No class assigned</h3>
      @if($canManage)
        <form id="bulkUnassignedForm" method="POST" action="{{ route('school.students.bulk-action', ['school' => $school->slug]) }}"></form>
      @endif
      <div style="overflow-x:auto">
      <table>
        <thead><tr>
          @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="unassigned-select-all" aria-label="Select all unassigned students" /></th>@endif
          <th>Admission No.</th><th>Name</th><th></th>
        </tr></thead>
        <tbody>
          @foreach($unassigned as $student)
            <tr class="row-link" onclick="window.location='{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}'" style="cursor:pointer">
              @if($canManage)
                <td onclick="event.stopPropagation()"><input type="checkbox" form="bulkUnassignedForm" class="unassigned-row-check" name="student_ids[]" value="{{ $student->id }}" aria-label="Select {{ $student->fullName() }}" /></td>
              @endif
              <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
              <td><strong>{{ $student->fullName() }}</strong></td>
              <td><a class="btn btn-ghost btn-sm" href="{{ route('school.students.edit', ['school' => $school->slug, 'studentId' => $student->id]) }}">Assign class</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>

      @if($canManage)
        <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
          <span class="muted" style="font-size:.85rem">Tick any number above, then choose an action.</span>
          <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button form="bulkUnassignedForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="withdrawn">Mark withdrawn</button>
            <button form="bulkUnassignedForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Move the selected students to Trashed? You can restore them from there afterwards.')">Delete selected</button>
          </div>
        </div>
        <script>
          (function(){
            var all = document.getElementById('unassigned-select-all');
            var boxes = document.querySelectorAll('.unassigned-row-check');
            all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
            document.getElementById('bulkUnassignedForm')?.addEventListener('submit', function(e){
              var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
              if (! anyChecked) { e.preventDefault(); alert('Tick at least one student first.'); }
            });
          })();
        </script>
      @endif
    </div>
  @endif
@endif
@endsection
