@extends('layouts.app', ['portal' => 'school', 'pageTitle' => 'Import staff'])
@section('title', 'Import staff')
@section('heading', 'Import staff')
@section('crumb', 'Add many staff at once from a spreadsheet')
@section('content')

<div class="row-between" style="margin-bottom:1.2rem">
  <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">Back to staff</a>
</div>

@if(! empty($preview))
  @include('school.partials.import-preview', [
    'preview' => $preview,
    'columns' => $columns,
    'confirmRoute' => route('school.staff.import.confirm', ['school' => $school->slug]),
  ])
@endif

@if(! empty($skipped))
  <div class="flash flash-warn" style="margin-bottom:1.2rem">
    {{ $skipped }} row(s) were skipped because they needed attention. Fix them in your file and
    upload again to add them.
  </div>
@endif

@isset($result)
  <div class="card" style="margin-bottom:1.2rem">
    <h3 style="margin-bottom:.6rem">Import result</h3>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.6rem">
      <span class="badge badge-ok">{{ $result->imported }} imported</span>
      @if($result->failed())<span class="badge badge-warn">{{ $result->failed() }} not imported</span>@endif
    </div>
    <a class="btn btn-primary btn-sm" href="{{ route('school.staff.index', ['school' => $school->slug]) }}">View staff</a>
  </div>
@endisset

@if(empty($preview))
  <div class="card">
    <h3 style="margin-bottom:.3rem">Upload your file</h3>
    <p class="muted" style="margin-bottom:.9rem">
      Nothing is saved on upload. You will see exactly which rows are ready and which need
      attention, and decide from there.
    </p>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.staff.import.template', ['school' => $school->slug]) }}" style="margin-bottom:.9rem">Download the blank sheet</a>
    <form method="POST" action="{{ route('school.staff.import.run', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="file">CSV file</label>
        <input id="file" name="file" type="file" accept=".csv,.txt,.xlsx,.xls" required />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Check this file</button>
    </form>
  </div>
@endif
@endsection
