@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Grading'])
@section('title', 'Grading')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Grading</h1>
    <p class="subtitle">Written answers waiting on you.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($queues->isEmpty())
    <p class="muted">You have not been assigned as a grader on any assessment.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Assessment</th><th>Your layer</th><th>Waiting on you</th><th></th></tr></thead>
      <tbody>
        @foreach($queues as $queue)
          <tr>
            <td data-label="Assessment"><strong>{{ $queue['assessment']->title }}</strong></td>
            <td class="muted" data-label="Your layer">{{ $queue['layer'] === 2 ? 'Second review' : 'First grader' }}</td>
            <td data-label="Waiting on you">
              @if($queue['outstanding'] > 0)
                <span class="badge badge-warn">{{ $queue['outstanding'] }}</span>
              @else
                <span class="badge badge-ok">All marked</span>
              @endif
            </td>
            <td>
              <a class="btn btn-primary btn-sm" href="{{ route('school.cbt.grader.queue', ['school' => $school->slug, 'assessmentId' => $queue['assessment']->id]) }}">Open</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
