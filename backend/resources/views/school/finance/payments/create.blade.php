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
                <input type="checkbox" name="student_ids[]" value="{{ $student->id }}" class="student-checkbox" data-class-id="{{ $student->currentClassArm?->schoolClass?->id }}" data-owed="{{ $balancesByStudent[$student->id]['owed'] ?? 0 }}" data-owed-all="{{ $balancesByStudent[$student->id]['owedAll'] ?? 0 }}" data-paid="{{ $balancesByStudent[$student->id]['paid'] ?? 0 }}" @checked(in_array($student->id, $old))>
                {{ $student->fullName() }} ({{ $student->admission_number }})
              </label>
            @endforeach
          </div>
        @else
          <input type="hidden" name="student_ids[]" value="{{ $students->first()->id }}" class="student-checkbox" checked data-class-id="{{ $students->first()->currentClassArm?->schoolClass?->id }}" data-owed="{{ $balancesByStudent[$students->first()->id]['owed'] ?? 0 }}" data-owed-all="{{ $balancesByStudent[$students->first()->id]['owedAll'] ?? 0 }}" data-paid="{{ $balancesByStudent[$students->first()->id]['paid'] ?? 0 }}">
          <p style="font-weight:700">{{ $students->first()->fullName() }} ({{ $students->first()->admission_number }})</p>
        @endif
        <p class="hint" id="splitHint" style="display:none"></p>
        @error('student_ids')<p class="err">{{ $message }}</p>@enderror
        @error('student_ids.*')<p class="err">{{ $message }}</p>@enderror
      </div>

      <div class="field">
        <label for="fee_item_id_display">What is this for (optional)</label>
        <select id="fee_item_id_display">
          <option value="">General payment</option>
          {{--
            "All Fees" payment plan (user request 2026-07-26): "add an
            item of all fees... based on the child or children selected,
            ask if they want full or partial payment... calculate the
            total amount to be paid." Two variants (user report
            2026-07-26: "you did not add all fees, optional items
            included") since an optional item is still something a
            parent may choose not to pay, so non-optional-only stays its
            own choice rather than being replaced.
          --}}
          <option value="all_fees">All Fees (non-optional items)</option>
          <option value="all_fees_incl_optional">All Fees (including optional items)</option>
          {{--
            Grouped by name (user report 2026-07-26: "looking through all
            the list is too clumsy... it is still showing school uniform
            for three childs, wrong approach"): a fee item scoped to
            certain classes often exists as several same-named rows at
            different prices, one per class. Shown here as one option per
            distinct name; resolveVariant() in the script below picks the
            right underlying fee item once it knows the selected child's
            class.
          --}}
          @foreach($feeItemGroups as $i => $group)
            @php($universal = collect($group['variants'])->contains(fn ($v) => empty($v['classIds'])))
            @php($classIds = $universal ? '' : collect($group['variants'])->pluck('classIds')->flatten()->unique()->join(','))
            {{-- A single-variant group (most fee items, they usually apply
                 to every class) still shows its price right in the list,
                 same convenience as before; a multi-variant group's price
                 varies by class, so it is resolved once the child is
                 known instead (the amount hint below the picker). --}}
            <option value="group:{{ $i }}" data-classes="{{ $classIds }}" @selected($oldFeeItemSelectValue === "group:{$i}")>{{ $group['name'] }}@if(count($group['variants']) === 1) &middot; ₦{{ number_format($group['variants'][0]['amount'], 2) }} @endif</option>
          @endforeach
        </select>
        <input type="hidden" name="fee_item_id" id="fee_item_id" value="{{ in_array($oldFeeItemSelectValue, ['all_fees', 'all_fees_incl_optional'], true) ? $oldFeeItemSelectValue : old('fee_item_id') }}">
        <p class="hint" id="feeItemClassHint" style="display:none">Showing only fees that apply to this student's class.</p>
        <p class="hint" id="feeItemAmountHint" style="display:none"></p>
        @if($term)<p class="hint">Showing fees for {{ $term->name }}.</p>@endif
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

      {{-- Fee item groups (user report 2026-07-26): one entry in
           feeItemGroups per distinct name, each with every per-class
           variant (id/amount/classIds/installments) that shares that
           name. resolveVariant() picks the right one once the selected
           child's class is known: an exact class match wins, then a
           variant that applies to every class (empty classIds), then
           whatever variant comes first. --}}
      var feeItemGroups = @json($feeItemGroups);
      var feeItemSelect = document.getElementById('fee_item_id_display');
      var feeItemHidden = document.getElementById('fee_item_id');
      var installmentField = document.getElementById('installmentField');
      var installmentSelect = document.getElementById('fee_item_installment_id');
      var feeItemAmountHint = document.getElementById('feeItemAmountHint');

      function resolveVariant(groupIndex, classId){
        var group = feeItemGroups[groupIndex];
        if (! group) return null;
        var variants = group.variants;
        if (classId) {
          var exact = variants.filter(function(v){ return v.classIds.indexOf(parseInt(classId, 10)) !== -1; })[0];
          if (exact) return exact;
        }
        var universal = variants.filter(function(v){ return v.classIds.length === 0; })[0];
        return universal || variants[0] || null;
      }

      function currentSelectedClassId(){
        var classIds = selectedStudentEls().map(function(el){ return el.dataset.classId; }).filter(Boolean);
        var unique = classIds.filter(function(v, i, a){ return a.indexOf(v) === i; });
        // Several children in different classes have no single class to
        // scope by.
        return unique.length === 1 ? unique[0] : '';
      }

      function refreshInstallments(){
        installmentSelect.innerHTML = '<option value="">Full amount / not tied to one installment</option>';
        var installments = [];
        if (feeItemSelect.value.indexOf('group:') === 0) {
          var variant = resolveVariant(parseInt(feeItemSelect.value.split(':')[1], 10), currentSelectedClassId());
          installments = variant ? variant.installments : [];
        }
        installments.forEach(function(inst){
          var opt = document.createElement('option');
          opt.value = inst.id;
          opt.textContent = inst.label + ' (₦' + inst.amount.toLocaleString(undefined, {minimumFractionDigits:2}) + ')';
          installmentSelect.appendChild(opt);
        });
        installmentField.style.display = installments.length ? '' : 'none';
        if (window.SkulosSearchable) window.SkulosSearchable.enhanceAll(installmentSelect.parentNode);
      }

      {{-- Whether the currently selected fee item group actually prices
           the currently ticked children differently (real bug found live
           2026-07-27: 3 children in 3 different classes selected for
           "School Uniform (Optional)", a class priced item with no
           universal variant; resolveVariant() with no single shared
           class id falls back to an arbitrary variant, one price got
           applied to everyone and then divided evenly, silently wrong
           for at least 2 of the 3 children, and a payment record would
           have been saved against the wrong class's fee item entirely
           since only one fee_item_id is ever submitted per payment). --}}
      function selectedVariantPrices(groupIndex){
        return selectedStudentEls().map(function(el){
          var variant = resolveVariant(groupIndex, el.dataset.classId);
          return variant ? variant.amount : null;
        });
      }

      {{-- Resolves the group option actually selected down to a real fee
           item id in the hidden field the form submits, and auto fills
           the amount so picking a specific fee (not an All Fees plan)
           shows its price without the parent having to look it up. --}}
      function refreshFeeItemHidden(){
        var val = feeItemSelect.value;
        if (val.indexOf('group:') === 0) {
          var groupIndex = parseInt(val.split(':')[1], 10);
          var variant = resolveVariant(groupIndex, currentSelectedClassId());
          feeItemHidden.value = variant ? String(variant.id) : '';
          var prices = selectedVariantPrices(groupIndex);
          var pricesVary = prices.some(function(p){ return p !== null; }) && prices.some(function(p){ return p !== prices[0]; });
          if (pricesVary) {
            // No single fee item id can correctly represent this
            // selection, a payment can only ever be saved against one
            // fee item; block it rather than silently save the wrong
            // price/item against some of the children.
            feeItemHidden.value = '';
            feeItemAmountHint.textContent = 'This fee costs a different amount for the selected children\'s classes. Submit one payment per class, or use "All Fees" instead.';
            feeItemAmountHint.style.display = '';
          } else if (variant) {
            feeItemAmountHint.textContent = 'Amount for this fee: ₦' + variant.amount.toLocaleString(undefined, {minimumFractionDigits: 2});
            feeItemAmountHint.style.display = '';
            amountInput.value = variant.amount.toFixed(2);
          } else {
            feeItemAmountHint.style.display = 'none';
          }
          refreshSplitHint();
        } else {
          feeItemHidden.value = val;
          feeItemAmountHint.style.display = 'none';
        }
      }

      {{-- A fee item may only apply to certain classes (user request
           2026-07-25): once a student is picked, hide any group option
           none of whose variants apply to that student's own class (a
           blank data-classes means at least one variant applies to
           every class, always shown). The already selected option is
           reset if it no longer applies, so the form can never silently
           submit a fee item for the wrong class. --}}
      var studentEls = Array.prototype.slice.call(document.querySelectorAll('.student-checkbox'));
      var feeItemClassHint = document.getElementById('feeItemClassHint');
      function selectedStudentEls(){
        return studentEls.filter(function(el){ return el.type !== 'checkbox' || el.checked; });
      }
      function refreshFeeItemsForStudents(){
        var classId = currentSelectedClassId();
        var anyHidden = false;
        Array.prototype.forEach.call(feeItemSelect.options, function(opt){
          if (! opt.value || opt.value === 'all_fees' || opt.value === 'all_fees_incl_optional') return;
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
        refreshFeeItemHidden();
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
        var val = feeItemSelect.value;
        // The amount hint above already carries the "different price per
        // class, submit separately" warning in this case, showing "split
        // evenly" underneath it would directly contradict that warning.
        var blockedByVaryingPrice = val.indexOf('group:') === 0 && ! feeItemHidden.value;
        if (count > 1 && amount > 0 && ! blockedByVaryingPrice) {
          var share = amount / count;
          splitHint.textContent = 'This will be split evenly: ₦' + share.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' each for ' + count + ' children.';
          splitHint.style.display = '';
        } else {
          splitHint.style.display = 'none';
        }
      }

      var amountInput = document.getElementById('amount');

      {{-- All Fees payment plan (user request 2026-07-26): full or
           partial payment against the combined total for whichever
           children are ticked, non-optional only or including optional
           items depending on which variant is picked, with a live
           balance so a parent who already paid something sees what is
           actually left. --}}
      var allFeesPlan = document.getElementById('allFeesPlan');
      var allFeesSummary = document.getElementById('allFeesSummary');
      var fullPaymentLabel = document.getElementById('fullPaymentLabel');
      var percentRow = document.getElementById('percentRow');

      function selectedBalanceTotals(inclOptional){
        // Balance is the SUM of each child's own clamped balance, never
        // a single clamp on the combined totals (real bug found live
        // 2026-07-27: a child who has already paid more than they owe
        // was letting that surplus silently cover a sibling's separate
        // unpaid balance, "Balance: 61,500" instead of the real
        // 190,000 owed across the family once the two other children's
        // balances are counted properly, matching the same per student
        // clamp FeeBalanceService::outstandingForGuardian() and the
        // parent dashboard's own Fees Due figure already use).
        var owed = 0, paid = 0, balance = 0;
        selectedStudentEls().forEach(function(el){
          var childOwed = parseFloat((inclOptional ? el.dataset.owedAll : el.dataset.owed) || '0');
          var childPaid = parseFloat(el.dataset.paid || '0');
          owed += childOwed;
          paid += childPaid;
          balance += Math.max(0, childOwed - childPaid);
        });

        return {owed: owed, paid: paid, balance: balance};
      }

      function planMode(){
        var checked = document.querySelector('input[name="paymentPlanMode"]:checked');

        return checked ? checked.value : 'full';
      }

      function applyPaymentPlanMode(){
        var totals = selectedBalanceTotals(feeItemSelect.value === 'all_fees_incl_optional');
        percentRow.style.display = planMode() === 'partial' ? 'flex' : 'none';
        if (planMode() === 'full') {
          amountInput.value = totals.balance.toFixed(2);
          refreshSplitHint();
        }
      }

      function refreshAllFeesPlan(){
        var val = feeItemSelect.value;
        if (val !== 'all_fees' && val !== 'all_fees_incl_optional') {
          allFeesPlan.style.display = 'none';
          return;
        }
        allFeesPlan.style.display = '';
        var inclOptional = val === 'all_fees_incl_optional';
        var totals = selectedBalanceTotals(inclOptional);
        fullPaymentLabel.textContent = totals.paid > 0 ? 'Complete Payment' : 'Full Payment';
        var parts = [(inclOptional ? 'Fees owed, including optional: ₦' : 'Non optional fees owed: ₦') + totals.owed.toLocaleString(undefined, {minimumFractionDigits: 2})];
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
          var totals = selectedBalanceTotals(feeItemSelect.value === 'all_fees_incl_optional');
          var pct = parseFloat(btn.dataset.percent);
          amountInput.value = (totals.balance * pct / 100).toFixed(2);
          refreshSplitHint();
        });
      });
      feeItemSelect?.addEventListener('change', function(){ refreshFeeItemHidden(); refreshInstallments(); refreshAllFeesPlan(); });

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
