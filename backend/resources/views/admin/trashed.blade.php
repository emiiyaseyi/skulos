@extends('layouts.app', ['portal' => 'admin'])
@section('title', 'Archive')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Archive</h1>
    <p class="subtitle">Deleted schools. Their databases are kept, so any of these can be restored.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Back to schools</a>
</div>

<div class="card">
  @if($schools->isEmpty())
    <p class="muted">Nothing in the archive.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>School</th><th>Address</th><th>Deleted</th><th></th></tr></thead>
      <tbody>
        @foreach($schools as $school)
          <tr>
            <td><strong>{{ $school->name }}</strong></td>
            <td class="muted">{{ $school->slug }}.{{ config('app.domain','localhost') }}</td>
            <td class="muted">{{ $school->deleted_at?->toDayDateTimeString() }}</td>
            <td>
              <form method="POST" action="{{ route('admin.schools.restore', ['schoolId' => $school->id]) }}">
                @csrf
                <button class="btn btn-primary btn-sm" type="submit">Restore</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endsection
