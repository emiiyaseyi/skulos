@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Bulk Score Workbook'])
@section('title', 'Bulk Score Workbook')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Bulk Score Workbook</h1>
    <p class="subtitle">Download one Excel workbook covering every class at once (one sheet per class, students and subjects pre-filled), enter scores offline, then upload it back to populate scores across the whole school in one go.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.examinations.structure', ['school' => $school->slug]) }}">Assessment Structure</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" action="{{ route('school.examinations.workbook', ['school' => $school->slug]) }}" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
    <div class="field" style="margin:0;min-width:14rem">
      <label for="term_id">{{ \App\Support\TermLabel::current() }}</label>
      <select id="term_id" name="term_id" onchange="this.form.requestSubmit()">
        @foreach($terms as $t)
          <option value="{{ $t->id }}" @selected($term && $term->id === $t->id)>{{ $t->session?->name }} {{ $t->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field" style="margin:0;min-width:12rem">
      <label for="report_type">Report type</label>
      <select id="report_type" name="report_type" onchange="this.form.requestSubmit()">
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM)>Full {{ \App\Support\TermLabel::current() }} Report</option>
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM)>Mid {{ \App\Support\TermLabel::current() }} Report</option>
      </select>
    </div>
  </form>
</div>

<div class="grid cols-2" style="gap:1rem;align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.5rem">Download</h3>
    <p class="muted" style="margin-bottom:.8rem">One workbook, one sheet per class with students in it, already pre-filled with whatever scores exist today. Any cell you type a value into that is outside the component's own range (below 0, or above its own maximum) is flagged red right inside Excel, before you ever upload it back.</p>
    <a class="btn btn-primary btn-sm" href="{{ route('school.examinations.workbook.download', ['school' => $school->slug, 'term_id' => $term->id, 'report_type' => $reportType]) }}">Download workbook ({{ \App\Models\Tenant\ReportCardReview::reportTypeLabel($reportType) }})</a>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.5rem">Upload</h3>
    <p class="muted" style="margin-bottom:.8rem">Upload the same workbook back once you have entered scores offline. Every sheet is matched back to its own class automatically; a blank cell is left untouched, never treated as a zero.</p>
    <form method="POST" action="{{ route('school.examinations.workbook.upload', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}" />
      <input type="hidden" name="report_type" value="{{ $reportType }}" />
      <div class="field">
        <label for="file">Completed workbook (.xlsx)</label>
        <input type="file" id="file" name="file" accept=".xlsx" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Upload and save scores</button>
    </form>
  </div>
</div>
@endsection
