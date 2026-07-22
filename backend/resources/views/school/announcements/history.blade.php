@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Communication History'])
@section('title', 'Communication History')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Communication History</h1>
    <p class="subtitle">Every announcement and broadcast this school has sent, in one ledger.</p>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="field" style="margin:0">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="" {{ ! $type ? 'selected' : '' }}>All</option>
        <option value="announcement" {{ $type === 'announcement' ? 'selected' : '' }}>Announcements</option>
        <option value="broadcast" {{ $type === 'broadcast' ? 'selected' : '' }}>Messages</option>
      </select>
    </div>
  </form>
</div>

<div class="card">
  @if($items->isEmpty())
    <p class="muted">Nothing sent yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Sent</th><th>Type</th><th>Title</th><th>To</th><th>Acknowledged</th></tr></thead>
      <tbody>
        @foreach($items as $item)
          @php
            $delivered = (int) $item->delivered_count;
            $read = (int) $item->read_count;
            $pct = $delivered > 0 ? round($read / $delivered * 100) : 0;
            // The class arm/section name is admin-authored data, not a
            // literal, and was previously interpolated into a raw {!! !!}
            // render unescaped, a stored XSS vector (audit finding L-3,
            // 2026-07-17).
            $target = e(ucfirst($item->audience));
            if ($item->classArm) { $target .= ' &middot; '.e($item->classArm->label()); }
            elseif ($item->section) { $target .= ' &middot; '.e($item->section->name); }
            else { $target .= ' &middot; whole school'; }
          @endphp
          <tr>
            <td class="muted" style="white-space:nowrap">{{ $item->published_at?->format('d M Y') ?? $item->created_at->format('d M Y') }}</td>
            <td style="white-space:nowrap">
              <span class="badge {{ $item->type === 'broadcast' ? 'badge-warn' : 'badge-ok' }}">{{ $item->type === 'broadcast' ? 'Message' : 'Announcement' }}</span>
            </td>
            <td>
              <strong>{{ $item->title }}</strong>
              <div class="muted" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit(strip_tags($item->body), 90) }}</div>
            </td>
            <td class="muted" style="white-space:nowrap">{!! $target !!}</td>
            <td style="white-space:nowrap">
              @if($delivered === 0)
                <span class="muted">No recipients</span>
              @else
                <a class="badge {{ $pct >= 66 ? 'badge-ok' : ($pct >= 33 ? 'badge-warn' : 'badge-bad') }}" style="text-decoration:none" href="{{ route('school.announcements.history.show', ['school' => $school->slug, 'announcementId' => $item->id]) }}">{{ $read }} of {{ $delivered }}</a>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $items->links() }}</div>
  @endif
</div>
@endsection
