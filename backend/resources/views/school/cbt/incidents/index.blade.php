@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Assessment Incidents'])
@section('title', 'Assessment Incidents')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Assessment Incidents</h1>
    <p class="subtitle">Raised automatically when an attempt's risk crosses the high threshold, or manually from the live monitor.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card">
  @if($incidents->isEmpty())
    <p class="muted">No incidents recorded.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Taker</th><th>Assessment</th><th>Type</th><th>Description</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($incidents as $incident)
          <tr>
            <td data-label="Taker">{{ $incident->session?->takeable?->name ?? 'Unknown' }}</td>
            <td class="muted" data-label="Assessment">{{ $incident->session?->assessment?->title }}</td>
            <td data-label="Type">{{ $incident->type === 'manual' ? 'Manually raised' : 'High risk crossing' }}</td>
            <td class="muted" data-label="Description">{{ $incident->description }}</td>
            <td data-label="Status">
              @if($incident->status === 'open')
                <span class="badge badge-warn">Open</span>
              @else
                <span class="badge badge-ok">Resolved</span>
              @endif
            </td>
            <td>
              @if($incident->status === 'open')
                <form method="POST" action="{{ route('school.cbt.incidents.resolve', ['school' => $school->slug, 'incidentId' => $incident->id]) }}">
                  @csrf
                  <button class="btn btn-primary btn-sm" type="submit">Resolve</button>
                </form>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
