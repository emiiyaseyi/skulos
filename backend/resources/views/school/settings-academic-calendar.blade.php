@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Sessions & Terms'])
@section('title', 'Sessions & Terms')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Sessions &amp; Terms</h1>
    <p class="subtitle">One time setup: create academic sessions and terms, and set which one is current.</p>
  </div>
  @if($canManage)
    <a class="btn btn-ghost btn-sm" href="{{ route('school.academics.promotion-settings.edit', ['school' => $school->slug]) }}">Promotion requirement</a>
  @endif
</div>

@if($suggestedTerm)
  <div class="card" style="margin-bottom:1.1rem;border-color:var(--accent)">
    <div class="row-between" style="flex-wrap:wrap;gap:.6rem">
      <p style="margin:0">Today's date falls within <strong>{{ $suggestedTerm->name }}</strong> ({{ $suggestedTerm->session->name }}). Set it as the current term?</p>
      @if($canManage)
        <form method="POST" action="{{ route('school.academics.terms.make-current', ['school' => $school->slug, 'termId' => $suggestedTerm->id]) }}">
          @csrf
          <button class="btn btn-primary btn-sm" type="submit">Set as current</button>
        </form>
      @endif
    </div>
  </div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.7rem">Academic sessions &amp; terms</h3>
  @if($sessions->isEmpty())
    <p class="muted">No sessions yet. Create one below, e.g. enter 2025 for the 2025/2026 session, and its three terms are added automatically. Alternatively, fill in each term's start and end date once created, and this page will suggest the current one automatically based on today's date.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Session</th><th>Terms</th><th>Dates</th><th></th></tr></thead>
      <tbody>
        @foreach($sessions as $session)
          <tr>
            <td>
              <strong>{{ $session->name }}</strong>
              @if($session->is_current)<span class="badge badge-ok" style="margin-left:.4rem">Current</span>@endif
            </td>
            <td class="muted">
              @foreach($session->terms as $term)
                <div style="margin-bottom:.4rem">
                  {{ $term->name }}
                  @if($term->is_current)
                    <span class="badge badge-ok">Current</span>
                    @if($canManage)
                      <form method="POST" action="{{ route('school.academics.end-term', ['school' => $school->slug]) }}" style="display:inline" onsubmit="return confirm('End {{ $term->name }} now? This moves to the next term, and if this is the last term of the session, promotes qualifying students to their next class. This cannot be undone from here.')">
                        @csrf
                        <button class="btn btn-primary btn-sm" type="submit">End this term</button>
                      </form>
                    @endif
                  @endif
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
              @foreach($session->terms as $term)
                <div class="badge" style="margin-bottom:.2rem">{{ $term->name }}</div>
                @if($canManage)
                  <form method="POST" action="{{ route('school.settings.calendar.terms.dates', ['school' => $school->slug, 'termId' => $term->id]) }}" style="display:flex;gap:.3rem;align-items:center;margin-bottom:.4rem;flex-wrap:wrap">
                    @csrf
                    <input type="date" name="starts_on" value="{{ $term->starts_on?->toDateString() }}" style="padding:.3rem .4rem;font-size:.8rem" />
                    <span class="muted" style="font-size:.8rem">to</span>
                    <input type="date" name="ends_on" value="{{ $term->ends_on?->toDateString() }}" style="padding:.3rem .4rem;font-size:.8rem" />
                    <button class="btn btn-ghost btn-sm" type="submit">Save</button>
                  </form>
                @else
                  <div class="muted" style="font-size:.8rem;margin-bottom:.4rem">{{ $term->starts_on?->toDateString() ?? '?' }} to {{ $term->ends_on?->toDateString() ?? '?' }}</div>
                @endif
              @endforeach
            </td>
            <td style="white-space:nowrap">
              @if($canManage && ! $session->is_current)
                <form method="POST" action="{{ route('school.academics.sessions.make-current', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Make current</button>
                </form>
                <form method="POST" action="{{ route('school.academics.sessions.archive', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline" onsubmit="return confirm('Archive {{ $session->name }}? It stays in your records but is hidden from this list until restored.')">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Archive</button>
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

@if($archivedSessions->isNotEmpty())
<div class="card">
  <h3 style="margin-bottom:.7rem">Archived sessions</h3>
  <p class="muted" style="margin-bottom:.7rem">Kept in your records, hidden from the list above. Restore one to bring it back.</p>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Session</th><th>Terms</th><th></th></tr></thead>
    <tbody>
      @foreach($archivedSessions as $session)
        <tr>
          <td><strong>{{ $session->name }}</strong></td>
          <td class="muted">{{ $session->terms->pluck('name')->implode(', ') }}</td>
          <td>
            @if($canManage)
              <form method="POST" action="{{ route('school.academics.sessions.unarchive', ['school' => $school->slug, 'sessionId' => $session->id]) }}">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Restore</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif
@endsection
