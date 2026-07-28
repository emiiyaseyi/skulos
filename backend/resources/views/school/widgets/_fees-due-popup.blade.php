{{--
  Per-child fees breakdown behind the dashboard "Fees Due" tile (user
  directive 2026-07-28: "another column for each child to show the
  amount to be pay by each child, the amount paid, and then a status...
  then another button of pay now"). A dedicated partial rather than
  reusing the generic _stat-popup (which only ever renders a flat
  name/value list, no room for 3 columns), sharing its .stat-popup-*
  classes so the two still look like one family of popup.
--}}
@if(($rows ?? collect())->isNotEmpty())
<div class="stat-popup-scrim" id="{{ $id }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
  <div class="stat-popup-card fees-popup-card">
    <h3 id="{{ $id }}-title">{{ $title }}</h3>
    <div style="overflow-x:auto">
    <table class="fees-popup-table">
      <thead>
        <tr><th>Child</th><th>Amount</th><th>Paid</th><th>Status</th></tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          <tr>
            <td>{{ $row['name'] }}</td>
            <td>₦{{ number_format($row['owed'], 2) }}</td>
            <td>₦{{ number_format($row['paid'], 2) }}</td>
            <td style="color:{{ $row['due'] > 0 ? 'var(--bad)' : 'var(--ok)' }};font-weight:700">
              {{ $row['due'] > 0 ? '₦'.number_format($row['due'], 2).' left' : 'Completed' }}
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div class="stat-popup-actions" style="display:flex;gap:.6rem;justify-content:center">
      <button class="btn btn-ghost btn-sm" type="button" data-close-popup="{{ $id }}">Close</button>
      @if($rows->sum('due') > 0)
        <a class="btn btn-primary btn-sm" href="{{ route('school.finance.payments.create', ['school' => $school->slug]) }}">Pay Now</a>
      @endif
    </div>
  </div>
</div>
<style>
  .fees-popup-card{max-width:32rem;text-align:left}
  .fees-popup-card h3{text-align:center}
  .fees-popup-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.6rem}
  .fees-popup-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;color:var(--mut-dim);padding:.4rem .5rem;border-bottom:1px solid var(--line)}
  .fees-popup-table td{padding:.55rem .5rem;border-bottom:1px solid var(--line);white-space:nowrap}
  .fees-popup-table tbody tr:last-child td{border-bottom:none}
</style>
@endif
