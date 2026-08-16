@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Import students'])
@section('title', 'Import students')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Import students</h1>
    <p class="subtitle">Upload a CSV to add many students at once.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.students', ['school' => $school->slug]) }}">Back to students</a>
</div>

@if(! empty($preview))
  @include('school.partials.import-preview', [
    'preview' => $preview,
    'columns' => $columns,
    'confirmRoute' => route('school.students.import.confirm', ['school' => $school->slug]),
  ])
@endif

@if(! empty($skipped))
  <div class="flash flash-warn" style="margin-bottom:1.2rem">
    {{ $skipped }} row(s) were skipped because they needed attention. Fix them in your file and
    upload again to add them.
  </div>
@endif

@isset($result)
  <div class="card" style="margin-bottom:1.25rem">
    <div class="row-between" style="margin-bottom:.6rem">
      <h3>Import result</h3>
      <div class="stack" style="flex-direction:row;gap:.5rem">
        <span class="badge badge-ok">{{ $result->imported }} imported</span>
        @if($result->failed())<span class="badge badge-warn">{{ $result->failed() }} to fix</span>@endif
      </div>
    </div>
    @if($result->ok())
      <p class="muted">Every row imported successfully.</p>
    @else
      <p class="muted" style="margin-bottom:.8rem">
        @if($result->mode === 'strict')
          Nothing was imported because some rows have problems (strict mode). Fix these rows and upload again.
        @else
          The valid rows were imported. Fix the rows below and upload them again.
        @endif
      </p>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Row</th><th>Problem</th></tr></thead>
        <tbody>
          @foreach($result->errors as $err)
            <tr>
              <td><strong>Row {{ $err['row'] }}</strong></td>
              <td>
                @foreach($err['errors'] as $field => $messages)
                  <div>{{ implode(' ', $messages) }}</div>
                @endforeach
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>
@endisset

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3 style="margin-bottom:.8rem">Upload your file</h3>
    <form method="POST" action="{{ route('school.students.import.run', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="file">CSV file</label>
        <input id="file" name="file" type="file" accept=".csv,text/csv" required />
        @error('file')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label>If some rows have problems</label>
        <label style="font-weight:500;display:flex;gap:.5rem;align-items:center;margin-bottom:.4rem"><input type="radio" name="mode" value="partial" checked> Import the good rows, report the rest</label>
        <label style="font-weight:500;display:flex;gap:.5rem;align-items:center"><input type="radio" name="mode" value="strict"> Import nothing unless every row is valid</label>
        @error('mode')<div class="err">{{ $message }}</div>@enderror
      </div>
      <button class="btn btn-primary" type="submit">Import students</button>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-bottom:.4rem">How it works</h3>
    <p class="muted" style="margin-bottom:1rem">Download the template, fill in one student per row, then upload it. Problems are reported by row number so you can fix and re upload.</p>
    <a class="btn btn-ghost" href="{{ route('school.students.template', ['school' => $school->slug]) }}">Download CSV template</a>
    <div style="margin-top:1.2rem">
      <div class="row-between" style="margin-bottom:.5rem">
        <b style="font-size:.82rem">Columns</b>
        <a class="btn btn-ghost btn-sm" href="{{ route('school.students.settings.edit', ['school' => $school->slug]) }}">Which fields are required</a>
      </div>
      <ul style="list-style:none;display:flex;flex-direction:column;gap:.3rem">
        @foreach($columns as $key => $label)
          <li class="muted" style="font-size:.85rem"><strong style="color:var(--ink)">{{ $key }}</strong>, {{ $label }}@if(isset($requiredFields) && in_array($key, $requiredFields, true))<span class="badge badge-warn" style="margin-left:.4rem">Required</span>@endif</li>
        @endforeach
      </ul>
    </div>
  </div>
</div>
@endsection
