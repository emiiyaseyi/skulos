@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Payments'])
@section('title', 'Payments')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Payments</h1>
    <p class="subtitle">{{ $isFamily ? 'Bank transfer payments you have submitted.' : 'Bank transfer payments submitted to this school.' }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    @if(! $isFamily)
      <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.index', ['school' => $school->slug]) }}">Fee records</a>
    @endif
    @if($canSubmit)
      <a class="btn btn-primary btn-sm" href="{{ route('school.finance.payments.create', ['school' => $school->slug]) }}">Submit payment</a>
    @endif
  </div>
</div>

{{--
  Term switcher (user request 2026-07-25: "one can switch term view but
  the default for parent should be set by admin"). $activeTerm resolves
  from ?term_id= first, then the admin-set finance_active_term_id
  setting, then Term::current(). Everyone can view a different term;
  only someone who can verify payments can change the parent default.
--}}
@if(count($terms))
  <div class="card" style="margin-bottom:1.1rem">
    <div style="display:flex;flex-wrap:wrap;gap:1.2rem;align-items:flex-end;justify-content:space-between">
      <form method="GET" style="max-width:18rem">
        @if($status)<input type="hidden" name="status" value="{{ $status }}" />@endif
        <div class="field" style="margin:0">
          <label for="term_id">Viewing term</label>
          <select id="term_id" name="term_id" onchange="this.form.submit()">
            @foreach($terms as $term)
              <option value="{{ $term->id }}" {{ $activeTerm && (int) $activeTerm->id === (int) $term->id ? 'selected' : '' }}>{{ $term->name }} ({{ $term->session?->name }})</option>
            @endforeach
          </select>
        </div>
      </form>
      @if($canVerify)
        <form method="POST" action="{{ route('school.finance.payments.active-term', ['school' => $school->slug]) }}" style="max-width:18rem">
          @csrf
          <div class="field" style="margin:0">
            <label for="default_term_id">Default term parents see</label>
            <select id="default_term_id" name="term_id" onchange="this.form.submit()">
              <option value="" {{ ! $defaultTermId ? 'selected' : '' }}>The school's current term (automatic)</option>
              @foreach($terms as $term)
                <option value="{{ $term->id }}" {{ (string) $defaultTermId === (string) $term->id ? 'selected' : '' }}>{{ $term->name }} ({{ $term->session?->name }})</option>
              @endforeach
            </select>
          </div>
        </form>
      @endif
    </div>
  </div>
@endif

{{--
  Class-level payment status (user request 2026-07-25: "under payments,
  show a data table of all the classes in the school, number of student
  per class, number that has paid full fees, number that has paid
  partial payments, a trigger [to nudge] parent to those who are yet to
  pay at all, or have partial payment left"). Scoped to $activeTerm; a
  class with no fee items configured for that term is left out
  entirely, there is nothing to be "owing" against.
--}}
@if(! $isFamily && count($classStatus))
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.6rem">{{ $activeTerm?->name }} fee status by class</h3>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Class</th><th>Students</th><th>Paid in full</th><th>Partial</th><th>Not paid</th><th></th></tr></thead>
      <tbody>
        @foreach($classStatus as $row)
          <tr>
            <td><strong>{{ $row['label'] }}</strong></td>
            <td class="muted">{{ $row['studentCount'] }}</td>
            <td><span class="badge badge-ok">{{ $row['fullyPaid'] }}</span></td>
            <td><span class="badge badge-warn">{{ $row['partial'] }}</span></td>
            <td><span class="badge badge-bad">{{ $row['unpaid'] }}</span></td>
            <td>
              @if($canVerify && ($row['partial'] + $row['unpaid']) > 0)
                <form method="POST" action="{{ route('school.finance.payments.nudge', ['school' => $school->slug]) }}" style="display:inline">
                  @csrf
                  <input type="hidden" name="class_arm_id" value="{{ $row['arm']->id }}" />
                  <input type="hidden" name="term_id" value="{{ $activeTerm?->id }}" />
                  <button class="btn btn-ghost btn-sm" type="submit">Nudge parents</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif

{{--
  Payment countdown/reminder popup settings (user request 2026-07-25:
  "let the admin configure a payment countdown date to parents... 15
  days (as set by the admin) it shows daily on the parent login as pop
  up reminder to pay"). Left blank, no popup ever shows to any parent.
--}}
@if($canVerify)
  <div class="card" style="margin-bottom:1.1rem;max-width:30rem">
    <h3 style="margin-bottom:.4rem">Payment reminder</h3>
    <p class="muted" style="margin-bottom:.8rem">A parent who still owes fees sees a daily reminder pop up, starting this many days before the due date. It keeps showing, as overdue, for as long as a balance remains past that date.</p>
    <form method="POST" action="{{ route('school.finance.payments.due-date', ['school' => $school->slug]) }}" class="grid cols-2" style="gap:1rem">
      @csrf
      <div class="field" style="margin:0">
        <label for="payment_due_date">Due date</label>
        <input id="payment_due_date" name="payment_due_date" type="date" value="{{ $paymentDueDate }}" />
      </div>
      <div class="field" style="margin:0">
        <label for="payment_reminder_days">Remind starting this many days before</label>
        <input id="payment_reminder_days" name="payment_reminder_days" type="number" min="1" max="90" required value="{{ $paymentReminderDays }}" />
      </div>
      <div style="grid-column:1/-1">
        <button class="btn btn-primary btn-sm" type="submit">Save</button>
      </div>
    </form>
  </div>
@endif

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="max-width:16rem">
    @if($activeTerm && request()->query('term_id'))<input type="hidden" name="term_id" value="{{ $activeTerm->id }}" />@endif
    <div class="field" style="margin:0">
      <label for="status">Status</label>
      <select id="status" name="status" onchange="this.form.submit()">
        <option value="" {{ ! $status ? 'selected' : '' }}>All</option>
        <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="verified" {{ $status === 'verified' ? 'selected' : '' }}>Verified</option>
        <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
      </select>
    </div>
  </form>
</div>

<div class="card">
  @if($payments->isEmpty())
    <p class="muted">No payments{{ $status ? ' with this status' : '' }} yet.</p>
  @else
    @if($canVerify)
      <form id="bulkPaymentsForm" method="POST" action="{{ route('school.finance.payments.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canVerify)<th style="width:2.2rem"><input type="checkbox" id="payment-select-all" aria-label="Select all pending payments" /></th>@endif
        <th>Date</th><th>Student</th><th>For</th><th>Amount</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($payments as $payment)
          <tr>
            @if($canVerify)
              <td>
                @if($payment->status === 'pending')
                  <input type="checkbox" form="bulkPaymentsForm" class="payment-row-check" name="payment_ids[]" value="{{ $payment->id }}" aria-label="Select payment from {{ $payment->student?->fullName() }}" />
                @endif
              </td>
            @endif
            <td class="muted" style="white-space:nowrap">{{ $payment->created_at->format('d M Y') }}</td>
            <td>{{ $payment->student?->fullName() }}</td>
            <td class="muted">{{ $payment->feeItem?->name ?? 'General payment' }}@if($payment->installment) &middot; {{ $payment->installment->label }}@endif</td>
            <td>₦{{ number_format((float) $payment->amount, 2) }}</td>
            <td>
              <span class="badge {{ $payment->status === 'verified' ? 'badge-ok' : ($payment->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($payment->status) }}</span>
            </td>
            <td><a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.show', ['school' => $school->slug, 'paymentId' => $payment->id]) }}">View</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $payments->links() }}</div>

    @if($canVerify)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of pending payments above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkPaymentsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="verify">Verify selected</button>
          <button form="bulkPaymentsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="reject" onclick="return confirm('Reject the selected payments?')">Reject selected</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('payment-select-all');
          var boxes = document.querySelectorAll('.payment-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkPaymentsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one pending payment first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endsection
