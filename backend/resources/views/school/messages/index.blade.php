@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Messages'])
@section('title', 'Messages')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Messages</h1>
    <p class="subtitle">Broadcasts to parents, students, and teachers, with read receipts.</p>
  </div>
  @if($canCompose)
    <a class="btn btn-primary btn-sm" href="{{ route('school.messages.create', ['school' => $school->slug]) }}">New broadcast</a>
  @endif
</div>

<div class="card">
  @if($broadcasts->isEmpty())
    <p class="muted">No broadcasts sent yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Sent</th><th>Title</th><th>To</th><th>Acknowledged</th></tr></thead>
      <tbody>
        @foreach($broadcasts as $b)
          @php
            $delivered = (int) $b->delivered_count;
            $read = (int) $b->read_count;
            $pct = $delivered > 0 ? round($read / $delivered * 100) : 0;
            // The class arm/section name is admin-authored data, not a
            // literal, and was previously interpolated into a raw {!! !!}
            // render unescaped, a stored XSS vector (audit finding L-3,
            // 2026-07-17).
            $target = e(ucfirst($b->audience));
            if ($b->classArm) { $target .= ' &middot; '.e($b->classArm->label()); }
            elseif ($b->section) { $target .= ' &middot; '.e($b->section->name); }
            else { $target .= ' &middot; whole school'; }
          @endphp
          <tr>
            <td class="muted" style="white-space:nowrap" data-label="Sent">{{ $b->published_at?->format('d M Y') ?? $b->created_at->format('d M Y') }}</td>
            <td data-label="Title">
              <div style="display:flex;align-items:center;gap:.6rem">
                @if($b->image_path)
                  <img src="{{ $b->imageUrl() }}" alt="{{ $b->title }}" style="width:2.4rem;height:2.4rem;object-fit:cover;border-radius:6px;flex-shrink:0" />
                @endif
                <div>
                  <strong>{{ $b->title }}</strong>
                  <div class="muted">{{ \Illuminate\Support\Str::limit(strip_tags($b->body), 90) }}</div>
                </div>
              </div>
            </td>
            <td class="muted" style="white-space:nowrap" data-label="To">{!! $target !!}</td>
            <td style="white-space:nowrap" data-label="Acknowledged">
              @if($delivered === 0)
                <span class="muted">No recipients</span>
              @else
                <span class="badge {{ $pct >= 66 ? 'badge-ok' : ($pct >= 33 ? 'badge-warn' : 'badge-bad') }}">{{ $read }} of {{ $delivered }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $broadcasts->links() }}</div>
  @endif
</div>
@endsection
