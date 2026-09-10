@extends('layouts.app', ['portal' => 'admin'])
@section('title', $school->name)
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">{{ $school->name }}</h1>
    <p class="subtitle">{{ $school->slug }}.{{ config('app.domain','localhost') }}</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.index') }}">Back</a>
    <a class="btn btn-primary btn-sm" href="{{ $school->portalUrl() }}" target="_blank" rel="noopener">Open portal</a>
  </div>
</div>
<div class="card" style="margin-bottom:1.25rem">
  <table>
    <tbody>
      <tr><th>Status</th><td>{{ ucfirst($school->status) }}</td></tr>
      <tr><th>Database</th><td class="muted">{{ $school->database }}</td></tr>
      <tr>
        <th>Name</th>
        <td>
          <form method="POST" action="{{ route('admin.schools.name', $school) }}" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
            @csrf
            <input name="name" value="{{ $school->name }}" maxlength="150" required style="max-width:18rem" />
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </form>
          <p class="hint" style="margin-top:.3rem">This is the school's own display name, everywhere it appears. It does not change the web address below.</p>
        </td>
      </tr>
      <tr>
        <th>Web address</th>
        <td>
          <form method="POST" action="{{ route('admin.schools.slug', $school) }}" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
            @csrf
            <input name="slug" value="{{ $school->slug }}" maxlength="60" pattern="[a-zA-Z0-9]+" required style="max-width:12rem" />
            <span class="muted">.{{ config('app.domain') }}</span>
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </form>
          <p class="hint" style="margin-top:.3rem">
            Letters and numbers only. The school's data is untouched by a change here, but the old
            address stops working immediately, so anyone with it bookmarked will need the new one.
          </p>
        </td>
      </tr>
      <tr><th>Contact email</th><td>{{ $school->email ?: 'Not set' }}</td></tr>
      <tr><th>Contact phone</th><td>{{ $school->phone ?: 'Not set' }}</td></tr>
      <tr><th>Provisioned</th><td>{{ $school->provisioned_at?->toDayDateTimeString() ?: 'No' }}</td></tr>
      <tr>
        <th>Next billing date</th>
        <td>
          <form method="POST" action="{{ route('admin.schools.billing-date', $school) }}" style="display:flex;gap:.5rem;align-items:center">
            @csrf
            <input type="date" name="next_billing_date" value="{{ $school->next_billing_date?->toDateString() }}" style="max-width:11rem" />
            <button class="btn btn-ghost btn-sm" type="submit">Save</button>
          </form>
          <p class="hint" style="margin-top:.3rem">Set by hand for now, there is no automatic invoicing yet.</p>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Administrators</h3>
  <p class="muted" style="margin-bottom:.8rem">You can reset a school administrator's password, revoke their access without touching the school itself, or add another administrator.</p>
  @if($admins->isEmpty())
    <p class="muted">No administrator on record. Add one below.</p>
  @else
    <form id="bulkAdminsForm" method="POST" action="{{ route('admin.schools.admins.bulk-action', $school) }}">@csrf</form>
    <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="admin-select-all" aria-label="Select all administrators" /></th>
        <th>Name</th><th>Email</th><th>Access</th><th></th>
      </tr></thead>
      <tbody>
        @foreach($admins as $admin)
          <tr>
            <td><input type="checkbox" form="bulkAdminsForm" class="admin-row-check" name="user_ids[]" value="{{ $admin->id }}" aria-label="Select {{ $admin->email }}" /></td>
            <td><strong>{{ $admin->name }}</strong></td>
            <td class="muted">{{ $admin->email }}</td>
            <td><span class="badge {{ $admin->pivot->status === 'active' ? 'badge-ok' : 'badge-warn' }}">{{ ucfirst($admin->pivot->status) }}</span></td>
            <td>
              <div class="stack" style="flex-direction:row;gap:.4rem;flex-wrap:wrap">
                <form method="POST" action="{{ route('admin.schools.admins.reset-password', ['school' => $school->id, 'user' => $admin->id]) }}" onsubmit="return confirm('Reset the password for {{ $admin->email }}? A new one will be generated.')">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">Reset password</button>
                </form>
                <form method="POST" action="{{ route('admin.schools.admins.toggle-access', ['school' => $school->id, 'user' => $admin->id]) }}">
                  @csrf
                  <button class="btn btn-ghost btn-sm" type="submit">{{ $admin->pivot->status === 'active' ? 'Revoke access' : 'Restore access' }}</button>
                </form>
                @if($admin->pivot->status === 'active')
                  {{-- A reason is required before signing in as someone
                       (2026-08-16): this shows real children's records,
                       and the school's own audit trail now records why,
                       not just that it happened. --}}
                  <details>
                    <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">View as this admin</summary>
                    <form method="POST" action="{{ route('admin.schools.admins.impersonate', ['school' => $school->id, 'user' => $admin->id]) }}" style="margin-top:.5rem;min-width:18rem">
                      @csrf
                      <div class="field">
                        <label for="reason_{{ $admin->id }}">Reason for access</label>
                        <input id="reason_{{ $admin->id }}" name="reason" required minlength="5" maxlength="200"
                               placeholder="e.g. Investigating a reported report card error" />
                        <div class="hint">Recorded in this school's own audit trail, visible to them.</div>
                      </div>
                      <button class="btn btn-primary btn-sm" type="submit">Sign in as {{ $admin->name }}</button>
                    </form>
                  </details>
                @endif
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of administrators above, then choose an action.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button form="bulkAdminsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="restore">Restore access</button>
        <button form="bulkAdminsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="revoke">Revoke access</button>
        <button form="bulkAdminsForm" class="btn btn-ghost btn-sm" type="submit" name="action" value="reset-password" onclick="return confirm('Reset the password for every selected administrator? New ones will be generated and emailed.')">Reset password</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('admin-select-all');
        var boxes = document.querySelectorAll('.admin-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkAdminsForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one administrator first.'); }
        });
      })();
    </script>
  @endif
