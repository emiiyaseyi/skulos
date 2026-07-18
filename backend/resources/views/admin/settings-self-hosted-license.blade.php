@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Self-Hosted License'])
@section('title', 'Self-Hosted License')
@section('heading', 'Self-Hosted License')
@section('crumb', 'Only applies when this installation runs on its own server as a self-hosted copy')

@section('content')
<div class="card" style="max-width:32rem">
  @if(! config('skulos.self_hosted'))
    <p class="muted" style="margin-bottom:1.2rem">This installation is not configured as self-hosted (SKULOS_SELF_HOSTED is not set in its environment), so the license key below is not currently checked. Saving it here has no effect until that is set.</p>
  @else
    <p class="muted" style="margin-bottom:1.2rem">Enter the license key SKULOS issued for this school. This installation checks in with the main SKULOS platform on a schedule; if it cannot verify, a warning appears until the grace period runs out, then access locks fully.</p>
  @endif

  @if($school)
    <table style="margin-bottom:1.2rem">
      <tbody>
        <tr><th>Last check-in</th><td>{{ $school->license_last_verified_at?->toDayDateTimeString() ?: 'Never checked in yet' }}</td></tr>
        <tr>
          <th>Status</th>
          <td>
            @if($graceDaysRemaining !== null)
              <span class="badge badge-warn">In grace period, {{ $graceDaysRemaining }} day{{ $graceDaysRemaining === 1 ? '' : 's' }} left</span>
            @elseif($school->status === \App\Models\School::STATUS_SUSPENDED && $school->suspension_reason === \App\Models\School::SUSPENSION_REASON_LICENSE_EXPIRED)
              <span class="badge badge-bad">Locked out</span>
            @elseif($school->license_key)
              <span class="badge badge-ok">Verified</span>
            @else
              <span class="badge badge-warn">No license key set</span>
            @endif
          </td>
        </tr>
      </tbody>
    </table>
  @endif

  <form method="POST" action="{{ route('admin.settings.self-hosted-license.update') }}">
    @csrf
    <div class="field">
      <label for="license_key">License key</label>
      <input id="license_key" name="license_key" value="{{ old('license_key', $school->license_key ?? '') }}" required />
      @error('license_key')<p class="err">{{ $message }}</p>@enderror
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
