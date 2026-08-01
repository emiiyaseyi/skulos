@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Score Matrix'])
@section('title', 'Score Matrix')

@section('content')
@include('school.cbt._banner', [
  'icon' => '&#127942;',
  'title' => 'Score Matrix',
  'subtitle' => "Every taker's score across every assessment, side by side.",
  'links' => [
    ['label' => 'All results', 'url' => route('school.cbt.scores.results', ['school' => $school->slug]), 'tone' => 'green'],
    ['label' => 'Admin Backend', 'url' => route('school.cbt.console.index', ['school' => $school->slug]), 'tone' => 'grey'],
    ['label' => 'Export CSV', 'url' => route('school.cbt.scores.matrix.export', ['school' => $school->slug]), 'tone' => 'cyan'],
  ],
])

<form method="GET" action="{{ route('school.cbt.scores.matrix', ['school' => $school->slug]) }}"
      class="card" style="margin-bottom:1.1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
  <div class="field" style="margin:0;flex:1;min-width:12rem">
    <label for="subject">Filter by subject</label>
    <select id="subject" name="subject">
      <option value="">All subjects</option>
      @foreach($subjects as $subject)
        <option value="{{ $subject->id }}" @selected((string) $subjectId === (string) $subject->id)>{{ $subject->name }}</option>
      @endforeach
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="sort">Sort by</label>
    <select id="sort" name="sort">
      <option value="name" @selected($sort === 'name')>Name, A to Z</option>
      <option value="total_desc" @selected($sort === 'total_desc')>Total, highest first</option>
      <option value="total_asc" @selected($sort === 'total_asc')>Total, lowest first</option>
      <option value="average_desc" @selected($sort === 'average_desc')>Average, highest first</option>
      <option value="recent" @selected($sort === 'recent')>Most recently completed</option>
    </select>
  </div>
  <button class="btn btn-primary btn-sm" type="submit">Apply</button>
</form>

<div class="card">
  <p class="muted" style="margin-bottom:.8rem">
    Each cell is that taker's final grade for the assessment, resolved by its own rule
    (highest, average, first or last attempt). A dash means they have not completed it.
    Total and Average cover only the assessments they have completed.
  </p>

  @if($rows->isEmpty())
    <p class="muted">Nobody has attempted an assessment yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th style="position:sticky;left:0;background:var(--surface,#fff)">Taker</th>
          @foreach($assessments as $assessment)
            <th style="white-space:nowrap">
              {{ $assessment->title }}
              @if($assessment->subject)
                <div class="muted" style="font-weight:400;font-size:.72rem">{{ $assessment->subject->name }}</div>
              @endif
            </th>
          @endforeach
          <th>Total</th>
          <th>Average</th>
          <th>Last completed</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td style="position:sticky;left:0;background:var(--surface,#fff)">
              <div class="cbt-person"><strong>{{ $row['name'] }}</strong></div>
            </td>
            @foreach($assessments as $assessment)
              @php($grade = $row['scores'][$assessment->id] ?? null)
              <td style="text-align:center">
                @if($grade && $grade['score'] !== null)
                  <strong>{{ $grade['score'] }}</strong>
                  <div class="muted" style="font-size:.7rem">
                    / {{ $grade['max'] }}@if($grade['attempts'] > 1) &middot; {{ $grade['attempts'] }} attempts @endif
                  </div>
                @else
                  <span class="muted">&mdash;</span>
                @endif
              </td>
            @endforeach
            <td style="text-align:center"><strong>{{ $row['total'] }}</strong></td>
            <td style="text-align:center" class="muted">{{ $row['average'] ?? '—' }}</td>
            <td class="muted" style="white-space:nowrap">{{ $row['last_completed']?->format('d M Y H:i') ?? '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
