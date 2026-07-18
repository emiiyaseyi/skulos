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

@if($canManage)
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
        </select>
        @error('term_id')<p class="err" id="term_id-error">{{ $message }}</p>@enderror
      </div>
      <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem">
        <input type="checkbox" name="allow_installments" value="1" @checked(old('allow_installments')) />
        <span>Allow this fee to be paid in installments</span>
      </label>
      <button class="btn btn-primary btn-sm" type="submit">Add fee item</button>
    </form>
  </div>
@endif

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
        <th>Name</th><th>Term</th><th>Amount</th><th>Installments</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($feeItems as $item)
          <tr>
            @if($canManage)
              <td><input type="checkbox" form="bulkFeeItemsForm" class="fee-item-row-check" name="fee_item_ids[]" value="{{ $item->id }}" aria-label="Select {{ $item->name }}" /></td>
            @endif
            <td><strong>{{ $item->name }}</strong></td>
            <td class="muted">{{ $item->term?->name }}</td>
            <td>₦{{ number_format((float) $item->amount, 2) }}</td>
            <td class="muted">{{ $item->allow_installments ? 'Allowed' : 'Full amount only' }}</td>
            <td><span class="badge {{ $item->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $item->is_active ? 'Active' : 'Retired' }}</span></td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('school.finance.fee-items.show', ['school' => $school->slug, 'feeItemId' => $item->id]) }}">{{ $canManage ? 'Manage' : 'View' }}</a>
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
@endsection
