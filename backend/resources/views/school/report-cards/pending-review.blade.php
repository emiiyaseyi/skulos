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
    <form id="bulkReviewApproveForm" method="POST" action="{{ route('school.report-cards.bulk-approve', ['school' => $school->slug]) }}">@csrf</form>
    <form id="bulkReviewSendBackForm" method="POST" action="{{ route('school.report-cards.bulk-send-back', ['school' => $school->slug]) }}">@csrf<input type="hidden" name="notes" value="" /></form>
    <form id="bulkReviewNudgeForm" method="POST" action="{{ route('school.report-cards.bulk-nudge', ['school' => $school->slug]) }}">@csrf</form>

    {{-- User request 2026-07-22: "under pending review section/page, let's also add the filter and sort option there too." --}}
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem">
      <input type="search" id="pr-filter" placeholder="Filter by student or class" aria-label="Filter by student or class" style="max-width:16rem" />
      <select id="pr-sort" aria-label="Sort">
        <option value="student">Sort: Student A to Z</option>
        <option value="class">Sort: Class</option>
        <option value="status">Sort: Status</option>
        <option value="assigned">Sort: Assigned to</option>
      </select>
    </div>

    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="review-select-all" aria-label="Select all report cards" /></th>
        <th>Student</th><th>Class</th><th>Status</th><th>Assigned to</th><th></th>
      </tr></thead>
      <tbody id="pr-rows">
        @foreach($reviews as $review)
          <tr data-student="{{ strtolower($review->student?->fullName() ?? '') }}" data-class="{{ strtolower($review->student?->currentClassArm?->schoolClass?->name ?? '') }}" data-status="{{ strtolower(\App\Models\Tenant\ReportCardReview::statusLabel($review->status)) }}" data-assigned="{{ strtolower($review->assignedTo?->fullName() ?? '') }}">
            <td><input type="checkbox" class="review-row-check" value="{{ $review->student_id }}" aria-label="Select {{ $review->student?->fullName() }}" /></td>
            <td data-label="Student"><strong>{{ $review->student?->fullName() }}</strong></td>
            <td class="muted" data-label="Class">{{ $review->student?->currentClassArm?->schoolClass?->name ?? 'Not set' }}</td>
            <td data-label="Status">
              @if($review->status === \App\Models\Tenant\ReportCardReview::STATUS_NEEDS_CORRECTION)
                <span class="badge badge-warn">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @else
                <span class="badge">{{ \App\Models\Tenant\ReportCardReview::statusLabel($review->status) }}</span>
              @endif
              @if($review->status === \App\Models\Tenant\ReportCardReview::STATUS_PENDING_REVIEW && $review->currentStageRole())
                <div class="muted">{{ config('skulos.report_card_review_roles')[$review->currentStageRole()] ?? $review->currentStageRole() }}</div>
              @endif
            </td>
            <td class="muted" data-label="Assigned to">
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

    <div class="row-between" style="margin-top:.6rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number above, then act on all of them at once.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-primary btn-sm" id="review-bulk-approve-btn">Approve selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="review-bulk-reject-btn">Reject selected</button>
        <button type="button" class="btn btn-ghost btn-sm" id="review-bulk-nudge-btn">Nudge selected</button>
      </div>
    </div>
    <div style="margin-top:1rem">{{ $reviews->links() }}</div>
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

    var prFilter = document.getElementById('pr-filter');
    var prSort = document.getElementById('pr-sort');
    var prRows = document.getElementById('pr-rows');

    prFilter?.addEventListener('input', function(){
      var q = this.value.trim().toLowerCase();
      prRows.querySelectorAll('tr').forEach(function(row){
        var matches = row.dataset.student.includes(q) || row.dataset.class.includes(q);
        row.style.display = matches ? '' : 'none';
      });
    });

    prSort?.addEventListener('change', function(){
      var rows = Array.prototype.slice.call(prRows.querySelectorAll('tr'));
      var key = this.value;
      rows.sort(function(a, b){ return a.dataset[key].localeCompare(b.dataset[key]); });
      rows.forEach(function(row){ prRows.appendChild(row); });
    });

    var all = document.getElementById('review-select-all');
    var boxes = function(){ return document.querySelectorAll('.review-row-check'); };
    all?.addEventListener('change', function(){ boxes().forEach(function(cb){ cb.checked = all.checked; }); });

    function selectedIds(){
      return Array.prototype.filter.call(boxes(), function(cb){ return cb.checked; }).map(function(cb){ return cb.value; });
    }

    function fillIds(form, ids){
      form.querySelectorAll('input[name="student_ids[]"]').forEach(function(el){ el.remove(); });
      ids.forEach(function(id){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_ids[]';
        input.value = id;
        form.appendChild(input);
      });
    }

    document.getElementById('review-bulk-approve-btn')?.addEventListener('click', function(){
      var ids = selectedIds();
      if (! ids.length) { alert('Tick at least one report card first.'); return; }
      var form = document.getElementById('bulkReviewApproveForm');
      fillIds(form, ids);
      form.submit();
    });

    document.getElementById('review-bulk-reject-btn')?.addEventListener('click', function(){
      var ids = selectedIds();
      if (! ids.length) { alert('Tick at least one report card first.'); return; }
      var notes = prompt('What needs correcting? A comment is required.');
      if (notes === null) return;
      if (! notes.trim()) { alert('A comment is required to reject a report card.'); return; }
      var form = document.getElementById('bulkReviewSendBackForm');
      fillIds(form, ids);
      form.querySelector('[name="notes"]').value = notes.trim();
      form.submit();
    });

    document.getElementById('review-bulk-nudge-btn')?.addEventListener('click', function(){
      var ids = selectedIds();
      if (! ids.length) { alert('Tick at least one report card first.'); return; }
      var form = document.getElementById('bulkReviewNudgeForm');
      fillIds(form, ids);
      form.submit();
    });
  })();
</script>
@endsection
