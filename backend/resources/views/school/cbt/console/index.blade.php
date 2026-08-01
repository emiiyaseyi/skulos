@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admin Backend'])
@section('title', 'Admin Backend')

@section('content')
@php
  $url = fn (string $t) => route('school.cbt.console.index', ['school' => $school->slug, 'tab' => $t]);
  $riskColor = fn (?string $level) => match ($level) {
    'high' => 'var(--bad,#dc3545)', 'medium' => 'var(--warn,#e0a800)', default => 'var(--ok,#28a745)',
  };
@endphp

<div class="page-head">
  <div>
    <h1 class="title">Admin Backend</h1>
    <p class="subtitle">Every assessment in the school, in one place.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

{{-- Tab strip, with live badge counts --}}
<div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:1.1rem;border-bottom:1px solid var(--line);padding-bottom:.6rem">
  @foreach($tabs as $key => $label)
    @php($badge = $counts[$key] ?? null)
    <a href="{{ $url($key) }}"
       class="btn btn-sm {{ $tab === $key ? 'btn-primary' : 'btn-ghost' }}">
      {{ $label }}@if($badge) <span style="opacity:.75">({{ $badge }})</span>@endif
    </a>
  @endforeach
</div>

@if($tab === 'overview')
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.8rem;margin-bottom:1.1rem">
    @foreach($stats as $label => $value)
      <div class="card" style="text-align:center">
        <div style="font-size:1.6rem;font-weight:800">{{ $value }}</div>
        <div class="muted" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em">{{ $label }}</div>
      </div>
    @endforeach
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">Highest risk right now</h3>
    @if($topRisk->isEmpty())
      <p class="muted">Nothing in progress.</p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Taker</th><th>Assessment</th><th>Risk</th><th>Started</th><th></th></tr></thead>
        <tbody>
          @foreach($topRisk as $session)
            <tr>
              <td data-label="Taker">{{ $session->takeable?->name ?? 'Unknown' }}</td>
              <td class="muted" data-label="Assessment">{{ $session->assessment?->title }}</td>
              <td data-label="Risk"><span class="badge" style="background:{{ $riskColor($session->risk_level) }};color:#fff">{{ ucfirst($session->risk_level) }} &middot; {{ $session->risk_score }}</span></td>
              <td class="muted" data-label="Started">{{ $session->started_at?->format('H:i') }}</td>
              <td>
                <form method="POST" action="{{ route('school.cbt.console.suspend', ['school' => $school->slug, 'sessionId' => $session->id]) }}" onsubmit="return confirm('Suspend this attempt?')">
                  @csrf
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Suspend</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>

@elseif($tab === 'assessments')
  <div class="card">
    @if($assessments->isEmpty())
      <p class="muted">No assessments yet.</p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Title</th><th>Subject</th><th>Questions</th><th>Attempts</th><th>State</th><th>Results</th><th></th></tr></thead>
        <tbody>
          @foreach($assessments as $a)
            <tr>
              <td data-label="Title"><strong>{{ $a->title }}</strong></td>
              <td class="muted" data-label="Subject">{{ $a->subject?->name ?? 'Not set' }}</td>
              <td class="muted" data-label="Questions">{{ $a->assessment_questions_count }}</td>
              <td class="muted" data-label="Attempts">{{ $a->sessions_count }}</td>
              <td data-label="State">
                @if($a->paused_at)<span class="badge badge-warn">Paused</span>
                @elseif($a->is_active)<span class="badge badge-ok">Active</span>
                @else<span class="badge badge-warn">Inactive</span>@endif
              </td>
              <td data-label="Results">
                <span class="badge {{ $a->results_released ? 'badge-ok' : 'badge-warn' }}">{{ $a->results_released ? 'Released' : 'Held' }}</span>
              </td>
              <td style="white-space:nowrap">
                <a class="btn btn-ghost btn-sm" href="{{ route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $a->id]) }}">Edit</a>
                <form method="POST" action="{{ route('school.cbt.console.pause', ['school' => $school->slug, 'assessmentId' => $a->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $a->paused_at ? 'Resume' : 'Pause all' }}</button>
                </form>
                <form method="POST" action="{{ route('school.cbt.console.release-results', ['school' => $school->slug, 'assessmentId' => $a->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $a->results_released ? 'Hold results' : 'Release results' }}</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <div style="margin-top:1rem">{{ $assessments->links() }}</div>
    @endif
  </div>

