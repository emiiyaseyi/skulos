@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Approval History'])
@section('title', 'Approval History')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Approval History</h1>
    <p class="subtitle">Every decided profile change, password reset, student profile change, and report card review: who requested it, who decided it, and when.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.approvals.index', ['school' => $school->slug]) }}">Back to Approvals</a>
</div>

<div class="card">
  @if($items->isEmpty())
    <p class="muted" style="margin-bottom:0">No decisions have been recorded yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Type</th><th>Requested By</th><th>Decided By</th><th>Outcome</th><th>Requested</th><th>Decided</th><th>Notes</th></tr></thead>
      <tbody>
        @foreach($items as $item)
          <tr>
            <td>{{ $item['typeLabel'] }}@if(isset($item['description'])) &middot; {{ $item['description'] }}@endif</td>
            <td>{{ $item['requestedBy'] }}</td>
            <td>{{ $item['decidedBy'] }}</td>
            <td>
              <span class="badge {{ str_contains($item['outcome'], 'reject') ? 'badge-bad' : (in_array($item['outcome'], ['approved','released','locked','archived']) ? 'badge-ok' : 'badge-warn') }}">{{ ucfirst(str_replace('_', ' ', $item['outcome'])) }}</span>
            </td>
            <td class="muted">{{ $item['submittedAt']?->diffForHumans() }}</td>
            <td class="muted">{{ $item['decidedAt']?->diffForHumans() ?? 'Not set' }}</td>
            <td class="muted">{{ $item['notes'] ?? 'Not set' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div style="margin-top:1rem">{{ $items->links() }}</div>
  @endif
</div>
@endsection
