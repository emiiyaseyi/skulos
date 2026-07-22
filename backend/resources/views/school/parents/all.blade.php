@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Parents'])
@section('title', 'Parents')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Parents</h1>
    <p class="subtitle">{{ number_format($total) }} guardian record(s) on file.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.parents.directory', ['school' => $school->slug]) }}">Browse by class</a>
</div>

@if(! $canSeeGuardianDetails)
  <div class="card" style="margin-bottom:1.1rem"><p class="muted">Guardian details are restricted for your role. Ask the school admin if you need access.</p></div>
@else

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0;flex:1;min-width:14rem">
      <label for="parent_q">Search</label>
      <input id="parent_q" type="search" name="q" value="{{ $search }}" placeholder="Search by name, email, phone, or student" />
    </div>
    <div class="field" style="margin:0;min-width:12rem">
      <label for="parent_class">Class</label>
      <select id="parent_class" name="class_arm_id" onchange="this.form.requestSubmit()">
        <option value="">All classes</option>
        @foreach($classArmsBySection as $sectionName => $arms)
          <optgroup label="{{ $sectionName }}">
            @foreach($arms as $arm)
              <option value="{{ $arm->id }}" @selected($classArmId === $arm->id)>{{ $arm->label() }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="parent_sort">Sort by</label>
      <select id="parent_sort" name="sort" onchange="this.form.requestSubmit()">
        <option value="name" @selected($sort === 'name')>Name</option>
        <option value="relationship" @selected($sort === 'relationship')>Relationship</option>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="parent_dir">Direction</label>
      <select id="parent_dir" name="dir" onchange="this.form.requestSubmit()">
        <option value="asc" @selected($dir === 'asc')>A to Z</option>
        <option value="desc" @selected($dir === 'desc')>Z to A</option>
      </select>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Apply</button>
  </form>
</div>

<div class="card">
  @if($guardians->isEmpty())
    <p class="muted">No guardians match your search.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Relationship</th><th>Contact</th><th>Student</th><th>Class</th><th></th></tr></thead>
      <tbody>
        @foreach($guardians as $guardian)
          <tr>
            <td><strong>{{ $guardian->name }}</strong></td>
            <td class="muted">{{ $guardian->relationship ?: 'Not set' }}</td>
            <td class="muted">{{ $guardian->email ?: $guardian->phone ?: 'Not set' }}</td>
            <td class="muted">{{ $guardian->student?->fullName() ?? 'Removed student' }}</td>
            <td class="muted">{{ $guardian->student?->currentClassArm?->label() ?? 'Not set' }}</td>
            <td>
              @if($guardian->student)
                <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $guardian->student_id]) }}">View</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($guardians->hasPages())
      <div style="margin-top:1rem">{{ $guardians->links() }}</div>
    @endif
  @endif
</div>
@endif
@endsection
