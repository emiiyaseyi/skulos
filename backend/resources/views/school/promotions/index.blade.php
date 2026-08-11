@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Promotion Runs'])
@section('title', 'Promotion Runs')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Promotion Runs</h1>
    <p class="subtitle">Every past run of the manual promotion flow, revisitable at any time.</p>
  </div>
  <a class="btn btn-primary btn-sm" href="{{ route('school.promotions.create', ['school' => $school->slug]) }}">Run a new promotion</a>
</div>

<div class="card">
  @if($runs->isEmpty())
    <p class="muted" style="margin-bottom:0">No promotion has been run yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Term evaluated</th><th>Threshold</th><th>Promoted</th><th>Repeated</th><th>Graduated</th><th>Skipped</th><th>Run</th><th></th></tr></thead>
      <tbody>
        @foreach($runs as $run)
          <tr>
            <td data-label="Term evaluated">{{ $run->term?->session?->name }} {{ $run->term?->name }}</td>
            <td data-label="Threshold">{{ $run->threshold }}%</td>
            <td data-label="Promoted">{{ $run->promoted_count }}</td>
            <td data-label="Repeated">{{ $run->repeated_count }}</td>
            <td data-label="Graduated">{{ $run->graduated_count }}</td>
            <td data-label="Skipped">{{ $run->skipped_count }}</td>
            <td data-label="Run">{{ $run->created_at->diffForHumans() }}</td>
            <td data-label="Actions"><a class="btn btn-ghost btn-sm" href="{{ route('school.promotions.show', ['school' => $school->slug, 'runId' => $run->id]) }}">View report</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
