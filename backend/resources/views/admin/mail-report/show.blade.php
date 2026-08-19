@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Mail Report for '.$school->name])
@section('title', $school->name.' Mail Report')
@section('heading', $school->name)
@section('crumb', 'Mail sent to and about this school')
@section('content')

<div style="margin-bottom:1.2rem">
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.mail-report.index') }}">Back to all schools</a>
</div>

<div class="grid cols-3" style="margin-bottom:1.2rem">
  <div class="stat"><b>{{ number_format($total) }}</b><span>Sent in the selected range</span></div>
  @foreach($byAudience->take(2) as $key => $count)
    <div class="stat"><b>{{ number_format($count) }}</b><span>{{ $audiences[$key] }}</span></div>
  @endforeach
</div>
<div class="grid cols-3" style="margin-bottom:1.2rem">
  @foreach($byAudience->skip(2) as $key => $count)
    <div class="stat"><b>{{ number_format($count) }}</b><span>{{ $audiences[$key] }}</span></div>
  @endforeach
  <div class="stat"><b>{{ number_format($unclassified) }}</b><span>Not one of the four (platform or welcome mail)</span></div>
</div>

<div class="card" style="margin-bottom:1.2rem">
  <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
    <div class="field" style="margin:0">
      <label for="range">Range</label>
      <select id="range" name="range" onchange="this.form.submit()">
        <option value="all" @selected($range === 'all')>All time</option>
        <option value="today" @selected($range === 'today')>Today</option>
        <option value="this_year" @selected($range === 'this_year')>This year</option>
        <option value="created" @selected($range === 'created')>Since {{ $school->name }} was created ({{ $school->created_at->toFormattedDateString() }})</option>
        <option value="custom" @selected($range === 'custom')>Custom range</option>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="from">From</label>
      <input type="date" id="from" name="from" value="{{ $from }}" onchange="document.getElementById('range').value='custom'" />
    </div>
    <div class="field" style="margin:0">
      <label for="to">To</label>
      <input type="date" id="to" name="to" value="{{ $to }}" onchange="document.getElementById('range').value='custom'" />
    </div>
    <div><button class="btn btn-primary btn-sm" type="submit">Apply</button></div>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">By day</h3>
  @if($byDay->isEmpty())
    <p class="muted">No mail in this range.</p>
  @else
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead><tr><th>Date</th><th>Sent</th></tr></thead>
        <tbody>
          @foreach($byDay as $row)
            <tr>
              <td data-label="Date">{{ \Illuminate\Support\Carbon::parse($row->day)->toFormattedDateString() }}</td>
              <td data-label="Sent">{{ number_format($row->total) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
