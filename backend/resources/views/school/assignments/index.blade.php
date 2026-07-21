@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assignments'])
@section('title', 'Assignments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assignments</h1>
    <p class="subtitle">Homework and coursework for {{ $school->name }}.</p>
  </div>
  @if($canCreate)
    <a class="btn btn-primary btn-sm" href="{{ route('school.assignments.create', ['school' => $school->slug]) }}">Post assignment</a>
  @endif
</div>

@if($unrestricted)
  @if($summary)
    <div class="grid" style="grid-template-columns:repeat(3, 1fr);gap:1rem;margin-bottom:1.25rem">
      <div class="card stat" style="padding:1rem"><b>{{ (int) $summary->total }}</b><span>Active assignments</span></div>
      <div class="card stat" style="padding:1rem"><b>{{ (int) $summary->upcoming }}</b><span>Upcoming</span></div>
      <div class="card stat" style="padding:1rem"><b>{{ (int) $summary->overdue }}</b><span>Overdue</span></div>
    </div>
  @endif

  <div class="card" style="margin-bottom:1.25rem">
    <form method="GET" action="{{ route('school.assignments.index', ['school' => $school->slug]) }}" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
      <div class="field" style="margin:0">
        <label for="section_id">Section</label>
        <select id="section_id" name="section_id" onchange="this.form.requestSubmit()">
          <option value="">Every section</option>
          @foreach($sections as $section)
            <option value="{{ $section->id }}" @selected($selectedSectionId === $section->id)>{{ $section->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" style="margin:0">
        <label for="class_arm_id">Class</label>
        <select id="class_arm_id" name="class_arm_id" onchange="this.form.requestSubmit()">
          <option value="">Every class</option>
          @foreach($sections as $section)
            @foreach($section->classes as $class)
              @foreach($class->arms as $arm)
                <option value="{{ $arm->id }}" @selected($selectedClassArmId === $arm->id)>{{ $arm->label() }}</option>
              @endforeach
            @endforeach
          @endforeach
        </select>
      </div>
    </form>
  </div>
@endif

<div class="card">
  @if($assignments->isEmpty())
    <p class="muted">No assignments yet.</p>
  @else
    @if($canCreate)
      <form id="bulkAssignmentsForm" method="POST" action="{{ route('school.assignments.bulk-destroy', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canCreate)<th style="width:2.2rem"><input type="checkbox" id="assignment-select-all" aria-label="Select all assignments" /></th>@endif
        <th>Due</th><th>Title</th><th>Subject</th><th>Class</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($assignments as $assignment)
          <tr>
            @if($canCreate)<td><input type="checkbox" form="bulkAssignmentsForm" class="assignment-row-check" name="assignment_ids[]" value="{{ $assignment->id }}" aria-label="Select {{ $assignment->title }}" /></td>@endif
            <td class="muted" style="white-space:nowrap">{{ $assignment->due_on?->format('d M Y') ?? 'No due date' }}</td>
            <td>
              <strong>{{ $assignment->title }}</strong>
              @if($assignment->description)<div class="muted" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit($assignment->description, 90) }}</div>@endif
            </td>
            <td class="muted">{{ $assignment->subject?->name }}</td>
            <td class="muted">{{ $assignment->classArm?->label() }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.assignments.show', ['school' => $school->slug, 'assignmentId' => $assignment->id]) }}">View</a>
              @if($canCreate)
                <form method="POST" action="{{ route('school.assignments.destroy', ['school' => $school->slug, 'assignmentId' => $assignment->id]) }}" style="display:inline" onsubmit="return confirm('Remove this assignment?')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canCreate)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of assignments above, then remove them all at once.</span>
        <button form="bulkAssignmentsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected assignments?')">Remove selected</button>
      </div>
      <script>
        (function(){
          var all = document.getElementById('assignment-select-all');
          var boxes = document.querySelectorAll('.assignment-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkAssignmentsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one assignment first.'); }
          });
        })();
      </script>
    @endif
    <div style="margin-top:1rem">{{ $assignments->links() }}</div>
  @endif
</div>
@endsection
