@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $arm->label()])
@section('title', $arm->label())

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $arm->label() }}</h1>
    <p class="subtitle">{{ $term->name }} ({{ $term->session?->name }}) &middot; ₦{{ number_format($owed, 2) }} owed per student</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.index', ['school' => $school->slug, 'term_id' => $term->id]) }}">Back to Payments</a>
</div>

<div class="card">
  @if($rows->isEmpty())
    <p class="muted">No active students in this class.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Student</th><th>Status</th><th>Amount paid</th><th>Balance</th><th>Date paid</th></tr></thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td data-label="Student">
              <a href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $row['student']->id]) }}"><strong>{{ $row['student']->fullName() }}</strong></a>
              <div class="muted">{{ $row['student']->admission_number }}</div>
            </td>
            <td data-label="Status">
              @if($row['status'] === 'paid')
                <span class="badge badge-ok">Paid in full</span>
              @elseif($row['status'] === 'partial')
                <span class="badge badge-warn">Partial</span>
              @else
                <span class="badge badge-bad">Not paid</span>
              @endif
            </td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap" data-label="Amount paid">₦{{ number_format($row['paid'], 2) }}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap" data-label="Balance">{{ $row['balance'] > 0 ? '₦'.number_format($row['balance'], 2) : 'None' }}</td>
            <td class="muted" style="white-space:nowrap" data-label="Date paid">{{ $row['lastPaidAt'] ? \Illuminate\Support\Carbon::parse($row['lastPaidAt'])->format('d M Y') : 'Not paid yet' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
