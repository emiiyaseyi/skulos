@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Schools'])
@section('title', 'Schools')
@section('heading', 'Schools')
@section('crumb', 'Create, open, and manage every school on SKULOS')
@section('content')
<div class="grid cols-3" style="margin-bottom:1.5rem">
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'school'])</span><span class="stat"><b>{{ $stats['schools'] }}</b><span>Schools</span></span></div>
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'attendance'])</span><span class="stat"><b>{{ $stats['active'] }}</b><span>Active</span></span></div>
  <div class="card stat-card"><span class="stat-ic">@include('layouts.partials.icon',['name'=>'calendar'])</span><span class="stat"><b>{{ $stats['pending'] }}</b><span>Pending</span></span></div>
</div>

<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <h3>Create a school</h3>
    <p style="margin-bottom:1.1rem">A new school gets its own database, calendar defaults, subdomain, and an administrator who signs in to finish setup.</p>
    <form method="POST" action="{{ route('admin.schools.store') }}">
      @csrf
      <div class="field">
        <label for="name">School name</label>
        <input id="name" name="name" value="{{ old('name') }}" required placeholder="Greenfield Academy" />
        @error('name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="slug">Subdomain</label>
          <input id="slug" name="slug" value="{{ old('slug') }}" placeholder="greenfield" />
          <div class="hint">Blank = from name. Becomes yourslug.{{ config('app.domain', 'localhost') }}</div>
          @error('slug')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label for="phone">Contact phone</label>
          <input id="phone" name="phone" value="{{ old('phone') }}" />
        </div>
      </div>

      <div style="border-top:1px solid var(--line);margin:.3rem 0 1rem;padding-top:1rem">
        <b style="font-size:.85rem">School administrator login</b>
        <p class="hint" style="margin-bottom:.8rem">The details this school's administrator will sign in with to complete setup.</p>
      </div>
      <div class="field">
        <label for="admin_name">Administrator name</label>
        <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required placeholder="Jane Doe" />
        @error('admin_name')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="grid cols-2">
        <div class="field">
          <label for="admin_email">Administrator email</label>
          <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required placeholder="admin@school.com" />
          @error('admin_email')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label for="admin_password">Temporary password</label>
          <input id="admin_password" name="admin_password" type="text" required placeholder="at least 8 characters" />
          <div class="hint">Share this with the administrator.</div>
          @error('admin_password')<div class="err">{{ $message }}</div>@enderror
        </div>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Create school and admin</button>
    </form>
  </div>

  <div class="card">
    <h3>Every school</h3>
    <p style="margin-bottom:1rem">Manage a school's administrators, branding, and lifecycle, or open its portal.</p>
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
            <td style="text-align:right;white-space:nowrap">
              <a class="btn btn-primary btn-sm" href="{{ route('admin.schools.show', $s) }}">Manage</a>
              @if($s->status === 'active')
                <a class="btn btn-ghost btn-sm" href="{{ $s->portalUrl() }}" target="_blank" rel="noopener">Visit</a>
                <form method="POST" action="{{ route('admin.schools.suspend', $s) }}" style="display:inline">@csrf<button class="btn btn-ghost btn-sm">Suspend</button></form>
                <form method="POST" action="{{ route('admin.schools.archive', $s) }}" style="display:inline">@csrf<button class="btn btn-ghost btn-sm">Archive</button></form>
              @else
                <form method="POST" action="{{ route('admin.schools.activate', $s) }}" style="display:inline">@csrf<button class="btn btn-primary btn-sm">Resume</button></form>
              @endif
              <form method="POST" action="{{ route('admin.schools.destroy', $s) }}" style="display:inline" onsubmit="return confirm('Remove {{ $s->name }}? Its data is retained and it can be restored.')">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm" style="color:var(--bad)">Delete</button></form>
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
