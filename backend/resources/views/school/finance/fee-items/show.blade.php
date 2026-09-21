@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => $feeItem->name])
@section('title', $feeItem->name)

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $feeItem->name }}</h1>
    <p class="subtitle">{{ $feeItem->term?->name }} &middot; ₦{{ number_format((float) $feeItem->amount, 2) }}</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.index', ['school' => $school->slug]) }}">Back to Fee Records</a>
</div>

@if($canManage)
  <div class="card" style="margin-bottom:1.1rem;max-width:40rem">
    <h3 style="margin-bottom:.6rem">Edit fee item</h3>
    <form method="POST" action="{{ route('school.finance.fee-items.update', ['school' => $school->slug, 'feeItemId' => $feeItem->id]) }}">
      @csrf
      <div class="grid cols-2" style="gap:1rem">
        <div class="field">
          <label for="name">Name</label>
          <input id="name" name="name" required maxlength="120" value="{{ old('name', $feeItem->name) }}" />
        </div>
        <div class="field">
          <label for="amount">Amount (₦)</label>
          <input id="amount" name="amount" type="number" step="0.01" min="0" required value="{{ old('amount', $feeItem->amount) }}" />
        </div>
      </div>
      <div class="field">
        <label for="term_id">{{ \App\Support\TermLabel::current() }}</label>
        <select id="term_id" name="term_id" required>
          @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected(old('term_id', $feeItem->term_id) == $term->id)>{{ $term->name }} ({{ $term->session?->name }})</option>
          @endforeach
        </select>
      </div>
      @include('school.finance.fee-items._class-checkboxes', ['selectedClassIds' => old('class_ids', $feeItem->schoolClasses->pluck('id')->all())])
      <label style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
        <input type="checkbox" name="allow_installments" value="1" @checked(old('allow_installments', $feeItem->allow_installments)) />
        <span>Allow this fee to be paid in installments</span>
      </label>
      <label style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
        <input type="checkbox" name="is_optional" value="1" @checked(old('is_optional', $feeItem->is_optional)) />
        <span>This item is optional (e.g. uniform, sportswear), excluded from the "excluding optional items" total</span>
      </label>
      <button class="btn btn-primary btn-sm" type="submit">Save changes</button>
    </form>
  </div>
@endif

<div class="card">
  <div class="row-between" style="margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem">
    <h3 style="margin:0">Installment schedule</h3>
    @unless($feeItem->allow_installments)
      <span class="muted">Installments are off for this fee item. Turn "Allow this fee to be paid in installments" on above to use this.</span>
    @endunless
  </div>

  @if($feeItem->installments->isEmpty())
    <p class="muted">No installments set up yet.</p>
  @else
    @if($canManage)
      <form id="bulkInstallmentsForm" method="POST" action="{{ route('school.finance.fee-items.installments.bulk-action', ['school' => $school->slug]) }}">@csrf</form>
    @endif
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        @if($canManage)<th style="width:2.2rem"><input type="checkbox" id="installment-select-all" aria-label="Select all installments" /></th>@endif
        <th>Installment</th><th>Amount</th><th>Due</th><th>Collected</th>@if($canManage)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($feeItem->installments as $installment)
          @php($collected = $installment->verifiedTotal())
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkInstallmentsForm" class="installment-row-check" name="installment_ids[]" value="{{ $installment->id }}" aria-label="Select {{ $installment->label }}" /></td>
            @endif
            <td><strong>{{ $installment->label }}</strong></td>
            <td>₦{{ number_format((float) $installment->amount, 2) }}</td>
            <td class="muted">{{ $installment->due_date?->format('d M Y') ?? 'No due date' }}</td>
            <td class="muted">₦{{ number_format($collected, 2) }} of ₦{{ number_format((float) $installment->amount, 2) }}</td>
            @if($canManage)
              <td>
                <form method="POST" action="{{ route('school.finance.fee-items.installments.destroy', ['school' => $school->slug, 'installmentId' => $installment->id]) }}" onsubmit="return confirm('Remove this installment?')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
                </form>
              </td>
            @endif
          </tr>
        @endforeach
        <tr>
          <td colspan="{{ $canManage ? 3 : 2 }}" class="muted" style="text-align:right"><strong>Total collected</strong></td>
          <td colspan="2"><strong>₦{{ number_format($feeItem->installments->sum(fn($i) => $i->verifiedTotal()), 2) }} of ₦{{ number_format((float) $feeItem->installments->sum('amount'), 2) }}</strong></td>
        </tr>
      </tbody>
    </table>
    </div>

    @if($canManage)
      <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
        <span class="muted" style="font-size:.85rem">Tick any number of installments above, then remove them.</span>
        <button form="bulkInstallmentsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" onclick="return confirm('Remove the selected installments? Any with payments recorded against them will be skipped.')">Delete selected</button>
      </div>
      <script>
        (function(){
          var all = document.getElementById('installment-select-all');
          var boxes = document.querySelectorAll('.installment-row-check');
          all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
          document.getElementById('bulkInstallmentsForm')?.addEventListener('submit', function(e){
            var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
            if (! anyChecked) { e.preventDefault(); alert('Tick at least one installment first.'); }
          });
        })();
      </script>
    @endif
  @endif

  @if($canManage)
    <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid var(--line)">
      <h3 style="margin-bottom:.6rem">Add an installment</h3>
      <form method="POST" action="{{ route('school.finance.fee-items.installments.store', ['school' => $school->slug, 'feeItemId' => $feeItem->id]) }}" style="max-width:40rem">
        @csrf
        <div class="grid cols-2" style="gap:1rem">
          <div class="field">
            <label for="label">Label</label>
            <input id="label" name="label" required maxlength="120" placeholder="e.g. 1st Installment" value="{{ old('label') }}" />
            @error('label')<p class="err">{{ $message }}</p>@enderror
          </div>
          <div class="field">
            <label for="installment_amount">Amount (₦)</label>
            <input id="installment_amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" />
            @error('amount')<p class="err">{{ $message }}</p>@enderror
          </div>
        </div>
        <div class="field">
          <label for="due_date">Due date (optional)</label>
          <input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}" />
          @error('due_date')<p class="err">{{ $message }}</p>@enderror
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Add installment</button>
      </form>
    </div>
  @endif
</div>
@endsection
