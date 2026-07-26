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

@if($bankAccounts->isNotEmpty())
  {{--
    Where to actually send the money before uploading evidence (user
    request 2026-07-25: "Parent only sees Bank Transfer Payment Option...
    a school can add more than one account details and the parent sees
    all"). Purely informational, shown above the form, not itself part
    of the submission.
  --}}
  <div class="card" style="max-width:40rem;margin-bottom:1.1rem">
    <h3 style="margin-bottom:.3rem">Bank transfer details</h3>
    <p class="muted" style="margin-bottom:.8rem">Send your payment to any one of these accounts, then upload your evidence below.</p>
    <div class="stack" style="gap:.7rem">
      @foreach($bankAccounts as $account)
        <div style="border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem">
          <div style="font-weight:800">{{ $account->bank_name }}</div>
          <div class="muted" style="font-size:.85rem">{{ $account->account_name }}</div>
          <div style="font-variant-numeric:tabular-nums;font-weight:700;margin-top:.2rem">{{ $account->account_number }}</div>
        </div>
      @endforeach
    </div>
  </div>
@endif

<div class="card" style="max-width:40rem">
  @if($students->isEmpty())
    <p class="muted">No student on record to submit a payment for.</p>
  @else
    <form method="POST" action="{{ route('school.finance.payments.store', ['school' => $school->slug]) }}" enctype="multipart/form-data" id="paymentForm">
      @csrf
      <div class="field">
        <label for="student_id">Student</label>
        <select id="student_id" name="student_id" required aria-invalid="@error('student_id') true @else false @enderror" @error('student_id') aria-describedby="student_id-error" @enderror>
          <option value="">Select a student</option>
          @foreach($students as $student)
            <option value="{{ $student->id }}" data-class-id="{{ $student->currentClassArm?->schoolClass?->id }}" @selected(old('student_id') == $student->id)>{{ $student->fullName() }} ({{ $student->admission_number }})</option>
          @endforeach
        </select>
        @error('student_id')<p class="err" id="student_id-error">{{ $message }}</p>@enderror
      </div>

      <div class="field">
        <label for="fee_item_id">What is this for (optional)</label>
        <select id="fee_item_id" name="fee_item_id">
          <option value="">General payment</option>
          @foreach($feeItems as $item)
            <option value="{{ $item->id }}" data-classes="{{ $item->schoolClasses->pluck('id')->join(',') }}" @selected(old('fee_item_id') == $item->id)>{{ $item->name }} &middot; ₦{{ number_format((float) $item->amount, 2) }} ({{ $item->term?->name }})</option>
          @endforeach
        </select>
        <p class="hint" id="feeItemClassHint" style="display:none">Showing only fees that apply to this student's class.</p>
        @error('fee_item_id')<p class="err">{{ $message }}</p>@enderror
      </div>

      <div class="field" id="installmentField" style="display:none">
        <label for="fee_item_installment_id">Which installment (optional)</label>
        <select id="fee_item_installment_id" name="fee_item_installment_id">
          <option value="">Full amount / not tied to one installment</option>
        </select>
        <p class="hint">This fee can be paid in installments. Choose one if this payment is for a specific installment.</p>
        @error('fee_item_installment_id')<p class="err">{{ $message }}</p>@enderror
      </div>

      <div class="field">
        <label for="amount">Amount paid (₦)</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" aria-invalid="@error('amount') true @else false @enderror" @error('amount') aria-describedby="amount-error" @enderror />
        @error('amount')<p class="err" id="amount-error">{{ $message }}</p>@enderror
      </div>

      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="bank_name">Bank you sent from (optional)</label>
          <input id="bank_name" name="bank_name" maxlength="120" value="{{ old('bank_name') }}" />
          @error('bank_name')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="reference">Transaction reference (optional)</label>
          <input id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" />
          @error('reference')<p class="err">{{ $message }}</p>@enderror
        </div>
      </div>

      <div class="field">
        <label for="evidence">Evidence of payment</label>
        <input id="evidence" name="evidence" type="file" accept="image/png,image/jpeg,image/webp,.pdf" required aria-invalid="@error('evidence') true @else false @enderror" @error('evidence') aria-describedby="evidence-error" @enderror />
        <p class="hint">A screenshot or photo of your transfer receipt, or a PDF bank statement.</p>
        @error('evidence')<p class="err" id="evidence-error">{{ $message }}</p>@enderror
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

      {{-- Installment Payment Plans (2026-07-18): which fee items allow
           installments, and what those installments are, so the picker
           below only appears, and only offers real options, for a fee
           item that actually has any. --}}
      var feeItemInstallments = @json($feeItems->mapWithKeys(fn($item) => [$item->id => $item->installments->map(fn($i) => ['id' => $i->id, 'label' => $i->label, 'amount' => (float) $i->amount])]));
      var feeItemSelect = document.getElementById('fee_item_id');
      var installmentField = document.getElementById('installmentField');
      var installmentSelect = document.getElementById('fee_item_installment_id');
      function refreshInstallments(){
        var installments = feeItemInstallments[feeItemSelect.value] || [];
        installmentSelect.innerHTML = '<option value="">Full amount / not tied to one installment</option>';
        installments.forEach(function(inst){
          var opt = document.createElement('option');
          opt.value = inst.id;
          opt.textContent = inst.label + ' (₦' + inst.amount.toLocaleString(undefined, {minimumFractionDigits:2}) + ')';
          installmentSelect.appendChild(opt);
        });
        installmentField.style.display = installments.length ? '' : 'none';
        if (window.SkulosSearchable) window.SkulosSearchable.enhanceAll(installmentSelect.parentNode);
      }
      feeItemSelect?.addEventListener('change', refreshInstallments);
      refreshInstallments();

      {{-- A fee item may only apply to certain classes (user request
           2026-07-25): once a student is picked, hide any fee item option
           that does not apply to that student's own class (a blank
           data-classes means "every class", always shown). The already
           selected fee item is reset if it no longer applies, so the
           form can never silently submit a fee item for the wrong class. --}}
      var studentSelect = document.getElementById('student_id');
      var feeItemClassHint = document.getElementById('feeItemClassHint');
      function refreshFeeItemsForStudent(){
        var chosen = studentSelect.options[studentSelect.selectedIndex];
        var classId = chosen ? chosen.dataset.classId : '';
        var anyHidden = false;
        Array.prototype.forEach.call(feeItemSelect.options, function(opt){
          if (! opt.value) return; // "General payment"
          var classes = (opt.dataset.classes || '').split(',').filter(Boolean);
          var applies = classes.length === 0 || (classId && classes.indexOf(classId) !== -1);
          opt.hidden = ! applies;
          if (! applies) {
            anyHidden = true;
            if (opt.selected) feeItemSelect.value = '';
          }
        });
        feeItemClassHint.style.display = classId && anyHidden ? '' : 'none';
        if (window.SkulosSearchable) window.SkulosSearchable.enhanceAll(feeItemSelect.parentNode);
        refreshInstallments();
      }
      studentSelect?.addEventListener('change', refreshFeeItemsForStudent);
      refreshFeeItemsForStudent();
    </script>
  @endif
</div>
@endsection
