@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Profile approvals'])
@section('title', 'Profile approvals')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Profile approvals</h1>
    <p class="subtitle">Requested name, email, or phone changes waiting on your approval.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.profile.edit', ['school' => $school->slug]) }}">My profile</a>
</div>

<div class="card">
  @if($requests->isEmpty())
    <p class="muted">Nothing waiting on you right now.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Requested by</th><th>Change</th><th>Requested</th><th></th></tr></thead>
      <tbody>
        @foreach($requests as $req)
          <tr>
            <td><strong>{{ $req->applicant?->name ?? 'Unknown' }}</strong></td>
            <td class="muted">
              @foreach($req->requested as $field => $value)
                {{ ucfirst($field) }}: {{ $req->original[$field] ?? '' }} &rarr; {{ $value }}<br>
              @endforeach
            </td>
            <td class="muted">{{ $req->created_at->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.profile.approve', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.profile.reject', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline" class="reject-form">
                @csrf
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm reject-btn" type="button">Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.6rem">Forgot-password requests</h3>
  @if($passwordRequests->isEmpty())
    <p class="muted">Nothing waiting on you right now.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Requested by</th><th>Requested</th><th></th></tr></thead>
      <tbody>
        @foreach($passwordRequests as $req)
          <tr>
            <td><strong>{{ $req->applicant?->name ?? 'Unknown' }}</strong></td>
            <td class="muted">{{ $req->created_at->diffForHumans() }}</td>
            <td style="white-space:nowrap">
              <form method="POST" action="{{ route('school.profile.reset.approve', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Approve</button>
              </form>
              <form method="POST" action="{{ route('school.profile.reset.reject', ['school' => $school->slug, 'requestId' => $req->id]) }}" style="display:inline" class="reject-form">
                @csrf
                <input type="hidden" name="notes" value="" />
                <button class="btn btn-ghost btn-sm reject-btn" type="button">Reject</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<script>
  (function(){
    document.querySelectorAll('.reject-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var notes = prompt('Why is this being rejected? A reason is required.');
        if (notes === null) return;
        if (! notes.trim()) { alert('A reason is required to reject a change.'); return; }
        var form = btn.closest('form');
        form.querySelector('[name="notes"]').value = notes.trim();
        form.submit();
      });
    });
  })();
</script>
@endsection
