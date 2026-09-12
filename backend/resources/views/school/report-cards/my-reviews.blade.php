@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'My Report Card Reviews'])
@section('title', 'My Report Card Reviews')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">My Report Card Reviews</h1>
    <p class="subtitle">{{ $term->name ?? 'The current term' }}.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <form method="GET" action="{{ route('school.report-cards.my-reviews', ['school' => $school->slug]) }}">
      <select name="report_type" onchange="this.form.requestSubmit()">
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_FULL_TERM)>Full Term Report</option>
        <option value="{{ \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM }}" @selected($reportType === \App\Models\Tenant\ReportCardReview::REPORT_TYPE_MID_TERM)>Mid Term Report</option>
      </select>
    </form>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Back to Report Cards</a>
  </div>
</div>

@if(session('status'))<div class="flash flash-ok" style="margin-bottom:1.1rem">{{ session('status') }}</div>@endif
@if(session('error'))<div class="flash flash-bad" style="margin-bottom:1.1rem">{{ session('error') }}</div>@endif

@if($showMyClassSection)
<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.3rem">My class's report cards</h3>
  <p class="muted" style="margin-bottom:.8rem">Every report card generated for your own class, whoever it's assigned to review. This section is informational only -- use "Assigned to me" below to act on one.</p>
  @if($myClassReviews->isEmpty())
    <p class="muted">No report cards generated for your class yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Student</th><th>Status</th><th>Assigned to</th><th>Notes</th></tr></thead>
      <tbody>
        @foreach($myClassReviews as $review)
          <tr>
            <td data-label="Student"><strong>{{ $review->student?->fullName() }}</strong></td>
            <td data-label="Status">
              @if($review->status === \App\Models\Tenant\ReportCardReview::STATUS_NEEDS_CORRECTION)
                <span class="badge badge-warn">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @elseif(in_array($review->status, [\App\Models\Tenant\ReportCardReview::STATUS_APPROVED, \App\Models\Tenant\ReportCardReview::STATUS_RELEASED, \App\Models\Tenant\ReportCardReview::STATUS_LOCKED]))
                <span class="badge badge-ok">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @else
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @endif
            </td>
            <td class="muted" data-label="Assigned to">{{ $review->assignedTo?->fullName() ?? 'Not assigned' }}</td>
            <td class="muted" data-label="Notes">{{ $review->notes ?: '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endif

<div class="card">
  <h3 style="margin-bottom:.3rem">Assigned to me</h3>
  <p class="muted" style="margin-bottom:.8rem">Report cards an admin has assigned specifically to you to approve or send back for correction.</p>
  @if($assignedToMe->isEmpty())
    <p class="muted">Nothing assigned to you right now.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Student</th><th>Class</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($assignedToMe as $review)
          <tr>
            <td data-label="Student"><strong>{{ $review->student?->fullName() }}</strong></td>
            <td class="muted" data-label="Class">{{ $review->student?->currentClassArm?->schoolClass?->name ?? 'Not set' }}</td>
            <td data-label="Status">
              @if($review->status === \App\Models\Tenant\ReportCardReview::STATUS_NEEDS_CORRECTION)
                <span class="badge badge-warn">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @else
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @endif
            </td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $review->student_id, 'termId' => $term->id, 'report_type' => $reportType]) }}" target="_blank" rel="noopener">View</a>
              <form method="POST" action="{{ route('school.report-cards.approve', ['school' => $school->slug, 'studentId' => $review->student_id]) }}" style="display:inline">
                @csrf
                <input type="hidden" name="report_type" value="{{ $reportType }}" />
                <button class="btn btn-ghost btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.report-cards.send-back', ['school' => $school->slug, 'studentId' => $review->student_id]) }}" style="display:inline" class="rc-reject-form">
                @csrf
                <input type="hidden" name="report_type" value="{{ $reportType }}" />
                <input type="hidden" name="assigned_to_staff_id" value="{{ $review->assigned_to_staff_id }}" />
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm rc-reject-btn" type="button" data-staff="{{ $review->assigned_to_staff_id }}">Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<script>
  (function(){
    document.querySelectorAll('.rc-reject-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var form = btn.closest('form');
        var notes = prompt('What needs correcting? A comment is required.');
        if (notes === null) return;
        if (! notes.trim()) { alert('A comment is required to reject a report card.'); return; }
        form.querySelector('[name="notes"]').value = notes.trim();
        form.submit();
      });
    });
  })();
</script>
@endsection
