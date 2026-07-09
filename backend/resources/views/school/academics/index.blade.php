@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Classes & Subjects'])
@section('title', 'Classes & Subjects')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Classes &amp; Subjects</h1>
    <p class="subtitle">Sections, classes, and which subjects each class offers.</p>
  </div>
</div>

@if($sections->isEmpty())
  <div class="card"><p class="muted">No sections yet. Add one below to get started, e.g. "Nursery", "Primary", or "Secondary".</p></div>
@else
  @foreach($sections as $section)
    <div class="card" style="margin-bottom:1.1rem">
      <div class="row-between" style="margin-bottom:.7rem">
        <h3>{{ $section->name }}</h3>
      </div>
      @if($section->classes->isEmpty())
        <p class="muted">No classes in this section yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Class</th><th>Subjects offered</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              <tr>
                <td><strong>{{ $class->name }}</strong></td>
                <td class="muted">{{ $class->subjects_count }}</td>
                <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.subjects', ['school' => $school->slug, 'classId' => $class->id]) }}">Manage subjects</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </div>
  @endforeach
@endif

@if($canManage)
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Add a section</h3>
    <form method="POST" action="{{ route('school.academics.sections.store', ['school' => $school->slug]) }}">
      @csrf
      <div class="field">
        <label for="s_name">Section name</label>
        <input id="s_name" name="name" placeholder="e.g. Primary" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add section</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Add a class</h3>
    @if($sections->isEmpty())
      <p class="muted">Add a section first.</p>
    @else
      <form method="POST" action="{{ route('school.academics.classes.store', ['school' => $school->slug]) }}">
        @csrf
        <div class="field">
          <label for="c_section">Section</label>
          <select id="c_section" name="section_id" required>
            @foreach($sections as $section)
              <option value="{{ $section->id }}">{{ $section->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="c_name">Class name</label>
          <input id="c_name" name="name" placeholder="e.g. JSS 1 or Basic 1" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add class</button>
      </form>
    @endif
  </div>
</div>
@endif
@endsection