</div>

<div class="card" style="max-width:32rem">
  <h3 style="margin-bottom:.8rem">Add another administrator</h3>
  <form method="POST" action="{{ route('admin.schools.admins.store', ['school' => $school->id]) }}">
    @csrf
    <div class="field">
      <label for="a_name">Name</label>
      <input id="a_name" name="name" required />
    </div>
    <div class="field">
      <label for="a_email">Email</label>
      <input id="a_email" name="email" type="email" required />
    </div>
    <div class="field">
      <label for="a_password">Password</label>
      <input id="a_password" name="password" type="password" minlength="8" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Add administrator</button>
  </form>
</div>

<div class="card" style="max-width:32rem;margin-top:1.25rem" id="clone">
  <h3 style="margin-bottom:.4rem">Clone this school</h3>
  <p class="muted" style="margin-bottom:.8rem">Creates a brand new school with the same classes, subjects, grading scales, houses, and look and feel. Student, staff, and parent records are never copied.</p>
  <form method="POST" action="{{ route('admin.schools.clone', ['school' => $school->id]) }}">
    @csrf
    <div class="field">
      <label for="c_name">New school name</label>
      <input id="c_name" name="name" required />
    </div>
    <div class="field">
      <label for="c_slug">Address (optional)</label>
      <input id="c_slug" name="slug" placeholder="auto generated from the name" />
    </div>
    <div class="field">
      <label for="c_admin_name">New administrator name</label>
      <input id="c_admin_name" name="admin_name" required />
    </div>
    <div class="field">
      <label for="c_admin_email">New administrator email</label>
      <input id="c_admin_email" name="admin_email" type="email" required />
    </div>
    <div class="field">
      <label for="c_admin_password">New administrator password</label>
      <input id="c_admin_password" name="admin_password" type="password" minlength="8" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Clone {{ $school->name }}</button>
  </form>
</div>

