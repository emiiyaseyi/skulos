@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Manage Modules'])
@section('title', 'Manage Modules')
@section('heading', 'Manage Modules')
@section('crumb', 'Every school\'s optional modules, at a glance')

@section('content')
<div class="card">
  <p class="muted" style="margin-bottom:1rem">Core modules (Students, Staff, Academics, Attendance, ...) are always on for every school and are not shown here. Click a cell to toggle that module for that school.</p>
  @if($schools->isEmpty())
    <p class="muted">No provisioned schools yet.</p>
  @else
  <div style="overflow-x:auto">
  <table>
    <thead>
      <tr>
        <th style="position:sticky;left:0;background:var(--panel)">School</th>
        @foreach($modules as $code => $meta)
          <th style="white-space:nowrap">{{ $meta['name'] }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($schools as $school)
        <tr>
          <td style="position:sticky;left:0;background:var(--panel);font-weight:700;white-space:nowrap">{{ $school->name }}</td>
          @foreach($modules as $code => $meta)
            @php($state = $matrix[$school->id][$code] ?? 'disabled')
            <td>
              <form method="POST" action="{{ route('admin.billing.schools.toggle-feature', ['school' => $school->id]) }}">
                @csrf
                <input type="hidden" name="module" value="{{ $code }}" />
                <button type="submit" class="btn btn-sm {{ $state === 'enabled' ? 'btn-primary' : 'btn-ghost' }}" title="{{ ucfirst($state) }}, click to toggle">
                  {{ $state === 'enabled' ? 'On' : 'Off' }}
                </button>
              </form>
            </td>
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
  @endif
</div>
@endsection
