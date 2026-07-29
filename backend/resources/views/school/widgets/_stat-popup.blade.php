{{--
  A per-child breakdown behind a dashboard stat tile (user request
  2026-07-27: "parent can click on the card to view... per child as a
  pop up"). Fully self contained CSS, deliberately not reusing
  payment-popup.blade.php's .pay-scrim/.pay-card classes even though the
  look matches: that partial's own <style> block only ever renders
  inside its own "@if($paymentPopup)" guard, so a parent with no
  configured payment due date (most schools, until an admin sets one)
  would get this popup's HTML with no styling behind it at all.
--}}
@if(($rows ?? collect())->isNotEmpty())
<div class="stat-popup-scrim" id="{{ $id }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
  <div class="stat-popup-card">
    <h3 id="{{ $id }}-title">{{ $title }}</h3>
    <ul class="stat-popup-list">
      @forelse($rows as $row)
        <li><span>{{ $row['name'] }}</span><b style="color:{{ $row['ok'] ? 'var(--ok)' : 'var(--bad)' }}">{{ $row['value'] }}</b></li>
      @empty
        <li class="muted">{{ $emptyText ?? 'Nothing to show.' }}</li>
      @endforelse
    </ul>
    <div class="stat-popup-actions">
      <button class="btn btn-primary btn-sm" type="button" data-close-popup="{{ $id }}">Close</button>
    </div>
  </div>
</div>
<style>
  .stat-popup-scrim{position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:205;display:none;place-items:center;padding:1.25rem}
  .stat-popup-scrim.show{display:grid}
  .stat-popup-card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.8rem;box-shadow:var(--shadow);text-align:center}
  .stat-popup-card h3{font-size:1rem;font-weight:800;margin-bottom:.4rem}
  .stat-popup-list{list-style:none;text-align:left;margin:.8rem 0 0;display:flex;flex-direction:column;gap:.5rem}
  .stat-popup-list li{display:flex;justify-content:space-between;align-items:center;gap:.8rem;padding:.55rem .7rem;background:var(--bg);border-radius:8px;font-size:.88rem}
  .stat-popup-actions{margin-top:1.2rem}
</style>
@endif
