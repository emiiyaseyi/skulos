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
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Due</th><th>Title</th><th>Subject</th><th>Class</th>@if($canCreate)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($assignments as $assignment)
          <tr>
            <td class="muted" style="white-space:nowrap">{{ $assignment->due_on?->format('d M Y') ?? 'No due date' }}</td>
            <td>
              <strong>{{ $assignment->title }}</strong>
              @if($assignment->description)<div class="muted" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit($assignment->description, 90) }}</div>@endif
            </td>
            <td class="muted">{{ $assignment->subject?->name }}</td>
            <td class="muted">{{ $assignment->classArm?->label() }}</td>
            @if($canCreate)
              <td>
                <form method="POST" action="{{ route('school.assignments.destroy', ['school' => $school->slug, 'assignmentId' => $assignment->id]) }}" onsubmit="return confirm('Remove this assignment?')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                </form>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $assignments->links() }}</div>
  @endif
</div>
@endsection
