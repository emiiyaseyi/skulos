@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $assessment->title])
@section('title', $assessment->title)

@section('content')
@php
  $url = fn (string $t) => route('school.cbt.hub.index', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'tab' => $t]);
  $act = fn (string $name, $sessionId = null) => route('school.cbt.hub.'.$name, array_filter([
    'school' => $school->slug, 'assessmentId' => $assessment->id, 'sessionId' => $sessionId,
  ]));
  $riskColor = fn (?string $level) => match ($level) {
    'high' => '#dc3545', 'medium' => '#e0a800', default => '#198754',
  };
  $statusBadge = fn (string $status) => match ($status) {
    'in_progress' => ['In progress', 'badge-warn'],
    'suspended' => ['Suspended', 'badge-warn'],
    'graded' => ['Graded', 'badge-ok'],
    default => ['Submitted', 'badge-ok'],
  };
  $badgeTones = ['live' => '#198754', 'events' => '#6f42c1'];
@endphp

@include('school.cbt._banner', [
  'icon' => '&#128220;',
  'title' => $assessment->title,
  'subtitle' => trim(collect([
    $assessment->subject?->name,
    $assessment->term?->name,
    $assessment->duration_minutes.' minutes',
    $assessment->securityProfile?->name.' security',
  ])->filter()->implode(' · ')),
  'links' => [
    ['label' => 'Edit assessment', 'url' => route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id]), 'tone' => 'primary'],
    ['label' => 'Grading', 'url' => route('school.cbt.grader.queue', ['school' => $school->slug, 'assessmentId' => $assessment->id]), 'tone' => 'teal'],
    ['label' => 'Live Monitor', 'url' => route('school.cbt.live-monitor.index', ['school' => $school->slug]), 'tone' => 'red'],
    ['label' => 'Admin Backend', 'url' => route('school.cbt.console.index', ['school' => $school->slug]), 'tone' => 'grey'],
  ],
])

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if($errors->any())
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ $errors->first() }}</div>
@endif

@if($assessment->paused_at)
  <div class="flash flash-bad" style="margin-bottom:1.25rem">
    This assessment is paused for every taker. Their timers are frozen until it is resumed.
  </div>
@endif

<div class="cbt-tabs">
  @foreach($tabs as $key => $label)
    <a href="{{ $url($key) }}" class="cbt-tab {{ $tab === $key ? 'cbt-tab-active' : '' }}">
      {{ $label }}
      @if(! empty($counts[$key]))
        <span class="cbt-tab-badge" style="background:{{ $badgeTones[$key] ?? 'var(--mut-dim,#6c757d)' }}">{{ $counts[$key] }}</span>
      @endif
    </a>
  @endforeach
</div>

@if($tab === 'overview')
  @php
    $tones = ['Attempts' => '#495057', 'In progress' => '#fd7e14', 'Submitted' => '#0d6efd',
              'Graded' => '#198754', 'Suspended' => '#dc3545', 'High risk' => '#6f42c1'];
  @endphp
  <div class="cbt-stats">
    @foreach($stats as $label => $value)
      <div class="cbt-stat" style="background:{{ $tones[$label] ?? 'var(--accent)' }}">
        <b>{{ $value }}</b><span>{{ $label }}</span>
      </div>
    @endforeach
  </div>

  <div class="card">
    <h3 style="margin-bottom:.8rem">At a glance</h3>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <tbody>
        <tr><td data-label="Eligible takers">Eligible takers</td><td>{{ $eligibleCount ?: 'Everyone in the audience' }}</td></tr>
        <tr><td data-label="Average score">Average score</td><td>{{ $averageScore ? round($averageScore, 2) : 'No graded attempts yet' }}</td></tr>
        <tr><td data-label="Results">Results</td><td>{{ $assessment->results_released ? 'Released to takers' : 'Held back' }}</td></tr>
        <tr><td data-label="Status">Status</td><td>{{ $assessment->paused_at ? 'Paused' : ($assessment->is_active ? 'Active' : 'Inactive') }}</td></tr>
      </tbody>
    </table>
    </div>
  </div>

