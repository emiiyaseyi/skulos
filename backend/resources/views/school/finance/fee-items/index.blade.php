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
          <input id="name" name="name" required maxlength="120" placeholder="e.g. Tuition" value="{{ old('name') }}" />
        </div>
        <div class="field">
          <label for="amount">Amount (₦)</label>
          <input id="amount" name="amount" type="number" step="0.01" min="0" required value="{{ old('amount') }}" />
        </div>
      </div>
      <div class="field">
        <label for="term_id">Term</label>
        <select id="term_id" name="term_id" required>
          <option value="">Select a term</option>
          @foreach($terms as $term)
            <option value="{{ $term->id }}" @selected(old('term_id') == $term->id)>{{ $term->name }} ({{ $term->session?->name }})</option>
          @endforeach
        </select>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Add fee item</button>
    </form>
  </div>
@endif

<div class="card">
  @if($feeItems->isEmpty())
    <p class="muted">No fee items yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Name</th><th>Term</th><th>Amount</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead>
      <tbody>
        @foreach($feeItems as $item)
          <tr>
            <td><strong>{{ $item->name }}</strong></td>
            <td class="muted">{{ $item->term?->name }}</td>
            <td>₦{{ number_format((float) $item->amount, 2) }}</td>
            <td><span class="badge {{ $item->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $item->is_active ? 'Active' : 'Retired' }}</span></td>
            @if($canManage)
              <td style="white-space:nowrap">
                <form method="POST" action="{{ route('school.finance.fee-items.toggle-active', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $item->is_active ? 'Retire' : 'Reactivate' }}</button>
                </form>
                <form method="POST" action="{{ route('school.finance.fee-items.destroy', ['school' => $school->slug, 'feeItemId' => $item->id]) }}" style="display:inline" onsubmit="return confirm('Remove this fee item?')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-ghost btn-sm" type="submit">Delete</button>
                </form>
              </td>
            @endif
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
