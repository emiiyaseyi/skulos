@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Report Settings'])
@section('title', 'Report Settings')

@push('head')
<style>
.rc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}
.rc-opt{cursor:pointer;display:block}
.rc-opt input{position:absolute;opacity:0}
.rc-card{border:2px solid var(--line);border-radius:14px;padding:1rem;background:var(--panel)}
.rc-opt input:checked + .rc-card{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
.rc-name{font-weight:800;font-size:.95rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:.2rem}
.rc-desc{font-size:.8rem;color:var(--mut);margin-bottom:.7rem;min-height:2.6em}
.rc-mini{background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:.5rem;display:grid;gap:4px;height:90px}
.rc-mini .row{height:8px;border-radius:2px;background:var(--soft);border:1px solid var(--line)}
.rc-mini .row.hi{background:color-mix(in srgb,var(--accent) 35%,var(--soft))}
.tick{width:18px;height:18px;border-radius:50%;border:2px solid var(--line);display:inline-grid;place-items:center;font-size:11px}
.rc-opt input:checked + .rc-card .tick{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.ftoggle{display:flex;align-items:center;gap:.6rem;padding:.6rem 0;border-bottom:1px solid var(--line)}
.ftoggle:last-child{border-bottom:none}
.ftoggle input{width:auto}
.fgroup{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);margin:1rem 0 .3rem}
.fgroup:first-child{margin-top:0}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Report Settings</h1>
    <p class="subtitle">Your report card look, and how report cards move through review before release.</p>
  </div>
  <div class="stack" style="flex-direction:row;gap:.6rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.layout.edit', ['school' => $school->slug]) }}">Layout designer</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.preview', ['school' => $school->slug]) }}" target="_blank" rel="noopener">Preview current look</a>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Review flow</h3>
  <p class="muted" style="margin-bottom:.8rem">Once generated, a report card moves through review before it can be released to students and parents: <strong>Pending review</strong> &rarr; a reviewer approves it, or sends it back for correction &rarr; <strong>Approved</strong> &rarr; <strong>Released</strong>. Assigning who reviews each report card, and approving or releasing it, is limited to the school owner, admin, principal, head teacher, or examination officer.</p>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.index', ['school' => $school->slug]) }}">Browse report cards</a>
    <a class="btn btn-ghost btn-sm" href="{{ route('school.report-cards.pending-review', ['school' => $school->slug]) }}">Pending review</a>
  </div>
  <form method="POST" action="{{ route('school.report-cards.review-stage', ['school' => $school->slug]) }}" style="display:flex;align-items:center;gap:.6rem;padding-top:.8rem;border-top:1px solid var(--line)">
    @csrf
    <input type="hidden" name="enabled" value="{{ $reviewStageEnabled ? '1' : '0' }}" />
    <label style="display:flex;align-items:center;gap:.5rem;margin:0">
      <input type="checkbox" @checked($reviewStageEnabled) onchange="this.form.querySelector('[name=enabled]').value=this.checked?'1':'0';this.form.submit()" />
      <span>Require review before a report card can be released</span>
    </label>
    <span class="muted">Turning this off releases every generated report card immediately, skipping approval.</span>
  </form>
  <form method="POST" action="{{ route('school.report-cards.suggested-remark', ['school' => $school->slug]) }}" style="display:flex;align-items:center;gap:.6rem;padding-top:.8rem;border-top:1px solid var(--line)">
    @csrf
    <input type="hidden" name="enabled" value="{{ $suggestedRemarkEnabled ? '1' : '0' }}" />
    <label style="display:flex;align-items:center;gap:.5rem;margin:0">
      <input type="checkbox" @checked($suggestedRemarkEnabled) onchange="this.form.querySelector('[name=enabled]').value=this.checked?'1':'0';this.form.submit()" />
      <span>Suggest a remark on the score entry screen</span>
    </label>
    <span class="muted">Turning this off stops pre filling a remark from the comment bank; teachers type their own from scratch.</span>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Review chain</h3>
  <p class="muted" style="margin-bottom:.8rem">Who a report card must pass through, in order, before it can be approved. Each stage's Approve action moves it to the next stage; the last stage's Approve makes it Approved and ready to release.</p>
  <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem">
    @foreach($reviewChain as $i => $roleSlug)
      <span class="badge" style="background:var(--soft);border:1px solid var(--line);border-radius:20px;padding:.3rem .8rem;font-size:.8rem">{{ $i + 1 }}. {{ $reviewChainRoles[$roleSlug] ?? $roleSlug }}</span>
      @if(! $loop->last)<span class="muted">&rarr;</span>@endif
    @endforeach
  </div>
  <form method="POST" action="{{ route('school.report-cards.review-chain', ['school' => $school->slug]) }}">
    @csrf
    <div class="rc-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
      @for($stage = 0; $stage < 5; $stage++)
        <label style="display:block">
          <span class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Stage {{ $stage + 1 }}{{ $stage === 0 ? ' (required)' : ' (optional)' }}</span>
          <select name="chain[]" class="input" @if($stage === 0) required @endif>
            @if($stage > 0)<option value="">Not used</option>@endif
            @foreach($reviewChainRoles as $slug => $label)
              <option value="{{ $slug }}" @selected(($reviewChain[$stage] ?? null) === $slug)>{{ $label }}</option>
            @endforeach
          </select>
        </label>
      @endfor
    </div>
    <p class="muted" style="margin:.6rem 0">Leave a stage as "Not used" to stop the chain there, later stages are then ignored.</p>
    <button class="btn btn-primary btn-sm" type="submit">Save review chain</button>
  </form>
</div>

<form method="POST" action="{{ route('school.report-cards.update', ['school' => $school->slug]) }}">
  @csrf

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:1rem">Report card look</h3>
    <div class="rc-grid">
      @foreach(config('skulos.report_card_templates') as $key => $meta)
        <label class="rc-opt">
          <input type="radio" name="template" value="{{ $key }}" @checked($currentTemplate === $key) />
          <div class="rc-card">
            <div class="rc-name">{{ $meta['label'] }} <span class="tick">&#10003;</span></div>
            <div class="rc-desc">{{ $meta['description'] }}</div>
            <div class="rc-mini">
              <div class="row hi" style="width:60%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:90%"></div>
              <div class="row" style="width:40%"></div>
            </div>
          </div>
        </label>
      @endforeach
    </div>
  </div>

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.4rem">Fields</h3>
    <p class="muted" style="margin-bottom:.4rem">Turn any field on or off. Custom additions can be requested from support.</p>
    @php($groups = ['identity' => 'Student details', 'academic' => 'Academic', 'remarks' => 'Remarks', 'evaluation' => 'Evaluation (traits, physical stats, comments, stamp, grade key)'])
    @foreach($groups as $groupKey => $groupLabel)
      <div class="fgroup">{{ $groupLabel }}</div>
      @foreach(config('skulos.report_card_fields') as $code => $meta)
        @continue($meta['group'] !== $groupKey)
        <label class="ftoggle">
          <input type="checkbox" name="fields[{{ $code }}]" value="1" @checked($fields[$code] ?? false) />
          <span>{{ $meta['label'] }}</span>
        </label>
      @endforeach
    @endforeach
  </div>

  <div class="card" style="margin-bottom:1.25rem">
    <h3 style="margin-bottom:.4rem">Footer</h3>
    <p class="muted" style="margin-bottom:.8rem">Shown at the very bottom of every report card, whichever template is chosen. Left blank, your school motto{{ $schoolMotto ? " (\"{$schoolMotto}\")" : '' }} is used instead.</p>
    <div class="field">
      <label for="footer_text">Footer text</label>
      <input id="footer_text" name="footer_text" maxlength="300" placeholder="{{ $schoolMotto ?: 'e.g. Knowledge, Character, Excellence' }}" value="{{ old('footer_text', $footerText) }}" />
    </div>
    <div class="field" style="max-width:14rem">
      <label for="footer_align">Position</label>
      <select id="footer_align" name="footer_align">
        <option value="left" @selected($footerAlign === 'left')>Left</option>
        <option value="center" @selected($footerAlign === 'center')>Center</option>
        <option value="right" @selected($footerAlign === 'right')>Right</option>
      </select>
    </div>
  </div>

  <button class="btn btn-primary" type="submit">Save report card look</button>
</form>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.4rem">Stamp and signature</h3>
  <p class="muted" style="margin-bottom:.8rem">The school wide default every report card falls back to. A class teacher can upload their own for their class from the Class Teacher Entry screen, overriding the default for that class only.</p>

  @if($canManageBranding)
    <form method="POST" action="{{ route('school.branding.update', ['school' => $school->slug]) }}" enctype="multipart/form-data" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
      @csrf
      <div class="field" style="margin:0">
        <label for="rc_stamp">School wide stamp</label>
        @if($school->stamp_path)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($school->stamp_path) }}" alt="Current school stamp" style="display:block;max-height:60px;margin-bottom:.4rem" />
        @endif
        <input id="rc_stamp" name="stamp" type="file" accept="image/*" />
      </div>
      <div class="field" style="margin:0">
        <label for="rc_signature">School wide signature</label>
        @if($school->signature_path)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($school->signature_path) }}" alt="Current school signature" style="display:block;max-height:60px;margin-bottom:.4rem" />
        @endif
        <input id="rc_signature" name="signature" type="file" accept="image/*" />
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save default stamp &amp; signature</button>
    </form>
  @else
    <p class="muted" style="margin-bottom:.8rem">Uploading a new default requires branding access. Ask your school admin, or manage it from <a href="{{ route('school.branding.edit', ['school' => $school->slug]) }}">Branding</a> if you have access there.</p>
  @endif

  <form method="POST" action="{{ route('school.report-cards.arm.stamp-signature.reset-all', ['school' => $school->slug]) }}" onsubmit="return confirm('Reset every class to the school default stamp and signature? Any class specific uploads will be cleared.');">
    @csrf
    <button class="btn btn-ghost btn-sm" type="submit">Reset all classes to the school default</button>
  </form>
</div>

<div class="card" style="margin-top:1.25rem">
  <h3 style="margin-bottom:.4rem">Signature signer</h3>
  <p class="muted" style="margin-bottom:.8rem">Who the signature above is shown as belonging to on each section's report cards. Left blank, a section defaults to Head Teacher, or Principal for a section named Secondary.</p>

  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Section</th><th>Signer</th><th></th></tr></thead>
    <tbody>
      @foreach($sections as $section)
        <tr>
          <td>{{ $section->name }}</td>
          <td>
            <form method="POST" action="{{ route('school.report-cards.section.signer', ['school' => $school->slug, 'sectionId' => $section->id]) }}" style="display:flex;gap:.5rem;align-items:center;margin:0">
              @csrf
              <input name="signer_name" value="{{ $section->signer_name }}" placeholder="{{ $section->signerLabel() }}" style="width:auto;flex:1" />
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endsection