@elseif($tab === 'attempts' || $tab === 'live')
  @php($rows = $tab === 'attempts' ? $sessions : $sessions)
  <div class="card">
    @if($rows->isEmpty())
      <p class="muted">{{ $tab === 'live' ? 'Nothing live right now.' : 'No attempts yet.' }}</p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Taker</th><th>Status</th><th>Risk</th><th>Score</th><th>Started</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($rows as $session)
            @php([$statusLabel, $statusClass] = $statusBadge($session->status))
            <tr>
              <td data-label="Taker">
                <div class="cbt-person"><strong>{{ $session->takeable?->name ?? 'Unknown' }}</strong></div>
                @if($session->break_requested)
                  <span class="badge badge-warn">Break requested{{ $session->break_reason ? ': '.$session->break_reason : '' }}</span>
                @endif
              </td>
              <td data-label="Status"><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
              <td data-label="Risk">
                <span class="badge" style="background:{{ $riskColor($session->risk_level) }};color:#fff">{{ $session->risk_score }}</span>
              </td>
              <td class="muted" data-label="Score">{{ $session->total_score !== null ? $session->total_score.' / '.$session->max_score : '—' }}</td>
              <td class="muted" data-label="Started">{{ $session->started_at?->format('d M H:i') }}</td>
              <td style="white-space:nowrap">
                @if($session->status === 'in_progress')
                  @if($session->break_requested)
                    <form method="POST" action="{{ $act('approve-break', $session->id) }}" style="display:inline-flex;gap:.3rem;align-items:center">
                      @csrf
                      <input name="minutes" type="number" value="5" min="1" max="60" style="width:3.6rem" />
                      <button class="btn btn-primary btn-sm" type="submit">Approve break</button>
                    </form>
                    <form method="POST" action="{{ $act('deny-break', $session->id) }}" style="display:inline">
                      @csrf<button class="btn btn-ghost btn-sm" type="submit">Deny</button>
                    </form>
                  @endif
                  <form method="POST" action="{{ $act('grant-time', $session->id) }}" style="display:inline-flex;gap:.3rem;align-items:center">
                    @csrf
                    <input name="minutes" type="number" value="10" min="1" max="180" style="width:3.6rem" />
                    <button class="btn btn-ghost btn-sm" type="submit">Grant time</button>
                  </form>
                  <form method="POST" action="{{ $act('force-submit', $session->id) }}" style="display:inline"
                        onsubmit="return confirm('Submit this attempt on the taker\'s behalf?')">
                    @csrf<button class="btn btn-ghost btn-sm" type="submit">Force submit</button>
                  </form>
                  <form method="POST" action="{{ $act('suspend', $session->id) }}" style="display:inline"
                        onsubmit="return confirm('Suspend this attempt?')">
                    @csrf<button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Suspend</button>
                  </form>
                @elseif($session->status === 'suspended')
                  <form method="POST" action="{{ $act('reinstate', $session->id) }}" style="display:inline">
                    @csrf<button class="btn btn-primary btn-sm" type="submit">Reinstate</button>
                  </form>
                @endif
                <form method="POST" action="{{ $act('delete-attempt', $session->id) }}" style="display:inline"
                      onsubmit="return confirm('Delete this attempt entirely? Its answers, events and captures go with it.')">
                  @csrf @method('DELETE')
                  <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      @if($tab === 'attempts')
        <div style="margin-top:1rem">{{ $rows->links() }}</div>
      @endif
    @endif
  </div>

@elseif($tab === 'proctors')
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem">
      <h3 style="margin:0">Proctors on this assessment</h3>
      <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.people.proctors', ['school' => $school->slug]) }}">Manage</a>
    </div>
    @if($proctors->isEmpty())
      <p class="muted">Nobody is assigned to invigilate this assessment.</p>
    @else
      <ul style="margin:0;padding-left:1.1rem">
        @foreach($proctors as $proctor)
          <li>{{ $staffNames[$proctor->user_id] ?? 'Unknown' }}
            @if(! $proctor->assessment_id)<span class="badge badge-ok">School wide</span>@endif
          </li>
        @endforeach
      </ul>
    @endif
  </div>

