@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Fee Records'])
@section('title', 'Fee Records')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Fee Records</h1>
    <p class="subtitle">The fee catalog payments are matched against, per term.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.payments.index', ['school' => $school->slug]) }}">Payments</a>
</div>

{{--
  The whole fee schedule at a glance, fee item names as rows and classes
  as columns (user request 2026-07-25: "the first section should show
  the schools fees in a tabular form for all classes first... this only
  shows if the school admin has uploaded, set or entered the fee
  items"). Scoped to one term at a time via the switcher below (user
  report 2026-07-26: "the terms are all showing on the page instead of
  have the switch a term function"), and optionally to one section's
  classes (user request 2026-07-26: "make it possible to view the fees
  per section... under the selected term"). Read only, editing still
  happens on the per fee item table/forms further down this same page.
--}}
@if(count($terms))
  <div class="card" style="margin-bottom:1.1rem">
    <div style="display:flex;flex-wrap:wrap;gap:1.2rem;align-items:flex-end">
      <form method="GET" style="max-width:18rem">
        @if($sectionId)<input type="hidden" name="section_id" value="{{ $sectionId }}" />@endif
        <div class="field" style="margin:0">
          <label for="schedule_term_id">Viewing term</label>
          <select id="schedule_term_id" name="term_id" onchange="this.form.submit()">
            @foreach($terms as $term)
              <option value="{{ $term->id }}" {{ $scheduleTerm && (int) $scheduleTerm->id === (int) $term->id ? 'selected' : '' }}>{{ $term->name }} ({{ $term->session?->name }})</option>
            @endforeach
          </select>
        </div>
      </form>
      <form method="GET" style="max-width:16rem">
        @if($scheduleTerm)<input type="hidden" name="term_id" value="{{ $scheduleTerm->id }}" />@endif
        <div class="field" style="margin:0">
          <label for="schedule_section_id">Section</label>
          <select id="schedule_section_id" name="section_id" onchange="this.form.submit()">
            <option value="">All sections</option>
            @foreach($sections as $section)
              <option value="{{ $section->id }}" @selected($sectionId === $section->id)>{{ $section->name }}</option>
            @endforeach
          </select>
        </div>
      </form>
    </div>
  </div>
@endif

@if($feeSchedule)
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.6rem">{{ $scheduleTerm->name }} fee schedule</h3>
    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>Fee item</th>
          @foreach($scheduleClasses as $class)
            <th style="text-align:right;white-space:nowrap">{{ $class->name }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($feeSchedule['rows'] as $row)
          <tr>
            <td style="white-space:nowrap">
              <strong>{{ $row['name'] }}</strong>
              @if($row['isOptional'])<span class="badge" style="margin-left:.4rem">Optional</span>@endif
            </td>
            @foreach($scheduleClasses as $class)
              <td class="muted" style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap">{{ $row['amounts'][$class->id] ? '₦'.$row['amounts'][$class->id] : 'Not set' }}</td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--line)">
          <td><strong>Total (including optional items)</strong></td>
          @foreach($scheduleClasses as $class)
            <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap"><strong>₦{{ number_format($feeSchedule['totals']['including'][$class->id] ?? 0, 2) }}</strong></td>
          @endforeach
        </tr>
        <tr>
          <td><strong>Total (excluding optional items)</strong></td>
          @foreach($scheduleClasses as $class)
            <td style="text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap"><strong>₦{{ number_format($feeSchedule['totals']['excluding'][$class->id] ?? 0, 2) }}</strong></td>
          @endforeach
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
@elseif($scheduleTerm)
  <div class="card" style="margin-bottom:1.1rem">
    <p class="muted">No fee items set up yet for {{ $scheduleTerm->name }}{{ $sectionId ? ' in this section' : '' }}.</p>
  </div>
@endif

@if($canManage)
  {{--
    Fee approval chain (user request 2026-07-25: "admin can set an
    approval level for school fees which the school owner, principal,
    vice principal, head teacher, assistant head teacher or any other
    name from the staff list, search option works here"). Off by
    default: a school that never opens this card keeps every fee item
    immediately live exactly as before. Every <select> in this app is
    searchable automatically once it has enough options (window.
    SkulosSearchable, applied on page load), so the plain staff options
    below are already the "search option" the request asked for.
  --}}
  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.4rem">Fee approval</h3>
    <p class="muted" style="margin-bottom:.8rem">Require someone to approve a new fee item before parents can see it.</p>
    <form method="POST" action="{{ route('school.finance.fee-items.approval-settings', ['school' => $school->slug]) }}">
      @csrf
      <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem">
        <input type="checkbox" name="enabled" value="1" @checked($feeApprovalEnabled) />
        <span>Require approval before a new fee item is visible to parents</span>
      </label>
      <div class="field">
        <label>Approvers</label>
        <p class="hint" style="margin:0 0 .5rem">Anyone selected here can approve or reject. Pick a role, a specific staff member, or both.</p>
        @for($i = 0; $i < 5; $i++)
          <select name="chain[]" style="margin-bottom:.5rem">
            <option value="">Not used</option>
            <optgroup label="Role">
              @foreach($feeApprovalRoles as $slug => $label)
                <option value="role:{{ $slug }}" @selected(($feeApprovalChain[$i] ?? null) === "role:{$slug}")>{{ $label }}</option>
              @endforeach
            </optgroup>
            <optgroup label="Specific staff member">
              @foreach($staffForApproval as $staff)
                <option value="staff:{{ $staff->id }}" @selected(($feeApprovalChain[$i] ?? null) === "staff:{$staff->id}")>{{ $staff->fullName() }}</option>
              @endforeach
            </optgroup>
          </select>
        @endfor
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save fee approval settings</button>
    </form>
  </div>

  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.6rem">Add a fee item</h3>
    <form method="POST" action="{{ route('school.finance.fee-items.store', ['school' => $school->slug]) }}">
      @csrf
      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" required maxlength="120" placeholder="e.g. Tuition" value="{{ old('name') }}" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="name-error" @enderror />
          @error('name')<p class="err" id="name-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="amount">Amount (₦)</label>
          <input id="amount" name="amount" type="number" step="0.01" min="0" required value="{{ old('amount') }}" aria-invalid="@error('amount') true @else false @enderror" @error('amount') aria-describedby="amount-error" @enderror />
          @error('amount')<p class="err" id="amount-error">{{ $message }}</p>@enderror
        </div>
      </div>
      <div class="field">
        <label for="term_id">Term</label>
        <select id="term_id" name="term_id" required aria-invalid="@error('term_id') true @else false @enderror" @error('term_id') aria-describedby="term_id-error" @enderror>
          <option value="">Select a term</option>
          @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected(old('term_id') == $term->id)>{{ $term->name }} ({{ $term->session?->name }})</option>
          @endforeach
          {{--
            The upcoming session's First Term, offered automatically once
            the school is in the last term of its current session (user
            request 2026-07-25), not a real Term row yet, resolved into
            one (created on the spot) only if this option is actually
            picked and saved, see FeeItemController::resolveTermId().
          --}}
          @if($upcomingTerm)
            <option value="{{ $upcomingTerm['value'] }}" @selected(old('term_id') === $upcomingTerm['value'])>{{ $upcomingTerm['label'] }}</option>
          @endif
        </select>
        @error('term_id')<p class="err" id="term_id-error">{{ $message }}</p>@enderror
      </div>
      @include('school.finance.fee-items._class-checkboxes', ['selectedClassIds' => old('class_ids', [])])
      <label style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
        <input type="checkbox" name="allow_installments" value="1" @checked(old('allow_installments')) />
        <span>Allow this fee to be paid in installments</span>
      </label>
      <label style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
        <input type="checkbox" name="is_optional" value="1" @checked(old('is_optional')) />
        <span>This item is optional (e.g. uniform, sportswear), excluded from the "excluding optional items" total</span>
      </label>
      <button class="btn btn-primary btn-sm" type="submit">Add fee item</button>
    </form>
  </div>

  {{--
    Bulk way to set up a whole fee schedule at once (user request
    2026-07-25: "make the fee items that one can upload as a bulk file,
    add a downloadable csv too... alternatively, the school can decide to
    upload a file that will act as the school fees"). Additive, not a
    wholesale replace, matching every other bulk import in this app.
  --}}
  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.4rem">Bulk add fee items</h3>
    <p class="muted" style="margin-bottom:.8rem">Download the template, fill in as many fee items as you like (one per row, "classes" left blank applies to the whole school), then upload it back.</p>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
      <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.template', ['school' => $school->slug]) }}">Download template</a>
      <form method="POST" action="{{ route('school.finance.fee-items.bulk-upload', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
        @csrf
        <input type="file" name="file" accept=".csv,text/csv" required />
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
      </form>
    </div>
  </div>
@endif

{{--
  School's own receiving bank account(s) (user request 2026-07-25:
  "School admin sets the school account options for payment which
  parents sees, a school can add more than one account details and the
  parent sees all"). Shown to a parent on the Submit Payment screen,
  purely informational, never itself validated against a Payment.
--}}
<div class="card" style="margin-bottom:1.1rem">
  <div class="row-between" style="margin-bottom:.6rem">
    <h3>Bank accounts</h3>
    <span class="muted">Shown to parents on the Submit Payment screen so they know where to send a bank transfer.</span>
  </div>

  @if($canManage)
    <form method="POST" action="{{ route('school.finance.bank-accounts.store', ['school' => $school->slug]) }}" class="grid cols-3" style="gap:1rem;margin-bottom:1.1rem;align-items:end">
      @csrf
      <div class="field" style="margin:0">
        <label for="bank_name">Bank name</label>
        <input id="bank_name" name="bank_name" required maxlength="120" placeholder="e.g. First Bank" />
      </div>
      <div class="field" style="margin:0">
        <label for="account_name">Account name</label>
        <input id="account_name" name="account_name" required maxlength="120" />
      </div>
      <div class="field" style="margin:0;display:flex;gap:.5rem;align-items:end">
        <div style="flex:1">
          <label for="account_number">Account number</label>
          <input id="account_number" name="account_number" required maxlength="40" />
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add</button>
      </div>
    </form>
  @endif

  @if($bankAccounts->isEmpty())
    <p class="muted">No bank account on file yet. Parents will not see a receiving account when submitting a payment.</p>
  @else
    @if($canManage)
      <form id="bulkBankAccountsForm" method="POST" action="{{ route('school.finance.bank-accounts.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="bank-account-select-all" aria-label="Select all bank accounts" /></th>@endif
        <th>Bank</th><th>Account name</th><th>Account number</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($bankAccounts as $account)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkBankAccountsForm" class="bank-account-row-check" name="bank_account_ids[]" value="{{ $account->id }}" aria-label="Select {{ $account->bank_name }}" /></td>
            @endif
            <td><strong>{{ $account->bank_name }}</strong></td>
            <td>{{ $account->account_name }}</td>
            <td class="muted">{{ $account->account_number }}</td>
            <td><span class="badge {{ $account->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $account->is_active ? 'Visible to parents' : 'Hidden' }}</span></td>
            <td style="white-space:nowrap">
              @if($canManage)
                <form method="POST" action="{{ route('school.finance.bank-accounts.toggle-active', ['school' => $school->slug, 'bankAccountId' => $account->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $account->is_active ? 'Hide' : 'Show' }}</button>
                </form>
                <form method="POST" action="{{ route('school.finance.bank-accounts.destroy', ['school' => $school->slug, 'bankAccountId' => $account->id]) }}" style="display:inline" onsubmit="return confirm('Remove this bank account?')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of bank accounts above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkBankAccountsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Show</button>
          <button form="bulkBankAccountsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="retire">Hide</button>
          <button form="bulkBankAccountsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected bank accounts?')">Delete selected</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('bank-account-select-all');
          var boxes = document.querySelectorAll('.bank-account-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkBankAccountsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one bank account first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>

{{--
  User report 2026-07-26: "parents should not be seeing the individual
  breakdown of the fees like this, just fee schedule is okay to be
  seen." The per-fee-item list (term, classes, installments, status)
  further identifies and manages each fee item individually, real
  finance-office detail a parent does not need; the schedule matrix
  above already gives them everything they actually need to know about
  what they owe. Restricted to the same $canManage (finance.update)
  tier already gating the edit/bulk-action controls inside this table,
  not just those controls, so a parent (finance.view/create only) never
  reaches this section at all.
--}}
@if($canManage)
<div class="card">
  @if($feeItems->isEmpty())
    <p class="muted">No fee items yet.</p>
  @else
    @if($canManage)
      <form id="bulkFeeItemsForm" method="POST" action="{{ route('school.finance.fee-items.bulk-action', ['school' => $school->slug]) }}"></form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="fee-item-select-all" aria-label="Select all fee items" /></th>@endif
        <th>Name</th><th>Term</th><th>Amount</th><th>Classes</th><th>Installments</th><th>Status</th>
        @if($feeApprovalEnabled)<th>Approval</th>@endif
        <th></th></tr></thead>
      <tbody>
        @foreach($feeItems as $item)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkFeeItemsForm" class="fee-item-row-check" name="fee_item_ids[]" value="{{ $item->id }}" aria-label="Select {{ $item->name }}" /></td>
            @endif
            <td><strong>{{ $item->name }}</strong>@if($item->is_optional)<span class="badge" style="margin-left:.4rem">Optional</span>@endif</td>
            <td class="muted">{{ $item->term?->name }}</td>
            <td>₦{{ number_format((float) $item->amount, 2) }}</td>
            <td class="muted">{{ $item->schoolClasses->isEmpty() ? 'Every class' : $item->schoolClasses->pluck('name')->join(', ') }}</td>
            <td class="muted">{{ $item->allow_installments ? 'Allowed' : 'Full amount only' }}</td>
            <td><span class="badge {{ $item->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $item->is_active ? 'Active' : 'Retired' }}</span></td>
            @if($feeApprovalEnabled)
              <td>
                @if($item->approval_status === 'pending')
                  <span class="badge badge-warn">Pending</span>
                @elseif($item->approval_status === 'rejected')
                  <span class="badge" style="background:color-mix(in srgb,var(--bad) 18%,transparent);color:color-mix(in srgb,var(--bad) 55%,black)">Rejected</span>
                @else
                  <span class="badge badge-ok">Approved</span>
                @endif
              </td>
            @endif
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.show', ['school' => $school->slug, 'feeItemId' => $item->id]) }}">{{ $canManage ? 'Manage' : 'View' }}</a>
              @if($isEligibleApprover && $item->approval_status === 'pending')
                <form method="POST" action="{{ route('school.finance.fee-items.approve', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Approve</button>
                </form>
                <form method="POST" action="{{ route('school.finance.fee-items.reject', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Reject</button>
                </form>
              @endif
              @if($canManage)
                <form method="POST" action="{{ route('school.finance.fee-items.toggle-active', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $item->is_active ? 'Retire' : 'Reactivate' }}</button>
                </form>
                <form method="POST" action="{{ route('school.finance.fee-items.destroy', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline" onsubmit="return confirm('Remove this fee item?')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of fee items above, then choose an action.</span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button form="bulkFeeItemsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Reactivate</button>
          <button form="bulkFeeItemsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="retire">Retire</button>
          <button form="bulkFeeItemsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected fee items? Any with payments recorded against them will be skipped.')">Delete selected</button>
        </div>
      </div>
      <script>
        (function(){
          var all = document.getElementById('fee-item-select-all');
          var boxes = document.querySelectorAll('.fee-item-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkFeeItemsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one fee item first.'); }
          });
        })();
      </script>
    @endif
  @endif
</div>
@endif
@endsection