<div class="card" style="max-width:32rem;margin-top:1.25rem" id="license">
  <h3 style="margin-bottom:.4rem">Self-hosted license</h3>
  <p class="muted" style="margin-bottom:.8rem">For a school running its own copy of SKULOS on its own server. That installation calls home on a schedule to verify its license; if it cannot, a warning shows there, then a grace period, then full lockout, all disclosed to the school.</p>
  @if($school->is_self_hosted)
    <table style="margin-bottom:1rem">
      <tbody>
        <tr><th>License key</th><td><code>{{ $school->license_key }}</code></td></tr>
        <tr><th>Issued</th><td>{{ $school->license_issued_at?->toDayDateTimeString() ?: 'Not set' }}</td></tr>
        <tr><th>Last check-in</th><td>{{ $school->license_last_verified_at?->toDayDateTimeString() ?: 'Never checked in yet' }}</td></tr>
        <tr>
          <th>Status</th>
          <td>
            @if($school->license_grace_started_at)
              <span class="badge badge-warn">In grace period</span>
            @elseif($school->suspension_reason === \App\Models\School::SUSPENSION_REASON_LICENSE_EXPIRED)
              <span class="badge badge-bad">Locked out</span>
            @else
              <span class="badge badge-ok">Verified</span>
            @endif
          </td>
        </tr>
      </tbody>
    </table>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
      <form method="POST" action="{{ route('admin.schools.license.regenerate', $school) }}" onsubmit="return confirm('The current key will stop working immediately. Continue?')">
        @csrf
        <button class="btn btn-ghost btn-sm" type="submit">Regenerate key</button>
      </form>
      <form method="POST" action="{{ route('admin.schools.license.revoke', $school) }}" onsubmit="return confirm('This school will fail its next check-in and its own grace period will begin. Continue?')">
        @csrf
        <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Revoke self-hosting</button>
      </form>
    </div>
  @else
    <form method="POST" action="{{ route('admin.schools.license.enable', $school) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Enable self-hosted licensing</button>
    </form>
  @endif
</div>

