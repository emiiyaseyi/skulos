@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Submit Payment'])
@section('title', 'Submit Payment')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Submit Payment</h1>
    <p class="subtitle">Paid by bank transfer? Upload your evidence here for verification.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.index', ['school' => $school->slug]) }}">Back</a>
</div>

<div class="card" style="max-width:40rem">
  @if($students->isEmpty())
    <p class="muted">No student on record to submit a payment for.</p>
  @else
    <form method="POST" action="{{ route('school.finance.payments.store', ['school' => $school->slug]) }}" enctype="multipart/form-data" id="paymentForm">
      @csrf
      <div class="field">
        <label for="student_id">Student</label>
        <select id="student_id" name="student_id" required>
          <option value="">Select a student</option>
          @foreach($students as $student)
            <option value="{{ $student->id }}" @selected(old('student_id') == $student->id)>{{ $student->fullName() }} ({{ $student->admission_number }})</option>
          @endforeach
        </select>
      </div>

      <div class="field">
        <label for="fee_item_id">What is this for (optional)</label>
        <select id="fee_item_id" name="fee_item_id">
          <option value="">General payment</option>
          @foreach($feeItems as $item)
            <option value="{{ $item->id }}" @selected(old('fee_item_id') == $item->id)>{{ $item->name }} &middot; ₦{{ number_format((float) $item->amount, 2) }} ({{ $item->term?->name }})</option>
          @endforeach
        </select>
      </div>

      <div class="field">
        <label for="amount">Amount paid (₦)</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" />
      </div>

      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="bank_name">Bank name (optional)</label>
          <input id="bank_name" name="bank_name" maxlength="120" value="{{ old('bank_name') }}" />
        </div>
        <div class="field">
          <label for="reference">Transaction reference (optional)</label>
          <input id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" />
        </div>
      </div>

      <div class="field">
        <label for="evidence">Evidence of payment</label>
        <input id="evidence" name="evidence" type="file" accept="image/png,image/jpeg,image/webp,.pdf" required />
        <p class="hint">A screenshot or photo of your transfer receipt, or a PDF bank statement.</p>
      </div>

      <button class="btn btn-primary btn-sm" type="submit" id="paymentSubmit">Submit for verification</button>
    </form>
    <script>
      {{-- A multi-MB evidence upload on a slow connection previously gave
           no feedback at all, so it looked frozen (audit finding M-13,
           2026-07-17). --}}
      document.getElementById('paymentForm')?.addEventListener('submit', function(){
        var btn = document.getElementById('paymentSubmit');
        if (!btn) return;
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'Uploading...';
      });
    </script>
  @endif
</div>
@endsection
