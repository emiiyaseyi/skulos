@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Mail Report'])
@section('title', 'Mail Report')
@section('heading', 'Mail Report')
@section('crumb', 'How much mail the platform has sent, per school and per audience')
@section('content')

<div class="row-between" style="margin-bottom:1.2rem">
  <div class="grid cols-3" style="flex:1;gap:1rem">
    <div class="stat"><b>{{ number_format($platformTotal) }}</b><span>Sent so far, all time</span></div>
    <div class="stat"><b>{{ number_format($rangeTotal) }}</b><span>Sent in the selected range</span></div>
    <div class="stat"><b>{{ number_format($noSchoolTotal) }}</b><span>Not tied to a school (platform mail)</span></div>
  </div>
</div>

<div class="card" style="margin-bottom:1.2rem">
  <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end">
    <div class="field" style="margin:0">
      <label for="range">Range</label>
      <select id="range" name="range" onchange="this.form.submit()">
        <option value="all" @selected($range === 'all')>All time</option>
        <option value="today" @selected($range === 'today')>Today</option>
        <option value="this_year" @selected($range === 'this_year')>This year</option>
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
    <div style="margin-left:auto"><a class="btn btn-ghost btn-sm" href="{{ route('admin.mail-log.index') }}">View the full log</a></div>
  </form>
</div>

<div class="card">
  @if($schools->isEmpty())
    <p class="muted">No schools yet.</p>
  @else
    <div style="overflow-x:auto">
      <table class="table-stack">
        <thead>
          <tr>
            <th>School</th>
            <th>Sent</th>
            @foreach($audiences as $key => $label)
              <th>{{ $label }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($schools as $s)
            <tr>
              <td data-label="School">
                <a href="{{ route('admin.mail-report.show', $s) }}" style="font-weight:700">{{ $s->name }}</a>
              </td>
              <td data-label="Sent" class="{{ $s->mail_total ? '' : 'muted' }}">{{ number_format($s->mail_total) }}</td>
              @foreach($audiences as $key => $label)
                <td data-label="{{ $label }}" class="muted">{{ number_format($s->{'mail_'.$key}) }}</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <p class="hint" style="margin-top:1rem">
      Sorted busiest first. A mail whose recipient's role could not be determined (a trial applicant
      before a school exists, a school's own welcome letter, a platform notice) is counted in Sent
      but not under any of the four audience columns, so those columns will not always add up to it.
    </p>
  @endif
</div>
@endsection