<div class="card" style="margin-top:1.25rem">
  <div class="row-between" style="margin-bottom:.8rem">
    <h3>Backups</h3>
    <form method="POST" action="{{ route('admin.schools.backups.store', $school->id) }}">
      @csrf
      <button class="btn btn-primary btn-sm" type="submit">Back up now</button>
    </form>
  </div>

  <b style="font-size:.82rem">Automatic backups</b>
  <p class="muted" style="margin-bottom:.6rem">Set a default schedule so this happens on its own, without the school admin needing to remember.</p>
  <form method="POST" action="{{ route('admin.schools.backups.schedule', $school->id) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.1rem">
    @csrf
    <div class="field" style="margin-bottom:0">
      <label for="backup_frequency">Frequency</label>
      <select id="backup_frequency" name="backup_frequency">
        <option value="none" @selected($school->backup_frequency === 'none' || ! $school->backup_frequency)>Off</option>
        <option value="daily" @selected($school->backup_frequency === 'daily')>Daily</option>
        <option value="weekly" @selected($school->backup_frequency === 'weekly')>Weekly (Monday)</option>
        <option value="monthly" @selected($school->backup_frequency === 'monthly')>Monthly (1st)</option>
      </select>
    </div>
    <div class="field" style="margin-bottom:0">
      <label for="backup_time">Time of day</label>
      <input id="backup_time" name="backup_time" type="time" value="{{ $school->backup_time ? substr($school->backup_time,0,5) : '02:00' }}" />
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Save schedule</button>
  </form>

  @if($backups->isEmpty())
    <p class="muted">No backups yet.</p>
  @else
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Taken</th><th>Type</th><th>Size</th><th></th></tr></thead>
      <tbody>
        @foreach($backups as $backup)
          <tr>
            <td>{{ $backup->created_at->toDayDateTimeString() }}</td>
            <td class="muted">{{ ucfirst($backup->type) }}</td>
            <td class="muted">{{ $backup->humanSize() }}</td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.backups.download', ['school' => $school->id, 'backupId' => $backup->id]) }}">Download</a>
              <form method="POST" action="{{ route('admin.schools.backups.restore', ['school' => $school->id, 'backupId' => $backup->id]) }}" style="display:inline" onsubmit="return confirm('Restore {{ $school->name }} from this backup? Everything created after {{ $backup->created_at->toDayDateTimeString() }} will be lost.')">
                @csrf
                <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Restore</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.4rem">Billing</h3>
  @php($effectivePrice = $school->price_per_student_per_term_override ?? $school->plan?->price_per_student_per_term)
  <p class="muted" style="margin-bottom:.8rem">Current plan: <strong style="color:var(--ink)">{{ $school->plan?->name ?? 'No plan set' }}</strong>{{ $effectivePrice !== null ? ' (&#8358;'.number_format((float) $effectivePrice, 2).' per student per term'.($school->price_per_student_per_term_override !== null ? ', custom price' : '').')' : '' }}. Manage plans and pricing from <a href="{{ route('admin.billing.index') }}">Billing</a>.</p>

  <b style="font-size:.82rem">Optional modules for this school</b>
  <p class="muted" style="margin-bottom:.6rem">These add to (or override) whatever the plan includes.</p>
  <div class="module-grid" style="margin-bottom:1.1rem">
    @foreach($optionalModules as $code => $meta)
      <form method="POST" action="{{ route('admin.billing.schools.toggle-feature', $school) }}" style="display:inline">
        @csrf
        <input type="hidden" name="module" value="{{ $code }}" />
        <button class="btn btn-sm {{ ($moduleStates[$code] ?? 'disabled') === 'enabled' ? 'btn-primary' : 'btn-ghost' }}" type="submit">{{ $meta['name'] }}</button>
      </form>
    @endforeach
  </div>

  <b style="font-size:.82rem">Add ons</b>
  <p class="muted" style="margin-bottom:.6rem">Purchasable on any plan, regardless of tier.</p>
  @if($schoolAddOns->isEmpty())
    <p class="muted" style="font-size:.85rem;margin:.4rem 0 .8rem">None on record.</p>
  @else
    <ul style="list-style:none;margin:.5rem 0 1rem;display:flex;flex-direction:column;gap:.4rem">
      @foreach($schoolAddOns as $addOn)
        <li style="display:flex;justify-content:space-between;align-items:center;background:var(--bg);border-radius:8px;padding:.5rem .7rem;font-size:.85rem">
          <span><strong>{{ $addOn->catalogEntry()['name'] ?? $addOn->add_on_code }}</strong>@if($addOn->note) &middot; {{ $addOn->note }}@endif</span>
          <form method="POST" action="{{ route('admin.billing.add-ons.destroy', $addOn) }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
          </form>
        </li>
      @endforeach
    </ul>
  @endif
  <form method="POST" action="{{ route('admin.billing.schools.add-ons.store', $school) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.1rem">
    @csrf
    <select name="add_on_code" required style="flex:1;min-width:12rem">
      <option value="">Select an add on</option>
      @foreach($addOnCatalog as $category)
        <optgroup label="{{ $category['label'] }}">
          @foreach($category['items'] as $code => $item)
            <option value="{{ $code }}">{{ $item['name'] }}</option>
          @endforeach
        </optgroup>
      @endforeach
    </select>
    <input name="note" placeholder="Note (optional)" style="flex:1;min-width:12rem" />
    <button class="btn btn-primary btn-sm" type="submit">Grant add on</button>
  </form>

  <b style="font-size:.82rem">Free term waivers</b>
  <p class="muted" style="margin-bottom:.6rem">Marking a term free pushes this school's next payment due date forward by the number of days you give, at no cost — it's a real billing action, not just a note.</p>
  @if($waivers->isEmpty())
    <p class="muted" style="font-size:.85rem;margin:.4rem 0 .8rem">None on record.</p>
  @else
    <ul style="list-style:none;margin:.5rem 0 1rem;display:flex;flex-direction:column;gap:.4rem">
      @foreach($waivers as $waiver)
        <li style="display:flex;justify-content:space-between;align-items:center;background:var(--bg);border-radius:8px;padding:.5rem .7rem;font-size:.85rem">
          <span><strong>{{ $waiver->label }}</strong>@if($waiver->note) &middot; {{ $waiver->note }}@endif</span>
          <form method="POST" action="{{ route('admin.billing.waivers.destroy', $waiver) }}">
            @csrf @method('DELETE')
            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
          </form>
        </li>
      @endforeach
    </ul>
  @endif
  <form method="POST" action="{{ route('admin.billing.schools.waivers.store', $school) }}" style="display:flex;gap:.5rem;flex-wrap:wrap">
    @csrf
    <input name="label" placeholder="e.g. First Term 2025/2026" required style="flex:1;min-width:12rem" />
    <input name="days" type="number" min="1" max="730" placeholder="Days, e.g. 90-120 for one term" required style="width:10rem" />
    <input name="note" placeholder="Note (optional)" style="flex:1;min-width:12rem" />
    <button class="btn btn-primary btn-sm" type="submit">Mark free</button>
  </form>
</div>

<style>
  .module-grid{display:flex;flex-wrap:wrap;gap:.5rem}
</style>
@endsection
