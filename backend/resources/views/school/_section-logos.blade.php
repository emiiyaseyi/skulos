{{--
  One logo per section, each uploaded from its own row (user directive
  2026-09-23: "adding another logo to another section, create the option
  under both school profile and branding"). Included by BOTH School Profile
  and Branding, and must sit OUTSIDE their own <form> (forms cannot nest).
  Expects $school and $sections (Section::with('branding')).
--}}
@if($sections->count() > 1)
  <div class="card" style="margin-top:1.2rem">
    <h3 style="margin-bottom:.3rem">Section logos</h3>
    <p class="hint" style="margin-bottom:.9rem">Give a section its own logo. It shows on that section's report cards and on the login page when several logos are turned on. A section without its own logo uses the school logo.</p>
    @foreach($sections as $s)
      @php($own = $s->branding?->logo_path)
      @php($shown = $own ?: $school->logo_path)
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.8rem;padding:.7rem 0;border-top:1px solid var(--line)">
        <div style="width:56px;height:56px;display:flex;align-items:center;justify-content:center;flex:none">
          @if($shown)<img src="{{ \App\Support\TenantAsset::url($shown) }}" alt="" style="max-width:56px;max-height:56px;object-fit:contain">@else<span class="muted">No logo</span>@endif
        </div>
        <div style="flex:1;min-width:8rem">
          <div style="font-weight:700">{{ $s->name }}</div>
          <div class="hint">{{ $own ? 'Has its own logo' : 'Using the school logo' }}</div>
        </div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center" action="{{ route('school.branding.sections.logo', ['school' => $school->slug, 'sectionId' => $s->id]) }}">
          @csrf
          <input type="file" name="logo" accept="image/*" required style="max-width:15rem" />
          <button class="btn btn-primary btn-sm" type="submit">{{ $own ? 'Replace logo' : 'Upload logo' }}</button>
        </form>
        @if($own)
          <form method="POST" action="{{ route('school.branding.sections.logo.remove', ['school' => $school->slug, 'sectionId' => $s->id]) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">Use school logo</button>
          </form>
        @endif
      </div>
    @endforeach
    @error('logo')<div class="err">{{ $message }}</div>@enderror
  </div>
@endif