@elseif(in_array($tab, ['inprogress', 'highrisk', 'suspended'], true))
  <div class="card">
    @if($sessions->isEmpty())
      <p class="muted">
        @if($tab === 'inprogress') No attempts in progress.
        @elseif($tab === 'highrisk') No high risk attempts.
        @else No suspended attempts.
        @endif
      </p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr>
            <th>Taker</th><th>Assessment</th><th>Risk</th><th>Signals</th>
            <th>{{ $tab === 'suspended' ? 'Suspended' : 'Started' }}</th><th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($sessions as $session)
            <tr>
              <td data-label="Taker">{{ $session->takeable?->name ?? 'Unknown' }}</td>
              <td class="muted" data-label="Assessment">{{ $session->assessment?->title }}</td>
              <td data-label="Risk"><span class="badge" style="background:{{ $riskColor($session->risk_level) }};color:#fff">{{ ucfirst($session->risk_level) }} &middot; {{ $session->risk_score }}</span></td>
              <td class="muted" data-label="Signals" style="font-size:.8rem">
                Tabs {{ $session->tab_switches }} &middot; FS {{ $session->fullscreen_exits }} &middot; Face {{ $session->face_missing_count }}
              </td>
              <td class="muted" data-label="When">
                {{ $tab === 'suspended' ? $session->suspended_at?->format('d M H:i') : $session->started_at?->format('d M H:i') }}
              </td>
              <td style="white-space:nowrap">
                @if($session->status === \App\Models\Tenant\CbtSession::STATUS_SUSPENDED)
                  <form method="POST" action="{{ route('school.cbt.console.reinstate', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline">
                    @csrf
                    <button class="btn btn-primary btn-sm" type="submit">Reinstate</button>
                  </form>
                @elseif($session->status === \App\Models\Tenant\CbtSession::STATUS_IN_PROGRESS)
                  <form method="POST" action="{{ route('school.cbt.console.force-submit', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline" onsubmit="return confirm('Submit this attempt on the taker\'s behalf?')">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">Force submit</button>
                  </form>
                  <form method="POST" action="{{ route('school.cbt.console.suspend', ['school' => $school->slug, 'sessionId' => $session->id]) }}" style="display:inline" onsubmit="return confirm('Suspend this attempt?')">
                    @csrf
                    <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Suspend</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <div style="margin-top:1rem">{{ $sessions->links() }}</div>
    @endif
  </div>

@elseif($tab === 'media')
  <div class="card">
    @if($snapshots->isEmpty())
      <p class="muted">No captured media yet.</p>
    @else
      <form method="POST" action="{{ route('school.cbt.console.media.delete', ['school' => $school->slug]) }}" onsubmit="return confirm('Delete the selected media? The files are removed from disk too.')">
        @csrf
        <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem">
          <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete selected</button>
          <span class="muted" style="font-size:.85rem">Tick any captures below first.</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(8rem,1fr));gap:.6rem">
          @foreach($snapshots as $snapshot)
            <label style="border:1px solid var(--line);border-radius:8px;padding:.4rem;display:block">
              <img src="{{ route('school.cbt.console.media.image', ['school' => $school->slug, 'snapshotId' => $snapshot->id]) }}"
                   alt="Capture" style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:4px" loading="lazy" />
              <div class="muted" style="font-size:.72rem;margin-top:.3rem">
                {{ $snapshot->session?->takeable?->name ?? 'Unknown' }}<br>{{ $snapshot->captured_at?->format('d M H:i') }}
              </div>
              <input type="checkbox" name="snapshot_ids[]" value="{{ $snapshot->id }}" />
            </label>
          @endforeach
        </div>
      </form>
      <div style="margin-top:1rem">{{ $snapshots->links() }}</div>
    @endif
  </div>

@elseif($tab === 'questions')
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem">
      <h3 style="margin:0">Question bank by category</h3>
      <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.questions.index', ['school' => $school->slug]) }}">Open question bank</a>
    </div>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Category</th><th>Questions</th></tr></thead>
      <tbody>
        @foreach($categories as $category)
          <tr>
            <td data-label="Category">{{ $category->name }}</td>
            <td class="muted" data-label="Questions">{{ $category->questions_count }}</td>
          </tr>
        @endforeach
        <tr>
          <td class="muted" data-label="Category">Uncategorised</td>
          <td class="muted" data-label="Questions">{{ $uncategorised }}</td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>

@elseif($tab === 'branding')
  <div class="card">
    <p class="muted" style="margin-bottom:.8rem">Give an assessment its own name and colour on the take screen, for example an entrance exam that should not carry the school's usual chrome. Leave both blank to use the school's own branding.</p>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Assessment</th><th>Display name</th><th>Colour</th><th></th></tr></thead>
      <tbody>
        @foreach($assessments as $a)
          <tr>
            <form method="POST" action="{{ route('school.cbt.console.branding', ['school' => $school->slug, 'assessmentId' => $a->id]) }}" id="brand{{ $a->id }}">@csrf</form>
            <td data-label="Assessment"><strong>{{ $a->title }}</strong></td>
            <td data-label="Display name"><input form="brand{{ $a->id }}" name="brand_name" type="text" value="{{ $a->brand_name }}" placeholder="{{ $a->title }}" /></td>
            <td data-label="Colour"><input form="brand{{ $a->id }}" name="brand_color" type="color" value="{{ $a->brand_color ?: ($accent ?: '#0f6cbf') }}" /></td>
            <td><button form="brand{{ $a->id }}" class="btn btn-primary btn-sm" type="submit">Save</button></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $assessments->links() }}</div>
  </div>

@else
  {{-- Proctors and Graders: honest not-built-yet state rather than a
       fake empty list, since their tables exist but the assignment
       screens are still being built. --}}
  <div class="card">
    <h3 style="margin-bottom:.5rem">{{ $tabs[$tab] }}</h3>
    <p class="muted">This tab is not built yet. The underlying data model is in place; the assignment screen is next.</p>
  </div>
@endif
@endsection
