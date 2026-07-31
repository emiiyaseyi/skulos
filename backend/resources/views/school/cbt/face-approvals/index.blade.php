@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Face Match Approvals'])
@section('title', 'Face Match Approvals')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Face Match Approvals</h1>
    <p class="subtitle">Takers whose live face capture could not automatically match their photo ID.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($approvals->isEmpty())
    <p class="muted">Nothing pending review.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Taker</th><th>Assessment</th><th>Photo ID</th><th>Live capture</th><th>Best match</th><th>Attempts</th><th></th></tr></thead>
      <tbody>
        @foreach($approvals as $approval)
          @php($precheck = $approval->precheckSession)
          <tr>
            <td data-label="Taker">{{ $precheck?->takeable?->name ?? 'Unknown' }}</td>
            <td class="muted" data-label="Assessment">{{ $precheck?->paper?->title }}</td>
            <td data-label="Photo ID">
              <img src="{{ route('school.cbt.face-approvals.photo-id-image', ['school' => $school->slug, 'approvalId' => $approval->id]) }}" alt="Photo ID" style="width:5rem;height:5rem;object-fit:cover;border-radius:6px" />
            </td>
            <td data-label="Live capture">
              <img src="{{ route('school.cbt.face-approvals.face-image', ['school' => $school->slug, 'approvalId' => $approval->id]) }}" alt="Live capture" style="width:5rem;height:5rem;object-fit:cover;border-radius:6px" />
            </td>
            <td data-label="Best match">{{ $approval->match_score !== null ? round($approval->match_score * 100).'%' : 'n/a' }}</td>
            <td class="muted" data-label="Attempts">{{ $approval->attempts }}</td>
            <td>
              <form method="POST" action="{{ route('school.cbt.face-approvals.decide', ['school' => $school->slug, 'approvalId' => $approval->id]) }}" style="display:flex;gap:.4rem">
                @csrf
                <input type="hidden" name="decision" value="approve" />
                <button class="btn btn-primary btn-sm" type="submit" onclick="this.form.decision.value='approve'">Approve</button>
                <button class="btn btn-ghost btn-sm" type="submit" onclick="this.form.decision.value='reject'">Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
