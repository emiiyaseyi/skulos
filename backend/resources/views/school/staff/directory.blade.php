@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Staff Portal'])
@section('title', 'Staff Portal')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Staff Portal</h1>
    <p class="subtitle">Browse by section and class to see who teaches where.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">Full staff list</a>
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
          <thead><tr><th>Class</th><th>Class Teacher(s)</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              <tr>
                <td>
                  <strong>{{ $class->name }}</strong>
                  @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                </td>
                <td class="muted">
                  @if($class->arms->isEmpty())
                    Not set
                  @else
                    {{ $class->arms->map(fn($arm) => $arm->classTeacher?->fullName() ?: 'Not set')->unique()->implode(', ') }}
                  @endif
                </td>
                <td><a class="btn btn-ghost btn-sm" href="{{ route('school.staff.directory-class', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
              </tr>
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
    <h3 style="margin-bottom:.7rem">Non-teaching staff</h3>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Position</th></tr></thead>
      <tbody>
        @foreach($unassigned as $staffMember)
          <tr class="row-link" onclick="window.location='{{ route('school.staff.show', ['school' => $school->slug, 'staffId' => $staffMember->id]) }}'" style="cursor:pointer">
            <td><strong>{{ $staffMember->fullName() }}</strong></td>
            <td class="muted">{{ $staffMember->position ?: 'Not set' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif
@endsection
