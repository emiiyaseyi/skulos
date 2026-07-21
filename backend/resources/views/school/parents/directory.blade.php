@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Parent Portal'])
@section('title', 'Parent Portal')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Parent Portal</h1>
    <p class="subtitle">Browse by section and class to reach a student's guardians.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.parents.all', ['school' => $school->slug]) }}">Full parent list</a>
</div>

@if(! $canSeeGuardianDetails)
  <div class="card" style="margin-bottom:1.1rem"><p class="muted">Guardian details are restricted for your role. Ask the school admin if you need access.</p></div>
@endif

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
          <thead><tr><th>Class</th><th>Students</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              @php($classTotal = $class->arms->sum(fn($arm) => $counts[$arm->id] ?? 0))
              <tr>
                <td>
                  <strong>{{ $class->name }}</strong>
                  @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                </td>
                <td class="muted">{{ $classTotal }}</td>
                <td><a class="btn btn-ghost btn-sm" href="{{ route('school.parents.directory-class', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
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
