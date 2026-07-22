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
    <details class="card" open style="margin-bottom:1.1rem">
      <summary style="cursor:pointer;font-weight:800;font-size:1rem;margin-bottom:.7rem">{{ $section->name }}</summary>
      @if($section->classes->isEmpty())
        <p class="muted">No classes in this section yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Class</th><th>Class Teacher(s)</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              {{-- A class actually split into streams carries no data of
                   its own on this row (same convention as Classes &
                   Subjects and the Student Portal browse screen, user
                   directive 2026-07-22: "the class stream should be
                   separated here under staff portal and parent portal
                   too"): it is a heading only, each real stream
                   underneath carries its own class teacher(s). Triggered
                   on arm count, not on whether an arm happens to be
                   named, so a legacy unnamed arm still surfaces as its
                   own row. --}}
              @if($class->arms->count() > 1)
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
                    <td class="muted">{{ $arm->classTeacher?->fullName() ?: 'Not set' }}</td>
                    <td><a class="btn btn-ghost btn-sm" href="{{ route('school.staff.directory-class', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
                  </tr>
                @endforeach
              @else
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
              @endif
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    </details>
  @endforeach
@endif

@if($unassigned->isNotEmpty())
  <details class="card" open>
    <summary style="cursor:pointer;font-weight:800;font-size:1rem;margin-bottom:.7rem">Non-teaching staff</summary>
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
  </details>
@endif
@endsection
