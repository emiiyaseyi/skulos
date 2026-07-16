@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Report Cards'])
@section('title', 'Report Cards')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Report Cards</h1>
    <p class="subtitle">Browse by section and class to generate, review, and release report cards.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if($canViewResults)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.index', ['school' => $school->slug]) }}">Results</a>
    @endif
    @if($canAssign)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.pending-review', ['school' => $school->slug]) }}">Pending review</a>
    @endif
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.edit', ['school' => $school->slug]) }}">Report settings</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" action="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">
    <div class="field" style="margin:0;max-width:22rem">
      <label for="term_id">Session &amp; term</label>
      <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
        @foreach($terms as $term)
          <option value="{{ $term->id }}" @selected($selectedTerm->id === $term->id)>{{ $term->session?->name }} &middot; {{ $term->name }}</option>
        @endforeach
      </select>
    </div>
  </form>
</div>

@if($sections->isEmpty())
  <div class="card"><p class="muted">No classes yet. Set up your class structure under Classes &amp; Subjects first.</p></div>
@else
  @foreach($sections as $section)
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.7rem">{{ $section->name }}</h3>
      @if($section->classes->isEmpty())
        <p class="muted">No classes in this section yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Class</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              <tr>
                <td>
                  <strong>{{ $class->name }}</strong>
                  @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                </td>
                <td><a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class-index', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $selectedTerm->id]) }}">View report cards</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </div>
  @endforeach
@endif
@endsection
