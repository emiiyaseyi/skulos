@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Report Builder'])
@section('title', 'Report Builder')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Report Builder</h1>
    <p class="subtitle">Pick a data source, choose your columns, filter, then preview or export.</p>
  </div>
</div>

@if(empty($sources))
  <div class="card"><p class="muted">You do not have access to any report source yet.</p></div>
@else
  <div class="card" style="margin-bottom:1.1rem">
    <form method="GET" action="{{ route('school.reports.builder.index', ['school' => $school->slug]) }}">
      <div class="field" style="max-width:18rem">
        <label for="source">Data source</label>
        <select id="source" name="source" onchange="this.form.requestSubmit()">
          <option value="">Choose a source</option>
          @foreach($sources as $key => $meta)
            <option value="{{ $key }}" @selected($source === $key)>{{ $meta['label'] }}</option>
          @endforeach
        </select>
      </div>

      @if($source)
        <div class="field">
          <label>Columns</label>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(12.5rem,1fr));gap:.5rem .75rem">
            @foreach($sources[$source]['columns'] as $key => $label)
              <label style="display:flex;align-items:flex-start;gap:.5rem;font-weight:400">
                <input type="checkbox" name="columns[]" value="{{ $key }}" @checked(in_array($key, $selectedColumns, true)) style="flex-shrink:0;margin-top:.2rem" />
                <span>{{ $label }}</span>
              </label>
            @endforeach
          </div>
        </div>

        <div class="grid cols-2" style="gap:1rem;max-width:32rem">
          @if($source === 'students' && $classArms->isNotEmpty())
            <div class="field" style="margin:0">
              <label for="class_arm_id">Class</label>
              <select id="class_arm_id" name="class_arm_id">
                <option value="">Every class</option>
                @foreach($classArms as $arm)
                  <option value="{{ $arm->id }}" @selected($classArmId === $arm->id)>{{ $arm->label() }}</option>
                @endforeach
              </select>
            </div>
          @endif
          <div class="field" style="margin:0">
            <label for="status">Status</label>
            <select id="status" name="status">
              <option value="" @selected(! $status)>Any status</option>
              <option value="active" @selected($status === 'active')>Active</option>
              <option value="inactive" @selected($status === 'inactive')>Inactive</option>
            </select>
          </div>
        </div>

        <div style="display:flex;gap:.6rem;margin-top:1rem;flex-wrap:wrap">
          <button class="btn btn-primary btn-sm" type="submit">Preview</button>
          @if(count($selectedColumns))
            <a class="btn btn-ghost btn-sm" href="{{ route('school.reports.builder.export', array_merge(['school' => $school->slug, 'source' => $source, 'columns' => $selectedColumns, 'status' => $status], $classArmId ? ['class_arm_id' => $classArmId] : [])) }}">Export CSV</a>
          @endif
        </div>
      @endif
    </form>
  </div>

  @if($source && count($selectedColumns))
    <div class="card">
      @if($rows->isEmpty())
        <p class="muted">No rows match this filter.</p>
      @else
        <div style="overflow-x:auto">
        <table>
          <thead><tr>
            @foreach($selectedColumns as $key)
              <th>{{ $sources[$source]['columns'][$key] }}</th>
            @endforeach
          </tr></thead>
          <tbody>
            @foreach($rows as $row)
              <tr>
                @foreach($selectedColumns as $key)
                  <td>{{ $row[$key] ?? '' }}</td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
        <p class="muted" style="margin-top:.6rem;font-size:.82rem">{{ $rows->count() }} row(s).</p>
      @endif
    </div>
  @endif
@endif
@endsection
