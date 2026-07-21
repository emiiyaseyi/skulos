@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Classes & Subjects'])
@section('title', 'Classes & Subjects')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Classes &amp; Subjects</h1>
    <p class="subtitle">Sections, classes, and which subjects each class offers.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.settings.calendar.edit', ['school' => $school->slug]) }}">Sessions &amp; terms are in Settings</a>
</div>

@if($canManage)
<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.4rem">Load a default class structure</h3>
  <p class="muted" style="margin-bottom:.8rem">Tick any tier to create its section, classes, and default subjects together, in one click. You can edit, add, or remove anything afterwards.</p>
  <form method="POST" action="{{ route('school.academics.class-structure.apply', ['school' => $school->slug]) }}">
    @csrf
    <div class="flagbox" style="margin-bottom:.8rem;flex-wrap:wrap">
      @foreach($classCatalogs as $key => $tier)
        <label><input type="checkbox" name="tiers[]" value="{{ $key }}" /> {{ config('skulos.default_subjects.'.$key.'.label', ucfirst($key)) }}</label>
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
      <div class="row-between" style="margin-bottom:.7rem;flex-wrap:wrap;gap:.6rem">
        <h3>{{ $section->name }}</h3>
        @if($canManage)
          <form method="POST" action="{{ route('school.academics.sections.update', ['school' => $school->slug, 'sectionId' => $section->id]) }}" style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <div class="field" style="margin-bottom:0">
              <label for="sec_name_{{ $section->id }}">Section name</label>
              <input id="sec_name_{{ $section->id }}" name="name" value="{{ $section->name }}" required />
            </div>
            <div class="field" style="margin-bottom:0;width:5.5rem">
              <label for="sec_seq_{{ $section->id }}">Position</label>
              <input id="sec_seq_{{ $section->id }}" name="sequence" type="number" min="1" value="{{ $section->sequence }}" />
            </div>
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </form>
        @endif
      </div>
      @if($section->classes->isEmpty())
        <p class="muted">No classes in this section yet.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr><th>Class</th><th>Streams</th><th>Subjects offered</th><th></th></tr></thead>
          <tbody>
            @foreach($section->classes as $class)

              {{-- Triggered on arm COUNT, not on whether an arm happens to
                   be named — see the matching note on the Students index,
                   the exact same convention applies here. Subjects offered
                   stays on the class row either way: subjects apply to the
                   whole class, not per stream, that part of the data model
                   is unaffected by streaming. --}}
              @if($class->arms->count() > 1)
                <tr>
                  <td>
                    <a href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}"><strong>{{ $class->name }}</strong></a>
                    @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                  </td>
                  <td class="muted">
                    @if($canManage)
                      <form method="POST" action="{{ route('school.academics.arms.store', ['school' => $school->slug]) }}" style="display:inline-flex;gap:.3rem">
                        @csrf
                        <input type="hidden" name="school_class_id" value="{{ $class->id }}" />
                        <input name="name" placeholder="e.g. B" style="width:5rem;padding:.3rem .5rem" />
                        <button class="btn btn-ghost btn-sm" type="submit">Add stream</button>
                      </form>
                    @endif
                  </td>
                  <td class="muted">{{ $class->subjects_count }}</td>
                  <td></td>
                </tr>
                {{-- View belongs on each stream, not the class heading row
                     above (user directive 2026-07-21, after seeing it on
                     the parent row only: "the View button should be for
                     each class stream and not the main class name") — a
                     streamed class's own row carries no other action
                     either, per the same "no data on the parent" rule. --}}
                @foreach($class->arms->sortBy(fn($arm) => $arm->orderKey()) as $arm)
                  <tr>
                    <td style="padding-left:1.6rem" class="muted">{{ $arm->label() }}</td>
                    <td></td>
                    <td></td>
                    <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
                  </tr>
                @endforeach
              @else
                <tr>
                  <td>
                    <a href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}"><strong>{{ $class->name }}</strong></a>
                    @unless($class->is_active)<span class="badge badge-warn" style="margin-left:.4rem">Retired</span>@endunless
                  </td>
                  <td class="muted">
                    {{ $class->arms->first()?->arm?->name ?: 'Single stream' }}
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
                  <td><a class="btn btn-ghost btn-sm" href="{{ route('school.academics.classes.show', ['school' => $school->slug, 'classId' => $class->id]) }}">View</a></td>
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
      <div class="field">
        <label for="s_sequence">Position (optional)</label>
        <input id="s_sequence" name="sequence" type="number" min="1" placeholder="Added at the end by default" />
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
