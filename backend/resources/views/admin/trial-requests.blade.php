@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Trial Requests'])
@section('title', 'Trial Requests')
@section('heading', 'Trial Requests')
@section('crumb', 'Review Start a Trial leads from the marketing site')
@section('content')

<div class="stack" style="flex-direction:row;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
  @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label)
    <a class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('admin.trial-requests.index', ['status' => $key]) }}">
      {{ $label }}@if($key !== 'all') ({{ $counts[$key] }})@endif
    </a>
  @endforeach
</div>

@if($trials->isEmpty())
  <div class="card"><p class="muted">Nothing here.</p></div>
@else
  @foreach($trials as $trial)
    <div class="card" style="margin-bottom:1.1rem">
      <div class="row-between" style="margin-bottom:.6rem">
        <div>
          <strong>{{ $trial->school_name }}</strong>
          <span class="badge {{ $trial->status === 'approved' ? 'badge-ok' : ($trial->status === 'rejected' ? 'badge-bad' : 'badge-warn') }}" style="margin-left:.5rem">{{ ucfirst($trial->status) }}</span>
        </div>
        <span class="muted" style="font-size:.82rem">{{ $trial->created_at->diffForHumans() }}</span>
      </div>
      <div style="overflow-x:auto">
      <table style="margin-bottom:.8rem">
        <tbody>
          <tr><th>Contact</th><td style="word-break:break-word">{{ $trial->contact_name }} &middot; {{ $trial->email }}@if($trial->phone) &middot; {{ $trial->phone }}@endif</td></tr>
          @if($trial->student_count)<tr><th>Students</th><td>{{ number_format($trial->student_count) }}</td></tr>@endif
          @if($trial->message)<tr><th>Message</th><td>{{ $trial->message }}</td></tr>@endif
          @if($trial->reviewed_at)<tr><th>Reviewed</th><td class="muted">{{ $trial->reviewed_at->toDayDateTimeString() }} by {{ $trial->reviewer?->name ?? 'Unknown' }}@if($trial->review_note) &middot; "{{ $trial->review_note }}"@endif</td></tr>@endif
        </tbody>
      </table>
      </div>

      @if($trial->status === 'pending')
        <div class="grid cols-2">
          <form method="POST" action="{{ route('admin.trial-requests.approve', $trial) }}">
            @csrf
            <div class="field">
              <label for="approve_note_{{ $trial->id }}">Note to include (setup status, demo, training)</label>
              <textarea id="approve_note_{{ $trial->id }}" name="review_note" rows="2" placeholder="We'll set up {{ $trial->school_name }} this week and schedule a demo and staff training."></textarea>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Approve and email</button>
          </form>
          <form method="POST" action="{{ route('admin.trial-requests.reject', $trial) }}">
            @csrf
            <div class="field">
              <label for="reject_note_{{ $trial->id }}">Note to include</label>
              <textarea id="reject_note_{{ $trial->id }}" name="review_note" rows="2" placeholder="Optional reason"></textarea>
            </div>
            <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--bad)">Decline and email</button>
          </form>
        </div>
      @endif
    </div>
  @endforeach
@endif
@endsection
