@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $class->name])
@section('title', $class->name.' report cards')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $class->name }} report cards</h1>
    <p class="subtitle">{{ $class->section?->name }} &middot; {{ $term->name ?? 'Current term' }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <form method="POST" action="{{ route('school.report-cards.class.generate', ['school' => $school->slug, 'classId' => $class->id]) }}">
      @csrf
      <input type="hidden" name="term_id" value="{{ $term->id }}" />
      <button class="btn btn-primary btn-sm" type="submit">Generate all</button>
    </form>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class.entry', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $term->id]) }}">Class teacher entry</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.class.print', ['school' => $school->slug, 'classId' => $class->id, 'term_id' => $term->id]) }}" target="_blank">Print all (PDF)</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.reports.students.index', ['school' => $school->slug]) }}">Broadsheet (Excel)</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug, 'term_id' => $term->id]) }}">Back to Report Cards</a>
  </div>
</div>

<div class="card">
  @if($students->isEmpty())
    <p class="muted">No students in this class yet.</p>
  @else
    @if($canAssign)
      <form id="bulkReviewForm" method="POST" action="{{ route('school.report-cards.class.bulk-assign', ['school' => $school->slug, 'classId' => $class->id]) }}">
        <input type="hidden" name="term_id" value="{{ $term->id }}" />
      </form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canAssign)<th style="width:2.2rem"><input type="checkbox" id="rc-select-all" aria-label="Select all students" /></th>@endif
        <th>Admission No.</th><th>Name</th><th>Status</th><th>Promotion</th><th>Assigned to</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($students as $student)
          @php($review = $reviews->get($student->id))
          @php($status = $review?->status)
          <tr>
            @if($canAssign)
              <td><input type="checkbox" form="bulkReviewForm" class="rc-row-check" name="student_ids[]" value="{{ $student->id }}" aria-label="Select {{ $student->fullName() }}" /></td>
            @endif
            <td class="muted" style="font-variant-numeric:tabular-nums">{{ $student->admission_number }}</td>
            <td><strong>{{ $student->fullName() }}</strong></td>
            <td>
              @if(! $review)
                <span class="badge badge-warn">Not generated</span>
              @elseif(in_array($status, [\App\Models\Tenant\ReportCardReview::STATUS_RELEASED, \App\Models\Tenant\ReportCardReview::STATUS_LOCKED], true))
                <span class="badge badge-ok">{{ \App\Models\Tenant\ReportCardReview::statusLabel($status) }}</span>
              @elseif($status === \App\Models\Tenant\ReportCardReview::STATUS_NEEDS_CORRECTION)
                <span class="badge badge-warn">{{ \App\Models\Tenant\ReportCardReview::statusLabel($status) }}</span>
              @elseif($status === \App\Models\Tenant\ReportCardReview::STATUS_ARCHIVED)
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($status) }}</span>
              @else
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($status) }}</span>
                @if($status === \App\Models\Tenant\ReportCardReview::STATUS_PENDING_REVIEW && $review->currentStageRole())
                  <div class="muted" style="font-size:.72rem">{{ $reviewChainRoles[$review->currentStageRole()] ?? $review->currentStageRole() }}</div>
                @endif
              @endif
            </td>
            <td class="muted" style="font-size:.85rem">
              @if($review && $canAssign)
                <form method="POST" action="{{ route('school.report-cards.promotion', ['school' => $school->slug, 'studentId' => $student->id]) }}">
                  @csrf
                  <input type="hidden" name="term_id" value="{{ $term->id }}" />
                  <select name="promotion_recommendation" onchange="this.form.submit()">
                    <option value="">Not set</option>
                    <option value="{{ \App\Models\Tenant\ReportCardReview::PROMOTION_PROMOTE }}" @selected($review->promotion_recommendation === \App\Models\Tenant\ReportCardReview::PROMOTION_PROMOTE)>Promote</option>
                    <option value="{{ \App\Models\Tenant\ReportCardReview::PROMOTION_CONDITIONAL }}" @selected($review->promotion_recommendation === \App\Models\Tenant\ReportCardReview::PROMOTION_CONDITIONAL)>Conditional</option>
                    <option value="{{ \App\Models\Tenant\ReportCardReview::PROMOTION_REPEAT }}" @selected($review->promotion_recommendation === \App\Models\Tenant\ReportCardReview::PROMOTION_REPEAT)>Repeat</option>
                  </select>
                </form>
              @else
                {{ \App\Models\Tenant\ReportCardReview::promotionLabel($review?->promotion_recommendation) ?? 'Not set' }}
              @endif
            </td>
            <td class="muted">
              @if($review && $canAssign)
                <form method="POST" action="{{ route('school.report-cards.assign', ['school' => $school->slug, 'studentId' => $student->id]) }}">
                  @csrf
                  <input type="hidden" name="term_id" value="{{ $term->id }}" />
                  <select name="assigned_to_staff_id" onchange="this.form.submit()">
                    <option value="">Not assigned</option>
                    @foreach($staffOptions as $staff)
                      <option value="{{ $staff->id }}" @selected($review->assigned_to_staff_id === $staff->id)>{{ $staff->fullName() }}</option>
                    @endforeach
                  </select>
                </form>
              @else
                {{ $review?->assignedTo?->fullName() ?? 'Not assigned' }}
              @endif
            </td>
            <td style="white-space:nowrap">
              @if(! $review)
                <form method="POST" action="{{ route('school.report-cards.generate', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="term_id" value="{{ $term->id }}" />
                  <button class="btn btn-ghost btn-sm" type="submit">Generate</button>
                </form>
              @else
                <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.show', ['school' => $school->slug, 'studentId' => $student->id, 'termId' => $term->id]) }}" target="_blank" rel="noopener">View</a>
                @if($canAssign)
                  <form method="POST" action="{{ route('school.report-cards.approve', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="term_id" value="{{ $term->id }}" />
                    <button class="btn btn-ghost btn-sm" type="submit">Approve</button>
                  </form>
                  <form method="POST" action="{{ route('school.report-cards.send-back', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline" class="rc-send-back-form">
                    @csrf
                    <input type="hidden" name="term_id" value="{{ $term->id }}" />
                    <input type="hidden" name="assigned_to_staff_id" value="{{ $review->assigned_to_staff_id }}" />
                    <input type="hidden" name="notes" value="" />
                    <button class="btn btn-ghost btn-sm rc-send-back-btn" type="button" data-staff="{{ $review->assigned_to_staff_id }}">Send back</button>
                  </form>
                @endif
                @if($canRelease && $status === \App\Models\Tenant\ReportCardReview::STATUS_APPROVED)
                  <form method="POST" action="{{ route('school.report-cards.release', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="term_id" value="{{ $term->id }}" />
                    <button class="btn btn-primary btn-sm" type="submit">Release</button>
                  </form>
                @endif
                @if($canRelease && $status === \App\Models\Tenant\ReportCardReview::STATUS_RELEASED)
                  <form method="POST" action="{{ route('school.report-cards.lock', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="term_id" value="{{ $term->id }}" />
                    <button class="btn btn-ghost btn-sm" type="submit">Lock</button>
                  </form>
                @endif
                @if($canRelease && in_array($status, [\App\Models\Tenant\ReportCardReview::STATUS_RELEASED, \App\Models\Tenant\ReportCardReview::STATUS_LOCKED], true))
                  <form method="POST" action="{{ route('school.report-cards.archive', ['school' => $school->slug, 'studentId' => $student->id]) }}" style="display:inline">
                    @csrf
                    <input type="hidden" name="term_id" value="{{ $term->id }}" />
                    <button class="btn btn-ghost btn-sm" type="submit">Archive</button>
                  </form>
                @endif
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canAssign)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of students above, then assign one staff member to review them all.</span>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <select form="bulkReviewForm" name="assigned_to_staff_id" required>
            <option value="">Choose staff&hellip;</option>
            @foreach($staffOptions as $staff)
              <option value="{{ $staff->id }}">{{ $staff->fullName() }}</option>
            @endforeach
          </select>
          <button form="bulkReviewForm" class="btn btn-ghost btn-sm" type="submit">Assign selected for review</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('rc-select-all');
          var boxes = document.querySelectorAll('.rc-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkReviewForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one student first.'); }
          });
        })();
      </script>
    @endif
    <script>
      (function(){
        document.querySelectorAll('.rc-send-back-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var form = btn.closest('form');
            var staffId = btn.getAttribute('data-staff');
            if (! staffId) { alert('Assign this report card to a staff member first, then send it back to them.'); return; }
            var notes = prompt('What needs correcting? A comment is required.');
            if (notes === null) return;
            if (! notes.trim()) { alert('A comment is required to send a report card back.'); return; }
            form.querySelector('[name="notes"]').value = notes.trim();
            form.submit();
          });
        });
      })();
    </script>
  @endif
</div>
@endsection
