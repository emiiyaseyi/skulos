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
        <label>{{ $students->count() > 1 ? 'Student(s)' : 'Student' }}</label>
        @if($students->count() > 1)
          {{--
            User request 2026-07-26: "for parents with more than one
            child, add the option of check box to select in case the
            parent want the payment to cover more than one child." One
            evidence upload can now cover several children at once, the
            amount below is split evenly across whichever are checked.
          --}}
          <p class="hint" style="margin-bottom:.4rem">Tick every child this payment covers. One payment can cover more than one child.</p>
          <div class="stack" style="gap:.4rem;border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem">
            @foreach($students as $student)
              @php($old = old('student_ids', []))
              <label style="display:flex;align-items:center;gap:.5rem;font-weight:600">
                <input type="checkbox" name="student_ids[]" value="{{ $student->id }}" class="student-checkbox" data-class-id="{{ $student->currentClassArm?->schoolClass?->id }}" data-owed="{{ $balancesByStudent[$student->id]['owed'] ?? 0 }}" data-paid="{{ $balancesByStudent[$student->id]['paid'] ?? 0 }}" @checked(in_array($student->id, $old))>
                {{ $student->fullName() }} ({{ $student->admission_number }})
              </label>
            @endforeach
          </div>
        @else
          <input type="hidden" name="student_ids[]" value="{{ $students->first()->id }}" class="student-checkbox" checked data-class-id="{{ $students->first()->currentClassArm?->schoolClass?->id }}" data-owed="{{ $balancesByStudent[$students->first()->id]['owed'] ?? 0 }}" data-paid="{{ $balancesByStudent[$students->first()->id]['paid'] ?? 0 }}">
          <p style="font-weight:700">{{ $students->first()->fullName() }} ({{ $students->first()->admission_number }})</p>
        @endif
        <p class="hint" id="splitHint" style="display:none"></p>
        @error('student_ids')<p class="err">{{ $message }}</p>@enderror
        @error('student_ids.*')<p class="err">{{ $message }}</p>@enderror
      </div>

      <div class="field">
        <label for="fee_item_id">What is this for (optional)</label>
        <select id="fee_item_id" name="fee_item_id">
          <option value="">General payment</option>
          {{--
            "All Fees" payment plan (user request 2026-07-26): "add an
            item of all fees... based on the child or children selected,
            ask if they want full or partial payment, this is only for
            non optional items, then calculate the total amount to be
            paid." Picking this reveals the plan below instead of a
            single fee item's own amount.
          --}}
          <option value="all_fees">All Fees (non-optional items)</option>
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

      {{--
        All Fees payment plan (user request 2026-07-26): full or partial
        payment against the combined non-optional total for whichever
        children are ticked above, with a live balance so a parent who
        already paid something sees what is actually left, not the
        original full amount again.
      --}}
      <div class="field" id="allFeesPlan" style="display:none;border:1px solid var(--line);border-radius:10px;padding:.8rem .9rem">
        <label style="margin-bottom:.3rem">Payment plan</label>
        <p id="allFeesSummary" class="muted" style="font-size:.85rem;margin-bottom:.6rem"></p>
        <div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-bottom:.6rem">
          <label style="display:flex;align-items:center;gap:.4rem;font-weight:600">
            <input type="radio" name="paymentPlanMode" value="full" checked>
            <span id="fullPaymentLabel">Full Payment</span>
          </label>
          <label style="display:flex;align-items:center;gap:.4rem;font-weight:600">
            <input type="radio" name="paymentPlanMode" value="partial">
            Partial Payment
          </label>
        </div>
        <div id="percentRow" style="display:none;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem">
          <button type="button" class="btn btn-ghost btn-sm percent-btn" data-percent="25">25%</button>
          <button type="button" class="btn btn-ghost btn-sm percent-btn" data-percent="50">50%</button>
          <button type="button" class="btn btn-ghost btn-sm percent-btn" data-percent="75">75%</button>
          <button type="button" class="btn btn-ghost btn-sm percent-btn" data-percent="90">90%</button>
        </div>
        <p class="hint">The amount below is filled in for you and can still be edited.</p>
      </div>

      <div class="field">
        <label for="amount">{{ $students->count() > 1 ? 'Total amount paid (₦)' : 'Amount paid (₦)' }}</label>
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
      document.getElementById('paymentForm')?.addEventListener('submit', function(e){
        var btn = document.getElementById('paymentSubmit');
        if (selectedStudentEls().length === 0) {
          e.preventDefault();
          alert('Tick at least one child first.');
          return;
        }
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
      var studentEls = Array.prototype.slice.call(document.querySelectorAll('.student-checkbox'));
      var feeItemClassHint = document.getElementById('feeItemClassHint');
      function selectedStudentEls(){
        return studentEls.filter(function(el){ return el.type !== 'checkbox' || el.checked; });
      }
      function refreshFeeItemsForStudents(){
        var selected = selectedStudentEls();
        var classIds = selected.map(function(el){ return el.dataset.classId; }).filter(Boolean);
        var uniqueClassIds = classIds.filter(function(v, i, a){ return a.indexOf(v) === i; });
        // Several children in different classes have no single class to
        // scope by, so the filter only kicks in when every selected child
        // shares the same class.
        var classId = uniqueClassIds.length === 1 ? uniqueClassIds[0] : '';
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

      {{-- Splitting one payment across more than one child (user request
           2026-07-26): show the parent exactly what each child's share
           will be before they submit, so the even split is never a
           surprise. --}}
      var splitHint = document.getElementById('splitHint');
      function refreshSplitHint(){
        var count = selectedStudentEls().length;
        var amount = parseFloat(amountInput.value);
        if (count > 1 && amount > 0) {
          var share = amount / count;
          splitHint.textContent = 'This will be split evenly: ₦' + share.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' each for ' + count + ' children.';
          splitHint.style.display = '';
        } else {
          splitHint.style.display = 'none';
        }
      }

      var amountInput = document.getElementById('amount');

      {{-- All Fees payment plan (user request 2026-07-26): full or
           partial payment against the combined non-optional total for
           whichever children are ticked, with a live balance so a parent
           who already paid something sees what is actually left. --}}
      var allFeesPlan = document.getElementById('allFeesPlan');
      var allFeesSummary = document.getElementById('allFeesSummary');
      var fullPaymentLabel = document.getElementById('fullPaymentLabel');
      var percentRow = document.getElementById('percentRow');

      function selectedBalanceTotals(){
        var owed = 0, paid = 0;
        selectedStudentEls().forEach(function(el){
          owed += parseFloat(el.dataset.owed || '0');
          paid += parseFloat(el.dataset.paid || '0');
        });

        return {owed: owed, paid: paid, balance: Math.max(0, owed - paid)};
      }

      function planMode(){
        var checked = document.querySelector('input[name="paymentPlanMode"]:checked');

        return checked ? checked.value : 'full';
      }

      function applyPaymentPlanMode(){
        var totals = selectedBalanceTotals();
        percentRow.style.display = planMode() === 'partial' ? 'flex' : 'none';
        if (planMode() === 'full') {
          amountInput.value = totals.balance.toFixed(2);
          refreshSplitHint();
        }
      }

      function refreshAllFeesPlan(){
        if (feeItemSelect.value !== 'all_fees') {
          allFeesPlan.style.display = 'none';
          return;
        }
        allFeesPlan.style.display = '';
        var totals = selectedBalanceTotals();
        fullPaymentLabel.textContent = totals.paid > 0 ? 'Complete Payment' : 'Full Payment';
        var parts = ['Non optional fees owed: ₦' + totals.owed.toLocaleString(undefined, {minimumFractionDigits: 2})];
        if (totals.paid > 0) {
          parts.push('Already paid: ₦' + totals.paid.toLocaleString(undefined, {minimumFractionDigits: 2}));
        }
        parts.push('Balance: ₦' + totals.balance.toLocaleString(undefined, {minimumFractionDigits: 2}));
        allFeesSummary.textContent = parts.join(' · ');
        applyPaymentPlanMode();
      }

      document.querySelectorAll('input[name="paymentPlanMode"]').forEach(function(radio){
        radio.addEventListener('change', applyPaymentPlanMode);
      });
      document.querySelectorAll('.percent-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var totals = selectedBalanceTotals();
          var pct = parseFloat(btn.dataset.percent);
          amountInput.value = (totals.balance * pct / 100).toFixed(2);
          refreshSplitHint();
        });
      });
      feeItemSelect?.addEventListener('change', refreshAllFeesPlan);

      studentEls.forEach(function(el){
        el.addEventListener('change', function(){ refreshFeeItemsForStudents(); refreshSplitHint(); refreshAllFeesPlan(); });
      });
      amountInput?.addEventListener('input', refreshSplitHint);
      refreshFeeItemsForStudents();
      refreshSplitHint();
      refreshAllFeesPlan();
    </script>
  @endif
</div>
@endsection
