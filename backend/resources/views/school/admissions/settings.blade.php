@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Admissions Settings'])
@section('title', 'Admissions Settings')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Admissions Settings</h1>
    <p class="subtitle">Which stages an applicant moves through, how long their account stays usable, and which classes are open right now.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.form-builder.index', ['school' => $school->slug]) }}">Form builder</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.pipeline', ['school' => $school->slug]) }}">Applicants</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.fees.index', ['school' => $school->slug]) }}">Fee payments</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.admissions.document-review.index', ['school' => $school->slug]) }}">Document review</a>
  </div>
</div>

@if(session('status'))
  <div class="flash flash-ok" style="margin-bottom:1.25rem">{{ session('status') }}</div>
@endif

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Admission pipeline</h3>
  <p class="muted" style="margin-bottom:.8rem">The stages every applicant moves through, in this order. Add, rename, remove, or reorder them however suits your school.</p>
  <div class="stack" style="gap:.5rem;margin-bottom:1rem;max-width:44rem">
    @foreach($stages as $stage)
      {{-- Real width bug (user reports 2026-09-09, two rounds of
           screenshots): this row packed 4 sibling <form>s onto one
           unwrapped flex line, and the label field's bare "flex:1"
           computed from a zero flex-basis with no floor, collapsing to
           a character or two wide on a narrow screen. flex-wrap plus a
           min-width fixed the collapse, but a single-line <input> can
           never word-wrap by definition -- the fuller stage names were
           still just getting clipped inside a too-narrow box. A plain
           <input> genuinely cannot wrap its own text; only a <textarea>
           can, so this is now one, sized for two visible lines and
           given real room to grow (the whole row's own max-width was
           also raised, it was capped tighter than the row actually
           needed on anything wider than a phone). --}}
      <div style="display:flex;align-items:flex-start;gap:.6rem;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:.5rem .8rem">
        <span class="muted" style="font-weight:700;min-width:1.4rem;padding-top:.5em">{{ $loop->index + 1 }}.</span>
        <form method="POST" action="{{ route('school.admissions.settings.stages.update', ['school' => $school->slug, 'stageId' => $stage->id]) }}" style="flex:1 1 18rem;min-width:16rem;display:flex;gap:.4rem;align-items:flex-start">
          @csrf
          {{-- A stage label is conceptually one line, just visually
               wrapped when it's long -- Enter is blocked so a stray
               keypress can't turn it into a genuine multi-line value. --}}
          <textarea name="label" rows="2" style="flex:1;min-width:10rem;resize:vertical" onkeydown="if(event.key==='Enter'){event.preventDefault();}">{{ $stage->label }}</textarea>
          <button class="btn btn-ghost btn-sm" type="submit" style="flex-shrink:0">Save</button>
        </form>
        <form method="POST" action="{{ route('school.admissions.settings.stages.move', ['school' => $school->slug, 'stageId' => $stage->id, 'direction' => 'up']) }}" style="flex-shrink:0">
          @csrf
          <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->first)>&uarr;</button>
        </form>
        <form method="POST" action="{{ route('school.admissions.settings.stages.move', ['school' => $school->slug, 'stageId' => $stage->id, 'direction' => 'down']) }}" style="flex-shrink:0">
          @csrf
          <button class="btn btn-ghost btn-sm" type="submit" @disabled($loop->last)>&darr;</button>
        </form>
        <form method="POST" action="{{ route('school.admissions.settings.stages.destroy', ['school' => $school->slug, 'stageId' => $stage->id]) }}" onsubmit="return confirm('Remove this stage?')" style="flex-shrink:0">
          @csrf @method('DELETE')
          <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit" @disabled($stages->count() <= 1)>Remove</button>
        </form>
      </div>
    @endforeach
  </div>
  <form method="POST" action="{{ route('school.admissions.settings.stages.store', ['school' => $school->slug]) }}" style="display:flex;gap:.5rem;max-width:44rem;align-items:flex-start">
    @csrf
    <textarea name="label" rows="2" placeholder="e.g. Entrance Assessment" required style="flex:1;min-width:10rem;resize:vertical" onkeydown="if(event.key==='Enter'){event.preventDefault();}"></textarea>
    <button class="btn btn-primary btn-sm" type="submit" style="flex-shrink:0">Add stage</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Account settings</h3>
  <form method="POST" action="{{ route('school.admissions.settings.account', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:1rem;max-width:32rem">
      <div class="field">
        <label for="account_ttl_days">Applicant account window (days)</label>
        <input id="account_ttl_days" name="account_ttl_days" type="number" min="1" max="365" value="{{ $ttlDays }}" required />
        <div class="hint">An applicant is locked out and archived once this many days pass after their credentials are issued.</div>
      </div>
      <div class="field">
        <label for="password_length">Generated password length</label>
        <input id="password_length" name="password_length" type="number" min="6" max="24" value="{{ $passwordLength }}" required />
        <div class="hint">How long the sign in password issued once the application fee clears should be.</div>
      </div>
      <div class="field">
        <label for="password_style">Generated password style</label>
        <select id="password_style" name="password_style" required>
          <option value="random" @selected($passwordStyle === 'random')>Random (letters, numbers, symbols)</option>
          <option value="alphanumeric" @selected($passwordStyle === 'alphanumeric')>Letters and numbers only</option>
          <option value="numeric" @selected($passwordStyle === 'numeric')>Numbers only (PIN style)</option>
        </select>
        <div class="hint">Numbers only is easiest to read out over the phone to a parent.</div>
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save account settings</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Application reference number format</h3>
  <p class="muted" style="margin-bottom:.8rem">How each applicant's reference/admission number is generated.</p>
  <form method="POST" action="{{ route('school.admissions.settings.reference-format', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:1rem;max-width:32rem">
      <div class="field">
        <label for="reference_style">Format</label>
        <select id="reference_style" name="reference_style" required onchange="document.getElementById('reference_prefix_field').style.display = this.value === 'custom' ? '' : 'none'">
          <option value="custom" @selected($referenceStyle === 'custom')>Custom prefix + serial (e.g. APP/0001)</option>
          <option value="structured" @selected($referenceStyle === 'structured')>School + class + serial (e.g. GA/P1/0001)</option>
        </select>
      </div>
      <div class="field" id="reference_prefix_field" style="{{ $referenceStyle === 'structured' ? 'display:none' : '' }}">
        <label for="reference_prefix">Prefix</label>
        <input id="reference_prefix" name="reference_prefix" type="text" maxlength="20" value="{{ $referencePrefix }}" />
      </div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Save reference format</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Admission/offer letter content</h3>
  <p class="muted" style="margin-bottom:.8rem">
    The two body paragraphs of the printable admission letter sent to an offered applicant.
    You can use <code>{applicant_name}</code>, <code>{school_name}</code>, <code>{class}</code>, and <code>{session}</code> anywhere in the text.
  </p>
  <form method="POST" action="{{ route('school.admissions.settings.letter-content', ['school' => $school->slug]) }}">
    @csrf
    <div class="field">
      <label for="letter_intro">Opening paragraph</label>
      <textarea id="letter_intro" name="letter_intro" rows="3" maxlength="2000" required>{{ old('letter_intro', $letterIntro) }}</textarea>
    </div>
    <div class="field">
      <label for="letter_next_steps">Next steps paragraph</label>
      <textarea id="letter_next_steps" name="letter_next_steps" rows="3" maxlength="2000" required>{{ old('letter_next_steps', $letterNextSteps) }}</textarea>
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save letter content</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Open a new admission window</h3>
  <form method="POST" action="{{ route('school.admissions.settings.windows.store', ['school' => $school->slug]) }}">
    @csrf
    <div class="grid cols-2" style="gap:1rem">
      <div class="field">
        <label for="window_name">Name (optional)</label>
        <input id="window_name" name="name" placeholder="e.g. 2026/2027 New Admissions" value="{{ old('name') }}" />
      </div>
      <div class="field">
        <label for="session_id">Session</label>
        <select id="session_id" name="session_id" required>
          <option value="">Choose a session</option>
          @foreach($sessions as $session)
            <option value="{{ $session->id }}">{{ $session->name }}</option>
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
        <label for="acceptance_fee_amount">Acceptance fee (&#8358;, optional)</label>
        <input id="acceptance_fee_amount" name="acceptance_fee_amount" type="number" step="0.01" min="0" />
        <div class="hint">Paid once an offer is made, before enrollment is completed. Leave blank if this school does not charge one.</div>
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
    <div class="field" style="margin-top:.5rem">
      <label>Classes applying for</label>
      <div class="grid cols-3" style="gap:.4rem .75rem">
        @foreach($classes as $class)
          <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
            <input type="checkbox" name="school_class_id[]" value="{{ $class->id }}" />
            <span>{{ $class->section?->name }} &middot; {{ $class->name }}</span>
          </label>
        @endforeach
      </div>
      <div class="hint">Tick as many as apply. They all share this same name, fees and dates as one admission window.</div>
    </div>
    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:.8rem">Open window</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-bottom:.8rem">Admission windows</h3>
  @if($batches->isEmpty())
    <p class="muted">No admission windows yet.</p>
  @else
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr><th>Name</th><th>Session</th><th>Classes</th><th>Campus</th><th>Fees</th><th>Opens</th><th>Closes</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach($batches as $batch)
          <tr>
            <td data-label="Name"><strong>{{ $batch->name ?: 'Untitled window' }}</strong></td>
            <td data-label="Session">{{ $batch->session?->name }}</td>
            <td class="muted" data-label="Classes">{{ $batch->classNames->implode(', ') }}</td>
            <td class="muted" data-label="Campus">{{ $batch->campus?->name ?? 'Any' }}</td>
            <td class="muted" data-label="Fees">
              &#8358;{{ number_format($batch->application_fee_amount, 2) }} application
              @if($batch->requires_acceptance_fee)
                <br>&#8358;{{ number_format($batch->acceptance_fee_amount, 2) }} acceptance
              @endif
            </td>
            <td class="muted" data-label="Opens">{{ $batch->opens_at?->toFormattedDateString() ?? 'Immediately' }}</td>
            <td class="muted" data-label="Closes">{{ $batch->closes_at?->toFormattedDateString() ?? 'No end date' }}</td>
            <td data-label="Status">
              <span class="badge {{ $batch->is_open ? 'badge-ok' : 'badge-warn' }}">{{ $batch->is_open ? 'Open' : ($batch->is_active ? 'Not yet open or closed' : 'Closed') }}</span>
            </td>
            <td style="white-space:nowrap">
              <details style="display:inline-block">
                <summary class="btn btn-ghost btn-sm" style="display:inline-block;cursor:pointer">Edit</summary>
                <form method="POST" action="{{ route('school.admissions.settings.windows.update', ['school' => $school->slug, 'batchUuid' => $batch->batch_uuid]) }}" style="margin-top:.6rem;min-width:16rem;text-align:left">
                  @csrf
                  <div class="field">
                    <label for="wn_{{ $batch->batch_uuid }}">Name</label>
                    <input id="wn_{{ $batch->batch_uuid }}" name="name" value="{{ $batch->name }}" />
                  </div>
                  <div class="field">
                    <label for="waf_{{ $batch->batch_uuid }}">Application fee (&#8358;)</label>
                    <input id="waf_{{ $batch->batch_uuid }}" name="application_fee_amount" type="number" step="0.01" min="0" value="{{ $batch->application_fee_amount }}" required />
                  </div>
                  <div class="field">
                    <label for="wcf_{{ $batch->batch_uuid }}">Acceptance fee (&#8358;, optional)</label>
                    <input id="wcf_{{ $batch->batch_uuid }}" name="acceptance_fee_amount" type="number" step="0.01" min="0" value="{{ $batch->acceptance_fee_amount }}" />
                  </div>
                  <div class="field">
                    <label for="wo_{{ $batch->batch_uuid }}">Opens</label>
                    <input id="wo_{{ $batch->batch_uuid }}" name="opens_at" type="date" value="{{ $batch->opens_at?->toDateString() }}" />
                  </div>
                  <div class="field">
                    <label for="wc_{{ $batch->batch_uuid }}">Closes</label>
                    <input id="wc_{{ $batch->batch_uuid }}" name="closes_at" type="date" value="{{ $batch->closes_at?->toDateString() }}" />
                  </div>
                  <button class="btn btn-primary btn-sm" type="submit">Save</button>
                </form>
              </details>
              <form method="POST" action="{{ route('school.admissions.settings.windows.toggle', ['school' => $school->slug, 'batchUuid' => $batch->batch_uuid]) }}" style="display:inline">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">{{ $batch->is_active ? 'Close' : 'Reopen' }}</button>
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
