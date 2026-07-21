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

<div class="card" style="margin-bottom:1.25rem">
  <h3>Create a school</h3>
  <p style="margin-bottom:1.1rem">A new school gets its own database, calendar defaults, subdomain, and an administrator who signs in to finish setup.</p>
  <form method="POST" action="{{ route('admin.schools.store') }}" style="max-width:40rem">
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
    <button class="btn btn-primary" type="submit">Create school and admin</button>
  </form>
</div>

<div class="card">
  <h3>Every school</h3>
  <p style="margin-bottom:1rem">Manage a school's administrators, branding, and lifecycle, view its plan and next billing date, or open its portal.</p>
  @if($schools->isEmpty())
    <p class="muted">No schools yet. Create your first one to test the platform.</p>
  @else
    <form id="bulkSchoolsForm" method="POST" action="{{ route('admin.schools.bulk-action') }}"></form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="school-select-all" aria-label="Select all schools" /></th>
        <th>Name</th><th>Address</th><th>Plan</th><th>Next due</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($schools as $s)
        <tr>
          <td><input type="checkbox" form="bulkSchoolsForm" class="school-row-check" name="school_ids[]" value="{{ $s->id }}" aria-label="Select {{ $s->name }}" /></td>
          <td><strong>{{ $s->name }}</strong></td>
          <td class="muted">{{ $s->slug }}.{{ config('app.domain', 'localhost') }}</td>
          <td class="muted">{{ $s->plan?->name ?? 'No plan set' }}</td>
          <td class="muted">{{ $s->next_billing_date?->toFormattedDateString() ?? 'Not set' }}</td>
          <td>
            <span class="badge {{ $s->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($s->status) }}</span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <button type="button" class="btn btn-ghost btn-sm school-view-btn" data-overview="{{ route('admin.schools.overview', $s) }}" data-name="{{ $s->name }}">View</button>
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

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of schools above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkSchoolsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="activate">Resume selected</button>
        <button form="bulkSchoolsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="suspend">Suspend selected</button>
        <button form="bulkSchoolsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="archive">Archive selected</button>
        <button form="bulkSchoolsForm" class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" name="action" value="delete" onclick="return confirm('Remove the selected schools? Their data is retained and each can be restored.')">Delete selected</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('school-select-all');
        var boxes = document.querySelectorAll('.school-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkSchoolsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one school first.'); }
        });
      })();
    </script>
  @endif
</div>

{{-- School overview modal: a quick read only peek (name, address, plan,
     next billing date, live counts from the school's own tenant database)
     without navigating to the full Manage page (user request 2026-07-10). --}}
<div class="idle-scrim" id="schoolOverviewScrim">
  <div class="idle-card" style="max-width:28rem;text-align:left">
    <div class="row-between" style="margin-bottom:1rem">
      <h3 id="schoolOverviewTitle">School overview</h3>
      <button class="btn btn-ghost btn-sm" type="button" id="schoolOverviewClose">Close</button>
    </div>
    <div id="schoolOverviewBody">
      <p class="muted">Loading…</p>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  var scrim = document.getElementById('schoolOverviewScrim');
  var body = document.getElementById('schoolOverviewBody');
  var title = document.getElementById('schoolOverviewTitle');
  var closeBtn = document.getElementById('schoolOverviewClose');
  if(!scrim) return;

  function row(label, value){
    return '<div class="row-between" style="padding:.45rem 0;border-bottom:1px solid var(--line)"><span class="muted" style="font-size:.85rem">'+label+'</span><strong style="font-size:.85rem;text-align:right">'+value+'</strong></div>';
  }

  function close(){ scrim.classList.remove('show'); }
  closeBtn.addEventListener('click', close);
  scrim.addEventListener('click', function(e){ if(e.target === scrim) close(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });

  document.querySelectorAll('.school-view-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      title.textContent = btn.getAttribute('data-name');
      body.innerHTML = '<p class="muted">Loading…</p>';
      scrim.classList.add('show');

      fetch(btn.getAttribute('data-overview'), { headers: { 'Accept': 'application/json' } })
        .then(function(r){ return r.json(); })
        .then(function(d){
          var html = ''
            + row('Address', d.subdomain)
            + row('Contact email', d.email || 'Not set')
            + row('Contact phone', d.phone || 'Not set')
            + row('Status', d.status)
            + row('Plan', d.plan)
            + row('Next billing date', d.next_billing_date || 'Not set')
            + row('Administrators', d.admins);
          if(d.provisioned){
            html += row('Students', d.counts.students)
              + row('Staff', d.counts.staff)
              + row('Parents', d.counts.parents)
              + row('Classes', d.counts.classes);
          } else {
            html += '<p class="muted" style="margin-top:.6rem">Not provisioned yet, no student, staff, or parent counts to show.</p>';
          }
          body.innerHTML = html;
        })
        .catch(function(){ body.innerHTML = '<p class="muted">Could not load this school right now.</p>'; });
    });
  });
})();
</script>
@endpush
