@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Bulk Generate Report Cards'])
@section('title', 'Bulk Generate Report Cards')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Bulk Generate Report Cards</h1>
    <p class="subtitle">Generate every class's report cards at once for one term, straight to the reviewers, instead of one class at a time.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Back to Report Cards</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">Term</label>
      <select id="term_id" name="term_id" onchange="this.form.submit()">
        @foreach($terms as $t)
          <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0;min-width:12rem">
      <label for="report_type">Report type</label>
      <select id="report_type" name="report_type" onchange="this.form.submit()">
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM)>Full Term Report</option>
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM)>Mid Term Report</option>
      </select>
    </div>
  </form>
</div>

<div class="card">
  @if(! $term)
    <p class="muted">No academic term has been set up yet.</p>
  @elseif($classes->isEmpty())
    <p class="muted">No class has any students yet.</p>
  @else
    <p class="muted" style="margin-bottom:.8rem">{{ $term->session?->name }} {{ $term->name }}, {{ \App\Models\Tenant\ReportCardReview::reportTypeLabel($reportType) }}. A class already fully generated is skipped, this only ever fills in what is missing.</p>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Section</th><th>Class</th><th>Students</th><th>Already generated</th></tr></thead>
      <tbody>
        @foreach($classes as $row)
          <tr>
            <td class="muted">{{ $row['section'] }}</td>
            <td><strong>{{ $row['name'] }}</strong></td>
            <td>{{ $row['student_count'] }}</td>
            <td class="muted">{{ $row['already_generated'] }} of {{ $row['student_count'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <form method="POST" action="{{ route('school.report-cards.bulk-generate.submit', ['school' => $school->slug]) }}" style="margin-top:1rem" onsubmit="return confirm('Generate every missing report card across all classes for {{ $term->session?->name }} {{ $term->name }}? This sends them straight to the review queue.')">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}" />
      <input type="hidden" name="report_type" value="{{ $reportType }}" />
      <button class="btn btn-primary" type="submit">Generate all classes</button>
    </form>
  @endif
</div>
@endsection
