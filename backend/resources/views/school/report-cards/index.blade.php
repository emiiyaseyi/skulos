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
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.traits.index', ['school' => $school->slug]) }}">Behavioural traits</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.edit', ['school' => $school->slug]) }}">Report settings</a>
  </div>
</div>

@if($terms->isNotEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <form method="GET" action="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">
      <div class="field" style="margin:0;max-width:22rem">
        <label for="term_id">Session &amp; term</label>
        <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
          @if(! $selectedTerm)
            <option value="" selected disabled>Choose a term</option>
          @endif
          @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected($selectedTerm?->id === $term->id)>{{ $term->session?->name }} &middot; {{ $term->name }}</option>
          @endforeach
        </select>
      </div>
    </form>
  </div>
@else
  <div class="card" style="margin-bottom:1.1rem"><p class="muted">No session or term set up yet. Set one under Settings &gt; Sessions &amp; Terms first.</p></div>
@endif

{{--
  "My classes" shortcut (UX audit finding, 2026-08-10): a class/subject
  teacher used to see the exact same full school-wide browse list below
  as an admin, nothing surfacing their own class first — real daily
  friction, worse on mobile, scrolling through every section before
  reaching anything relevant to their actual job. $myClasses is null
  for an unscoped (admin-tier) viewer, who never sees this card; the
  full browse list below stays their only and correct view either way.
--}}
@if($myClasses !== null && $myClasses->isNotEmpty())
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.7rem">My classes</h3>
    <div class="stack" style="flex-direction:row;flex-wrap:wrap;gap:.6rem">
      @foreach($myClasses as $arm)
        <a class="btn btn-primary btn-sm" href="{{ route('school.report-cards.class-index', ['school' => $school->slug, 'classId' => $arm->schoolClass?->id, 'term_id' => $selectedTerm?->id]) }}">{{ $arm->label() }}</a>
        {{-- Direct shortcut to the actual data-entry screen (2026-09-11,
             user report: "class teachers are unable to enter or select
             the Behavioural Traits for each student in the class") --
             the entry, permission, and select-with-scale logic all
             already work correctly, but reaching it took three hops
             (Report Cards -> pick class -> Class teacher entry button),
             easy to give up on. --}}
        <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class.entry', ['school' => $school->slug, 'classId' => $arm->schoolClass?->id, 'term_id' => $selectedTerm?->id]) }}">{{ $arm->label() }} &middot; Traits &amp; remarks</a>
      @endforeach
    </div>
  </div>
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
          <thead><tr><th>Class</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              {{-- Same "stream sub-row" convention already used on Classes
                   & Subjects, Student Portal, Staff Portal and Parent
                   Portal (user directive 2026-07-22: "same under the
                   report card page too"). The destination page itself
                   (class-index) still covers every stream in the class,
                   now with its own Class/Stream column so the streams
                   stay distinguishable once there, rather than adding a
                   second, narrower route just for this listing. --}}
              @if($class->arms->count() > 1)
                <tr>
                  <td>
                    <strong>{{ $class->name }}</strong>
                    @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                  </td>
                  <td></td>
                </tr>
                @foreach($class->arms->sortBy(fn($arm) => $arm->orderKey()) as $arm)
                  <tr>
                    <td style="padding-left:1.6rem" class="muted">{{ $arm->label() }}</td>
                    <td><a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class-index', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $selectedTerm?->id]) }}">View report cards</a></td>
                  </tr>
                @endforeach
              @else
                <tr>
                  <td>
                    <strong>{{ $class->name }}</strong>
                    @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                  </td>
                  <td><a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class-index', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $selectedTerm?->id]) }}">View report cards</a></td>
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
@endsection
