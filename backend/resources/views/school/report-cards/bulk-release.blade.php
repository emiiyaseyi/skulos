@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Bulk Release Report Cards'])
@section('title', 'Bulk Release Report Cards')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Bulk Release Report Cards</h1>
    <p class="subtitle">Release every generated report card for one term at once, instead of one student at a time.</p>
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
  @else
    <p class="muted" style="margin-bottom:.8rem">{{ $term->session?->name }} {{ $term->name }}. Choose which generated report cards to release; an already released, locked, or archived one is always left alone.</p>

    <div class="grid cols-3" style="gap:1rem;margin-bottom:1.1rem">
      <div class="card stat" style="padding:1rem"><b>{{ $counts['approved'] }}</b><span>Approved, not yet released</span></div>
      <div class="card stat" style="padding:1rem"><b>{{ $counts['non_approved'] }}</b><span>Not yet approved</span></div>
      <div class="card stat" style="padding:1rem"><b>{{ $counts['already_released'] }}</b><span>Already released</span></div>
    </div>

    <div class="stack" style="gap:.7rem">
      <form method="POST" action="{{ route('school.report-cards.bulk-release.submit', ['school' => $school->slug]) }}" onsubmit="return confirm('Release every approved report card for {{ $term->name }}?')">
        @csrf
        <input type="hidden" name="term_id" value="{{ $term->id }}" />
        <input type="hidden" name="report_type" value="{{ $reportType }}" />
        <input type="hidden" name="scope" value="approved" />
        <button class="btn btn-primary btn-sm" type="submit" @disabled($counts['approved'] === 0)>Release approved only ({{ $counts['approved'] }})</button>
      </form>
      <form method="POST" action="{{ route('school.report-cards.bulk-release.submit', ['school' => $school->slug]) }}" onsubmit="return confirm('Release every NOT YET APPROVED report card for {{ $term->name }} too, skipping the review stage entirely for them? This cannot be undone from here.')">
        @csrf
        <input type="hidden" name="term_id" value="{{ $term->id }}" />
        <input type="hidden" name="report_type" value="{{ $reportType }}" />
        <input type="hidden" name="scope" value="non_approved" />
        <button class="btn btn-ghost btn-sm" type="submit" @disabled($counts['non_approved'] === 0)>Release not yet approved only ({{ $counts['non_approved'] }})</button>
      </form>
      <form method="POST" action="{{ route('school.report-cards.bulk-release.submit', ['school' => $school->slug]) }}" onsubmit="return confirm('Release every generated report card for {{ $term->name }}, approved or not? This cannot be undone from here.')">
        @csrf
        <input type="hidden" name="term_id" value="{{ $term->id }}" />
        <input type="hidden" name="report_type" value="{{ $reportType }}" />
        <input type="hidden" name="scope" value="all" />
        <button class="btn btn-ghost btn-sm" type="submit" @disabled($counts['approved'] === 0 && $counts['non_approved'] === 0)>Release all generated ({{ $counts['approved'] + $counts['non_approved'] }})</button>
      </form>
    </div>
  @endif
</div>
@endsection