@elseif($tab === 'graders')
  <div class="card" style="margin-bottom:1.1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem">
      <h3 style="margin:0">Graders</h3>
      <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.people.graders', ['school' => $school->slug]) }}">Manage</a>
    </div>
    <p class="muted" style="margin-bottom:.8rem">
      Sharing: <strong>{{ ucfirst($config->mode) }}</strong>@if($config->cap) (cap {{ $config->cap }})@endif
      &middot; {{ $assignments }} attempt(s) dealt out.
    </p>
    @if($graders->isEmpty())
      <p class="muted">Nobody is assigned to mark this assessment.</p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Name</th><th>Layer</th><th>Blind</th><th>Deadline</th></tr></thead>
        <tbody>
          @foreach($graders as $grader)
            <tr>
              <td data-label="Name">{{ $staffNames[$grader->user_id] ?? 'Unknown' }}</td>
              <td class="muted" data-label="Layer">{{ $grader->isReviewer() ? 'Second review' : 'First grader' }}</td>
              <td data-label="Blind">{{ $grader->anonymous ? 'Yes' : 'No' }}</td>
              <td class="muted" data-label="Deadline">{{ $grader->deadline?->format('d M Y') ?? 'None' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>

@elseif($tab === 'questions')
  <div class="card" style="margin-bottom:1.1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem">
      <h3 style="margin:0">Fixed questions ({{ $questions->count() }})</h3>
      <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">Edit</a>
    </div>
    @if($questions->isEmpty())
      <p class="muted">No questions added yet.</p>
    @else
      <ol style="margin:0;padding-left:1.2rem">
        @foreach($questions as $aq)
          <li style="margin-bottom:.3rem">
            {{ \Illuminate\Support\Str::limit($aq->question->prompt, 90) }}
            <span class="muted" style="font-size:.78rem">&middot; {{ $aq->points_override ?? $aq->question->points }} mark(s)</span>
          </li>
        @endforeach
      </ol>
    @endif
  </div>

  <div class="card">
    <h3 style="margin-bottom:.5rem">Random pools ({{ $pools->count() }})</h3>
    @if($pools->isEmpty())
      <p class="muted">No pools. Every taker gets the same fixed questions above.</p>
    @else
      <ul style="margin:0;padding-left:1.1rem">
        @foreach($pools as $pool)
          <li>Draw {{ $pool->draw_count }} from <strong>{{ $pool->category?->name }}</strong> at {{ $pool->points_each }} mark(s) each</li>
        @endforeach
      </ul>
    @endif
  </div>

@elseif($tab === 'events')
  <div class="card">
    @if($events->isEmpty())
      <p class="muted">No proctoring signals recorded.</p>
    @else
      <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>When</th><th>Taker</th><th>Signal</th><th>Points</th><th></th></tr></thead>
        <tbody>
          @foreach($events as $event)
            <tr @if($event->overridden) style="opacity:.5" @endif>
              <td class="muted" data-label="When">{{ $event->occurred_at?->format('d M H:i:s') }}</td>
              <td data-label="Taker">{{ $event->session?->takeable?->name ?? 'Unknown' }}</td>
              <td data-label="Signal">
                {{ ucfirst(str_replace('_', ' ', $event->type)) }}
                @if($event->overridden)<span class="badge badge-ok">Dismissed</span>@endif
              </td>
              <td class="muted" data-label="Points">+{{ $event->score_impact }}</td>
              <td>
                @if(! $event->overridden && $event->score_impact > 0)
                  <form method="POST" action="{{ route('school.cbt.hub.override-event', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'eventId' => $event->id]) }}"
                        onsubmit="return confirm('Dismiss this signal and refund its risk points?')">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">Dismiss</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
      <div style="margin-top:1rem">{{ $events->links() }}</div>
    @endif
  </div>

@elseif($tab === 'gradebook')
  <div class="card">
    @if(! $linked)
      <p class="muted">This assessment isn't linked to a CA/Exam component. Link one from Settings to have graded scores reach the gradebook.</p>
    @else
      <div class="page-head" style="margin-bottom:.8rem">
        <div>
          <h3>Counts toward: {{ $component->name }}</h3>
          <p class="subtitle">Out of {{ $component->max_score }} &middot; {{ $assessment->gradebook_sync_mode === 'auto' ? 'Automatic sync' : 'Review then push' }}</p>
        </div>
        @if($assessment->gradebook_sync_mode !== 'auto' && $sessions->whereNull('gradebook_synced_at')->isNotEmpty())
          <form method="POST" action="{{ route('school.cbt.hub.push-gradebook-all', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">
            @csrf
            <button class="btn btn-primary btn-sm" type="submit">Push all</button>
          </form>
        @endif
      </div>
      @if($sessions->isEmpty())
        <p class="muted">No graded attempts yet.</p>
      @else
        <div style="overflow-x:auto">
        <table class="table-stack">
          <thead><tr><th>Taker</th><th>Raw score</th><th>Scaled score</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach($sessions as $session)
              <tr>
                <td data-label="Taker">{{ $session->takeable?->name ?? 'Unknown' }}</td>
                <td class="muted" data-label="Raw score">{{ $session->total_score }} / {{ $session->max_score }}</td>
                <td class="muted" data-label="Scaled score">{{ app(\App\Services\Cbt\GradebookSyncService::class)->previewScore($session) }} / {{ $component->max_score }}</td>
                <td data-label="Status">
                  @if($session->gradebook_synced_at)
                    <span class="badge badge-ok">Synced {{ $session->gradebook_synced_at->diffForHumans() }}</span>
                  @else
                    <span class="badge badge-warn">Not synced</span>
                  @endif
                </td>
                <td>
                  <form method="POST" action="{{ route('school.cbt.hub.push-gradebook', ['school' => $school->slug, 'assessmentId' => $assessment->id, 'sessionId' => $session->id]) }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm" type="submit">{{ $session->gradebook_synced_at ? 'Re-push' : 'Push to gradebook' }}</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      @endif
    @endif
  </div>

@elseif($tab === 'brand')
  <div class="card">
    <h3 style="margin-bottom:.5rem">Branding</h3>
    <p class="muted" style="margin-bottom:.8rem">Give this assessment its own name and colour on the take screen. Leave both blank to use the school's own branding.</p>
    <form method="POST" action="{{ route('school.cbt.console.branding', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">
      @csrf
      <div class="grid cols-2" style="gap:.75rem">
        <div class="field">
          <label for="brand_name">Display name</label>
          <input id="brand_name" name="brand_name" type="text" value="{{ $assessment->brand_name }}" placeholder="{{ $assessment->title }}" />
        </div>
        <div class="field">
          <label for="brand_color">Colour</label>
          <input id="brand_color" name="brand_color" type="color" value="{{ $assessment->brand_color ?: ($accent ?: '#0f6cbf') }}" />
        </div>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save branding</button>
    </form>
  </div>

@elseif($tab === 'settings')
  <div class="card">
    <h3 style="margin-bottom:.8rem">Configuration</h3>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <tbody>
        <tr><td data-label="Duration">Duration</td><td>{{ $assessment->duration_minutes }} minutes</td></tr>
        <tr><td data-label="Attempts">Attempts allowed</td><td>{{ $assessment->max_attempts === 0 ? 'Unlimited' : $assessment->max_attempts }}</td></tr>
        <tr><td data-label="Grade method">Grade method</td><td>{{ ucfirst($assessment->grade_method) }}</td></tr>
        <tr><td data-label="Pass mark">Pass mark</td><td>{{ $assessment->pass_mark ?? 'Not set' }}</td></tr>
        <tr><td data-label="Window">Window</td><td>{{ $assessment->opens_at?->format('d M Y H:i') ?? 'Always open' }} to {{ $assessment->closes_at?->format('d M Y H:i') ?? 'no close' }}</td></tr>
        <tr><td data-label="PIN">Access PIN</td><td>{{ $assessment->access_pin ? 'Set' : 'None' }}</td></tr>
        <tr><td data-label="Questions per page">Questions per page</td><td>{{ $assessment->questions_per_page }}</td></tr>
        <tr><td data-label="Shuffle">Shuffle questions</td><td>{{ $assessment->shuffle_questions ? 'Yes' : 'No' }}</td></tr>
        <tr><td data-label="Randomise">Random subset</td><td>{{ $assessment->randomize ? 'Draw '.$assessment->num_questions : 'No' }}</td></tr>
        <tr><td data-label="Results">Results release</td><td>{{ str_replace('_', ' ', $assessment->results_mode) }}</td></tr>
        <tr><td data-label="Security">Security profile</td><td>{{ $assessment->securityProfile?->name }}</td></tr>
        <tr><td data-label="Overrides">Per-taker overrides</td><td>{{ $schedules }}</td></tr>
      </tbody>
    </table>
    </div>
    <a class="btn btn-primary btn-sm" style="margin-top:.8rem" href="{{ route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id]) }}">Change these settings</a>
  </div>
@endif
@endsection
