@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admissions Settings'])
@section('title', 'Admissions Settings')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admissions Settings</h1>
    <p class="subtitle">Which stages an applicant moves through, how long their account stays usable, and which classes are open right now.</p>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Admission pipeline</h3>
  <p class="muted" style="margin-bottom:.8rem">Every applicant moves through these stages in this fixed order. Untick an optional stage to skip it, e.g. a school with no interview step.</p>
  <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem">
    @foreach($pipelineChain as $i => $key)
      <span class="badge" style="background:var(--soft);border:1px solid var(--line);border-radius:20px;padding:.3rem .8rem;font-size:.8rem">{{ $i + 1 }}. {{ $stageCatalog[$key] ?? $key }}</span>
      @if(! $loop->last)<span class="muted">&rarr;</span>@endif
    @endforeach
  </div>
  <form method="POST" action="{{ route('school.admissions.settings.pipeline', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:.5rem .75rem;margin-bottom:1rem">
      @foreach($stageCatalog as $key => $label)
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
          <input type="checkbox" name="stages[]" value="{{ $key }}" @checked(in_array($key, $pipelineChain, true)) />
          <span>{{ $label }}</span>
        </label>
      @endforeach
    </div>
    <div class="field" style="max-width:16rem">
      <label for="account_ttl_days">Applicant account window (days)</label>
      <input id="account_ttl_days" name="account_ttl_days" type="number" min="1" max="365" value="{{ $ttlDays }}" required />
      <div class="hint">An applicant is locked out and archived once this many days pass after their credentials are issued.</div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save pipeline settings</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Open a new admission window</h3>
  <form method="POST" action="{{ route('school.admissions.settings.windows.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:1rem">
      <div class="field">
        <label for="session_id">Session</label>
        <select id="session_id" name="session_id" required>
          <option value="">Choose a session</option>
          @foreach($sessions as $session)
            <option value="{{ $session->id }}">{{ $session->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="school_class_id">Class applying for</label>
        <select id="school_class_id" name="school_class_id" required>
          <option value="">Choose a class</option>
          @foreach($classes as $class)
            <option value="{{ $class->id }}">{{ $class->section?->name }} &middot; {{ $class->name }}</option>
          @endforeach
        </select>
      </div>
      @if($campuses->isNotEmpty())
        <div class="field">
          <label for="campus_id">Campus (optional)</label>
          <select id="campus_id" name="campus_id">
            <option value="">Any campus</option>
            @foreach($campuses as $campus)
              <option value="{{ $campus->id }}">{{ $campus->name }}</option>
            @endforeach
          </select>
        </div>
      @endif
      <div class="field">
        <label for="application_fee_amount">Application fee (&#8358;)</label>
        <input id="application_fee_amount" name="application_fee_amount" type="number" step="0.01" min="0" required />
      </div>
      <div class="field">
        <label for="acceptance_fee_amount">Acceptance fee (&#8358;)</label>
        <input id="acceptance_fee_amount" name="acceptance_fee_amount" type="number" step="0.01" min="0" required />
        <div class="hint">Paid once an offer is made, before enrollment is completed.</div>
      </div>
      <div class="field">
        <label for="opens_at">Opens (optional)</label>
        <input id="opens_at" name="opens_at" type="date" />
      </div>
      <div class="field">
        <label for="closes_at">Closes (optional)</label>
        <input id="closes_at" name="closes_at" type="date" />
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.6rem">Open window</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Admission windows</h3>
  @if($windows->isEmpty())
    <p class="muted">No admission windows yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Session</th><th>Class</th><th>Campus</th><th>Fee</th><th>Opens</th><th>Closes</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($windows as $window)
          <tr>
            <td data-label="Session">{{ $window->session?->name }}</td>
            <td data-label="Class">{{ $window->schoolClass?->name }}</td>
            <td class="muted" data-label="Campus">{{ $window->campus?->name ?? 'Any' }}</td>
            <td class="muted" data-label="Fee">&#8358;{{ number_format($window->application_fee_amount, 2) }}</td>
            <td class="muted" data-label="Opens">{{ $window->opens_at?->toFormattedDateString() ?? 'Immediately' }}</td>
            <td class="muted" data-label="Closes">{{ $window->closes_at?->toFormattedDateString() ?? 'No end date' }}</td>
            <td data-label="Status">
              <span class="badge {{ $window->isOpen() ? 'badge-ok' : 'badge-warn' }}">{{ $window->isOpen() ? 'Open' : ($window->is_active ? 'Not yet open or closed' : 'Closed') }}</span>
            </td>
            <td>
              <form method="POST" action="{{ route('school.admissions.settings.windows.toggle', ['school' => $school->slug, 'windowId' => $window->id]) }}">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">{{ $window->is_active ? 'Close' : 'Reopen' }}</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $windows->links() }}</div>
  @endif
</div>
@endsection
