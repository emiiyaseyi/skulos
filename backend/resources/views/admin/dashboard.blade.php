@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Dashboard'])
@section('title', 'Platform Admin')
@section('heading', 'Platform Administration')
@section('crumb', 'Overview of every school on SKULOS')
@section('content')
<div class="grid cols-3" style="margin-bottom:1.5rem">
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'school'])</span><span class="stat"><b>{{ $stats['schools'] }}</b><span>Schools</span></span></div>
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'attendance'])</span><span class="stat"><b>{{ $stats['active'] }}</b><span>Active</span></span></div>
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'calendar'])</span><span class="stat"><b>{{ $stats['pending'] }}</b><span>Pending</span></span></div>
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
        @foreach($schools as $s)
          <tr>
            <td><strong>{{ $s->name }}</strong></td>
            <td class="muted">{{ $s->slug }}.{{ config('app.domain', 'localhost') }}</td>
            <td>
              <span class="badge {{ $s->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($s->status) }}</span>
            </td>
            <td style="text-align:right">
              <a class="btn btn-ghost btn-sm" href="{{ $s->portalUrl() }}" target="_blank" rel="noopener">Open</a>
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
