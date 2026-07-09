@extends('layouts.app', ['portal' => 'admin'])
@section('title', $school->name)
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $school->name }}</h1>
    <p class="subtitle">{{ $school->slug }}.{{ config('app.domain','localhost') }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.dashboard') }}">Back</a>
    <a class="btn btn-primary btn-sm" href="{{ $school->portalUrl() }}" target="_blank" rel="noopener">Open portal</a>
  </div>
</div>
<div class="card">
  <table>
    <tbody>
      <tr><th>Status</th><td>{{ ucfirst($school->status) }}</td></tr>
      <tr><th>Database</th><td class="muted">{{ $school->database }}</td></tr>
      <tr><th>Contact email</th><td>{{ $school->email ?: 'Not set' }}</td></tr>
      <tr><th>Contact phone</th><td>{{ $school->phone ?: 'Not set' }}</td></tr>
      <tr><th>Provisioned</th><td>{{ $school->provisioned_at?->toDayDateTimeString() ?: 'No' }}</td></tr>
    </tbody>
  </table>
</div>
@endsection
