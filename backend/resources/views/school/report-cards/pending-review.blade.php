@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Pending review'])
@section('title', 'Pending review')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Pending review</h1>
    <p class="subtitle">Every report card in {{ $term->name ?? 'the current term' }} still waiting on a decision, across all classes.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Back to Report Cards</a>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <h3 style="margin-bottom:.4rem">Review settings</h3>
  <p class="muted" style="margin-bottom:.8rem">Pick who reviews report cards for each class. Every report card generated for a class is automatically assigned to that class's reviewer{{ $reviewStageEnabled ? '' : ' (though the review stage itself is currently off under Report settings, so cards release immediately)' }}.</p>
  @if($sections->isEmpty() || $sections->every(fn($s) => $s->classes->isEmpty()))
    <p class="muted">No classes yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Section</th><th>Class</th><th>Reviewer</th></tr></thead>
      <tbody>
        @foreach($sections as $section)
          @foreach($section->classes as $class)
            <tr>
              <td class="muted">{{ $section->name }}</td>
              <td><strong>{{ $class->name }}</strong></td>
              <td>
                <form method="POST" action="{{ route('school.report-cards.class.default-reviewer', ['school' => $school->slug, 'classId' => $class->id]) }}">
                  @csrf
                  <select name="default_reviewer_staff_id" onchange="this.form.submit()">
                    <option value="">Not set</option>
                    @foreach($staffOptions as $staff)
                      <option value="{{ $staff->id }}" @selected($class->default_reviewer_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
                    @endforeach
                  </select>
                </form>
              </td>
            </tr>
          @endforeach
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card">
  @if($reviews->isEmpty())
    <p class="muted">Nothing is waiting on review right now.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Student</th><th>Class</th><th>Status</th><th>Assigned to</th><th></th></tr></thead>
      <tbody>
        @foreach($reviews as $review)
          <tr>
            <td><strong>{{ $review->student?->fullName() }}</strong></td>
            <td class="muted">{{ $review->student?->currentClassArm?->schoolClass?->name ?? 'Not set' }}</td>
            <td>
              @if($review->status === \App\Models\Tenant\ReportCardReview::STATUS_NEEDS_CORRECTION)
                <span class="badge badge-warn">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @else
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @endif
            </td>
            <td class="muted">
              <form method="POST" action="{{ route('school.report-cards.assign', ['school' => $school->slug, 'studentId' => $review->student_id]) }}">
                @csrf
                <select name="assigned_to_staff_id" onchange="this.form.submit()">
                  <option value="">Not assigned</option>
                  @foreach($staffOptions as $staff)
                    <option value="{{ $staff->id }}" @selected($review->assigned_to_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
                  @endforeach
                </select>
              </form>
            </td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $review->student_id, 'termId' => $term->id]) }}" target="_blank" rel="noopener">View</a>
              <form method="POST" action="{{ route('school.report-cards.approve', ['school' => $school->slug, 'studentId' => $review->student_id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.report-cards.send-back', ['school' => $school->slug, 'studentId' => $review->student_id]) }}" style="display:inline" class="rc-reject-form">
                @csrf
                <input type="hidden" name="assigned_to_staff_id" value="{{ $review->assigned_to_staff_id }}" />
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm rc-reject-btn" type="button" data-staff="{{ $review->assigned_to_staff_id }}">Reject</button>
              </form>
              <form method="POST" action="{{ route('school.report-cards.nudge', ['school' => $school->slug, 'studentId' => $review->student_id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Nudge</button>
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
        var staffId = btn.getAttribute('data-staff');
        if (! staffId) { alert('Assign this report card to a staff member first, then reject it back to them.'); return; }
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
