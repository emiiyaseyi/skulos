@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admission Recovery Requests'])
@section('title', 'Admission Recovery Requests')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admission Recovery Requests</h1>
    <p class="subtitle">An applicant asked for a manual check after the automatic identity match didn't find them. Match the description to a real applicant and approve, or reject.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.pipeline', ['school' => $school->slug]) }}">Applicants</a>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif
@if(session('error'))
  <div class="flash flash-bad" style="margin-bottom:1.25rem">{{ session('error') }}</div>
@endif

<div class="card">
  @if($requests->isEmpty())
    <p class="muted">No recovery requests yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Remembered name</th><th>What they told us</th><th>Email</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($requests as $req)
          <tr>
            <td data-label="Remembered name"><strong>{{ $req->remembered_name ?: 'Not given' }}</strong></td>
            <td class="muted" data-label="What they told us" style="max-width:20rem">{{ $req->description }}</td>
            <td class="muted" data-label="Email">{{ $req->email }}</td>
            <td data-label="Status">
              <span class="badge {{ $req->status === 'approved' ? 'badge-ok' : ($req->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}">{{ ucfirst($req->status) }}</span>
              @if($req->matchedApplicant)
                <div class="muted" style="font-size:.78rem">{{ $req->matchedApplicant->name }} ({{ $req->matchedApplicant->admission_number }})</div>
              @endif
            </td>
            <td style="white-space:nowrap">
              @if($req->status === 'pending')
                <details style="display:inline-block">
                  <summary class="btn btn-primary btn-sm" style="display:inline-block;cursor:pointer">Approve</summary>
                  <form method="POST" action="{{ route('school.admissions.recovery-review.approve', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="margin-top:.5rem;min-width:16rem;text-align:left">
                    @csrf
                    <label for="match_{{ $req->id }}" class="muted" style="font-size:.8rem">Which applicant is this?</label>
                    <select id="match_{{ $req->id }}" name="matched_applicant_id" required>
                      <option value="">Choose an applicant</option>
                      @foreach($applicants as $applicant)
                        <option value="{{ $applicant->id }}">{{ $applicant->name }} &middot; {{ $applicant->admission_number }} &middot; {{ $applicant->phone }}</option>
                      @endforeach
                    </select>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.5rem">Confirm approve</button>
                  </form>
                </details>
                <details style="display:inline-block">
                  <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Reject</summary>
                  <form method="POST" action="{{ route('school.admissions.recovery-review.reject', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="margin-top:.5rem;min-width:14rem">
                    @csrf
                    <textarea name="notes" rows="2" placeholder="Reason (optional)"></textarea>
                    <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:.4rem">Confirm reject</button>
                  </form>
                </details>
              @else
                <span class="muted">{{ $req->notes ?: 'Decided' }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $requests->links() }}</div>
  @endif
</div>
@endsection
