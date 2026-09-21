@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'School Profile'])
@section('title', 'School Profile')

@push('head')
<style>
  .scope-choice{display:flex;gap:1.2rem;margin:.5rem 0 .5rem}
  .scope-choice label{display:flex;align-items:center;gap:.4rem;font-size:.85rem;font-weight:600;cursor:pointer}
  .section-ticks{display:none;flex-wrap:wrap;gap:.6rem;margin:.3rem 0 .8rem;padding:.7rem;border:1px solid var(--line);border-radius:10px;background:var(--bg)}
  .section-ticks.open{display:flex}
  .section-ticks label{display:flex;align-items:center;gap:.4rem;font-size:.85rem;font-weight:500;cursor:pointer}
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">School Profile</h1>
    <p class="subtitle">Your school's own institutional details.</p>
  </div>
</div>

<div class="card" style="max-width:34rem">
  <div class="field">
    <label>School name</label>
    <input value="{{ $school->name }}" disabled />
    <p class="hint">Contact SKULOS to change your school's name.</p>
  </div>

  <form method="POST" action="{{ route('school.settings.profile.update', ['school' => $school->slug]) }}" enctype="multipart/form-data">
    @csrf

    <div class="field">
      <label for="logo">Logo</label>
      @if($school->logo_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($school->logo_path) }}" alt="Current school logo" style="height:56px;display:block;margin-bottom:.4rem" />
      @endif
      <input id="logo" name="logo" type="file" accept="image/*" />
      <p class="hint">A square image works best. Up to 2 MB. Shared with Branding.</p>
      @if($sections->count() > 1)
        {{-- User request 2026-09-09: the per-section option Branding
             offers should be reachable from here too. --}}
        @include('school._scope-picker', ['field' => 'logo', 'sections' => $sections])
        <p class="hint" style="margin:-.3rem 0 .7rem">Who this upload is for. Untouched sections keep whatever they already have. "Whole school" is the one that updates everywhere; a section-specific upload only changes that section.</p>
        @error('logo_sections')<div class="err">{{ $message }}</div>@enderror
        <script>
          document.querySelectorAll('input[data-scope="logo"]').forEach(function(radio){
            radio.addEventListener('change', function(){
              var panel = document.querySelector('.section-ticks[data-scope-panel="logo"]');
              if (panel) panel.classList.toggle('open', this.value === 'sections');
            });
          });
        </script>
      @endif
    </div>

    <div class="field">
      <label for="motto">Motto</label>
      <input id="motto" name="motto" maxlength="150" value="{{ old('motto', $school->motto) }}" />
    </div>

    <div class="field">
      <label for="term_label">Term wording</label>
      <input id="term_label" name="term_label" maxlength="40" placeholder="Term" value="{{ old('term_label', $termLabel) }}" />
      <p class="hint">What your school calls a "Term", e.g. Trimester or Semester. Shown on report cards, Sessions &amp; {{ $termLabel }}s, and new {{ strtolower($termLabel) }}s created from here on. Leave blank to keep "Term". This doesn't rename a {{ strtolower($termLabel) }} you've already created, e.g. "First Term 2026/2027"; edit that directly from Sessions &amp; {{ $termLabel }}s.</p>
    </div>

    <div class="grid cols-2">
      <div class="field">
        <label for="registration_number">Registration number</label>
        <input id="registration_number" name="registration_number" maxlength="100" value="{{ old('registration_number', $school->registration_number) }}" />
      </div>
      <div class="field">
        <label for="established_year">Year established</label>
        <input id="established_year" name="established_year" type="number" min="1800" max="{{ now()->year }}" value="{{ old('established_year', $school->established_year) }}" />
      </div>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="150" value="{{ old('email', $school->email) }}" />
    </div>

    <div class="grid cols-2">
      <div class="field">
        <label for="phone">Phone</label>
        <input id="phone" name="phone" maxlength="40" value="{{ old('phone', $school->phone) }}" />
      </div>
      <div class="field">
        <label for="alternate_phone">Alternate phone</label>
        <input id="alternate_phone" name="alternate_phone" maxlength="40" value="{{ old('alternate_phone', $school->alternate_phone) }}" />
      </div>
    </div>

    <div class="field">
      <label for="website">Website</label>
      {{-- type="text", not "url": the browser's own URL validation
           rejects a bare domain like "www.school.com" with no http(s)
           scheme (user report 2026-07-24: "its not accepting
           www.greenfieldacadmy.com as a url, which is wrong"), the
           server below adds https:// automatically when missing. --}}
      <input id="website" name="website" type="text" maxlength="200" placeholder="www.yourschool.com" value="{{ old('website', $school->website) }}" />
    </div>

    <div class="field">
      <label for="address">Address</label>
      <textarea id="address" name="address" rows="3" maxlength="500">{{ old('address', $school->address) }}</textarea>
    </div>

    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
