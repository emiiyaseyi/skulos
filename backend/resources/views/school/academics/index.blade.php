@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Classes & Subjects'])
@section('title', 'Classes & Subjects')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Classes &amp; Subjects</h1>
    <p class="subtitle">Sections, classes, and which subjects each class offers.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="row-between" style="margin-bottom:.7rem">
    <h3>Academic sessions &amp; terms</h3>
  </div>
  @if($sessions->isEmpty())
    <p class="muted">No sessions yet. Create one below, e.g. enter 2025 for the 2025/2026 session, and its three terms are added automatically.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Session</th><th>Terms</th><th></th></tr></thead>
      <tbody>
        @foreach($sessions as $session)
          <tr>
            <td>
              <strong>{{ $session->name }}</strong>
              @if($session->is_current)<span class="badge badge-ok" style="margin-left:.4rem">Current</span>@endif
            </td>
            <td class="muted">
              @foreach($session->terms as $term)
                <div style="margin-bottom:.2rem">
                  {{ $term->name }}
                  @if($term->is_current)<span class="badge badge-ok">Current</span>@endif
                  @if($canManage && ! $term->is_current)
                    <form method="POST" action="{{ route('school.academics.terms.make-current', ['school' => $school->slug, 'termId' => $term->id]) }}" style="display:inline">
                      @csrf
                      <button class="btn btn-ghost btn-sm" type="submit">Make current</button>
                    </form>
                  @endif
                </div>
              @endforeach
            </td>
            <td>
              @if($canManage && ! $session->is_current)
                <form method="POST" action="{{ route('school.academics.sessions.make-current', ['school' => $school->slug, 'sessionId' => $session->id]) }}">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Make current</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

  @if($canManage)
    <div class="grid cols-2" style="margin-top:1rem">
      <form method="POST" action="{{ route('school.academics.sessions.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:flex-end">
        @csrf
        <div class="field" style="margin-bottom:0;flex:1">
          <label for="start_year">Create a session (start year)</label>
          <input id="start_year" name="start_year" type="number" min="2000" max="2100" placeholder="e.g. 2025" required />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Create session &amp; terms</button>
      </form>
      @if($sessions->isNotEmpty())
        <form method="POST" action="{{ route('school.academics.terms.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap">
          @csrf
          <div class="field" style="margin-bottom:0">
            <label for="t_session">Add a custom term to</label>
            <select id="t_session" name="academic_session_id" required>
              @foreach($sessions as $session)
                <option value="{{ $session->id }}">{{ $session->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="field" style="margin-bottom:0;flex:1">
            <label for="t_name">Term name</label>
            <input id="t_name" name="name" placeholder="e.g. Summer Term 2025/2026" required />
          </div>
          <button class="btn btn-ghost btn-sm" type="submit">Add term</button>
        </form>
      @endif
    </div>
  @endif
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.4rem">Load a default class structure</h3>
  <p class="muted" style="margin-bottom:.8rem">Tick any tier to create its section, classes, and default subjects together, in one click. You can edit, add, or remove anything afterwards.</p>
  <form method="POST" action="{{ route('school.academics.class-structure.apply', ['school' => $school->slug]) }}">
    @csrf
    <div class="flagbox" style="margin-bottom:.8rem;flex-wrap:wrap">
      @foreach($classCatalogs as $key => $tier)
        <label><input type="checkbox" name="tiers[]" value="{{ $key }}" style="width:auto" /> {{ config('skulos.default_subjects.'.$key.'.label', ucfirst($key)) }}</label>
      @endforeach
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Load selected tiers</button>
  </form>
</div>
@endif

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
          <thead><tr><th>Class</th><th>Streams</th><th>Subjects offered</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)
              <tr>
                <td><strong>{{ $class->name }}</strong></td>
                <td class="muted">
                  {{ $class->arms->map(fn($ca) => $ca->arm?->name ?: 'Single stream')->join(', ') }}
                  @if($canManage)
                    <form method="POST" action="{{ route('school.academics.arms.store', ['school' => $school->slug]) }}" style="display:inline-flex;gap:.3rem;margin-left:.5rem">
                      @csrf
                      <input type="hidden" name="school_class_id" value="{{ $class->id }}" />
                      <input name="name" placeholder="e.g. B" style="width:5rem;padding:.3rem .5rem" />
                      <button class="btn btn-ghost btn-sm" type="submit">Add stream</button>
                    </form>
                  @endif
                </td>
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
