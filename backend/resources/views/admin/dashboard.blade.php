@extends('layouts.app')
@section('title', 'Platform Admin')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Platform Administration</h1>
    <p class="subtitle">Create and oversee every school on SKULOS from one place.</p>
  </div>
</div>

<div class="grid cols-3" style="margin-bottom:1.5rem">
  <div class="card stat"><b>{{ $stats['schools'] }}</b><span>Schools</span></div>
  <div class="card stat"><b>{{ $stats['active'] }}</b><span>Active</span></div>
  <div class="card stat"><b>{{ $stats['pending'] }}</b><span>Pending</span></div>
</div>

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3>Create a school</h3>
    <p style="margin-bottom:1.1rem">A new school gets its own database, calendar defaults, and subdomain.</p>
    <form method="POST" action="{{ route('admin.schools.store') }}">
      @csrf
      <div class="field">
        <label for="name">School name</label>
        <input id="name" name="name" value="{{ old('name') }}" required placeholder="Greenfield Academy" />
        @error('name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="slug">Subdomain</label>
        <input id="slug" name="slug" value="{{ old('slug') }}" placeholder="greenfield" />
        <div class="hint">Leave blank to generate from the name. Becomes yourslug.{{ config('app.domain', 'localhost') }}</div>
        @error('slug')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="email">Contact email</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" />
        </div>
        <div class="field">
          <label for="phone">Contact phone</label>
          <input id="phone" name="phone" value="{{ old('phone') }}" />
        </div>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Create school</button>
    </form>
  </div>

  <div class="card">
    <h3>Schools</h3>
    <p style="margin-bottom:1rem">Every school and its address.</p>
    @if($schools->isEmpty())
      <p class="muted">No schools yet. Create your first one to test the platform.</p>
    @else
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Name</th><th>Address</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($schools as $school)
          <tr>
            <td><strong>{{ $school->name }}</strong></td>
            <td class="muted">{{ $school->slug }}.{{ config('app.domain', 'localhost') }}</td>
            <td>
              <span class="badge {{ $school->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($school->status) }}</span>
            </td>
            <td style="text-align:right">
              <a class="btn btn-ghost btn-sm" href="{{ $school->portalUrl() }}" target="_blank" rel="noopener">Open</a>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      </div>
    @endif
  </div>
</div>
@endsection
