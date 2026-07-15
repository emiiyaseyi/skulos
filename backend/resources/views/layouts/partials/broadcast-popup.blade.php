{{--
  Broadcast pop-up (2026-07-15). When a school user has an unacknowledged
  broadcast (a type='broadcast' message delivered as a 'broadcast'-category
  notification), it comes up as a modal on their next page load. They read
  it and acknowledge; acknowledging marks that notification read (via the
  existing per-user school.notifications.read route, which is already scoped
  to the signed-in user so nobody can acknowledge another person's), which
  is what the sender sees as a read receipt. Shown one at a time, most
  recent first; the next appears on the reload after acknowledging.

  The body was sanitized by App\Support\HtmlSanitizer before storage, so it
  is safe to render as HTML here.
--}}
@php
  $pendingBroadcast = \App\Models\Tenant\Notification::forUser(auth()->id())
      ->where('category', 'broadcast')
      ->whereNull('read_at')
      ->with('announcement')
      ->latest()
      ->first();
  $bcImageUrl = $pendingBroadcast?->announcement?->imageUrl();
@endphp
@if($pendingBroadcast)
<div class="bc-scrim" role="alertdialog" aria-modal="true" aria-labelledby="bcTitle">
  <div class="bc-card">
    <div class="bc-head">
      <span class="bc-tag">Broadcast</span>
      <h3 id="bcTitle">{{ $pendingBroadcast->title }}</h3>
    </div>
    @if($bcImageUrl)
      <img src="{{ $bcImageUrl }}" alt="" class="bc-image" />
    @endif
    <div class="bc-body">{!! $pendingBroadcast->body !!}</div>
    <form method="POST" action="{{ route('school.notifications.read', ['school' => $school->slug, 'id' => $pendingBroadcast->id]) }}">
      @csrf
      <button class="btn btn-primary btn-block" type="submit">Acknowledge and close</button>
    </form>
  </div>
</div>
<style>
  .bc-scrim{position:fixed;inset:0;background:rgba(8,12,20,.6);z-index:210;display:grid;place-items:center;padding:1.25rem}
  .bc-card{width:100%;max-width:32rem;max-height:85vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:1.6rem 1.7rem;box-shadow:var(--shadow)}
  .bc-tag{display:inline-block;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:var(--accent-ink);background:var(--accent);padding:.15rem .5rem;border-radius:6px;margin-bottom:.5rem}
  .bc-head h3{font-size:1.2rem;font-weight:800;margin:.1rem 0 1rem}
  .bc-image{width:100%;border-radius:10px;margin-bottom:1rem;display:block}
  .bc-body{font-size:.95rem;line-height:1.6;color:var(--ink);margin-bottom:1.4rem}
  .bc-body ul,.bc-body ol{margin:.4rem 0 .4rem 1.4rem}
</style>
@endif
