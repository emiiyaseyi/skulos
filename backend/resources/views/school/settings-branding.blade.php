@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Branding'])
@section('title', 'School Branding')

@push('head')
<style>
  .login-picker{display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem}
  .lpick{cursor:pointer}.lpick input{position:absolute;opacity:0}
  .lpick-card{border:2px solid var(--line);border-radius:12px;padding:.5rem;transition:border-color .15s ease}
  .lpick input:checked + .lpick-card{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 22%,transparent)}
  .lpick-name{font-size:.8rem;font-weight:700;text-align:center;margin-top:.4rem}
  .lpick-mini{height:60px;border:1px solid var(--line);border-radius:8px;overflow:hidden;display:flex;background:var(--bg)}
  .lpm-panel{background:color-mix(in srgb,var(--accent) 45%,transparent)}
  .lpm-form{flex:1;display:flex;flex-direction:column;justify-content:center;gap:4px;padding:8px}
  .lpm-form i{height:6px;border-radius:3px;background:var(--soft);border:1px solid var(--line)}
  .lpm-form b{height:9px;border-radius:3px;background:var(--accent);margin-top:2px}
  .lpm-centered .lpm-panel{display:none}.lpm-centered .lpm-form{align-items:center}.lpm-centered .lpm-form i,.lpm-centered .lpm-form b{width:70%}
  .lpm-split .lpm-panel{width:45%}
  .lpm-cover{background:color-mix(in srgb,var(--accent) 20%,var(--bg))}.lpm-cover .lpm-panel{display:none}.lpm-cover .lpm-form{align-items:center}.lpm-cover .lpm-form i,.lpm-cover .lpm-form b{width:70%}
  .lpm-showcase .lpm-panel{width:48%;background:var(--soft)}
  .color-swatches{display:grid;grid-template-columns:repeat(6,1fr);gap:.5rem;margin-bottom:.6rem}
  .color-swatch{width:100%;aspect-ratio:1;border-radius:8px;border:2px solid var(--line);cursor:pointer;padding:0}
  .color-swatch:hover{border-color:var(--accent)}
  .color-input-row{display:flex;gap:.5rem;align-items:center}
  .color-input-row input[type="color"]{width:2.6rem;height:2.6rem;padding:2px;border:1px solid var(--line);border-radius:8px;cursor:pointer;flex-shrink:0}
  .color-input-row input[type="text"]{flex:1}
  @media(max-width:480px){.color-swatches{grid-template-columns:repeat(4,1fr)}}
  .scope-choice{display:flex;gap:1.2rem;margin:.5rem 0 .5rem}
  .scope-choice label{display:flex;align-items:center;gap:.4rem;font-size:.85rem;font-weight:600;cursor:pointer}
  .section-ticks{display:none;flex-wrap:wrap;gap:.6rem;margin:.3rem 0 .8rem;padding:.7rem;border:1px solid var(--line);border-radius:10px;background:var(--bg)}
  .section-ticks.open{display:flex}
  .section-ticks label{display:flex;align-items:center;gap:.4rem;font-size:.85rem;font-weight:500;cursor:pointer}
  .section-row{display:flex;align-items:center;gap:.8rem;padding:.6rem 0;border-bottom:1px solid var(--line)}
  .section-row:last-child{border-bottom:none}
  .section-row .mark{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;color:#fff;font-weight:700;overflow:hidden;flex-shrink:0}
  .section-row .mark img{width:100%;height:100%;object-fit:contain}
  .section-row .name{flex:1;font-weight:600}
  .section-row .state{font-size:.78rem;color:var(--mut)}
</style>
@endpush


@section('content')
<div class="page-head">
  <div>
    <h1 class="title">School branding</h1>
    <p class="subtitle">Tune how your school's welcome and login page looks to your community.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.dashboard', ['school' => $school->slug]) }}">Back to dashboard</a>
</div>

@php($b = $school->branding())
<div class="grid cols-2" style="align-items:start">
  <div class="card">
    <form method="POST" action="{{ route('school.branding.update', ['school' => $school->slug]) }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="welcome_headline">Welcome headline</label>
        <input id="welcome_headline" name="welcome_headline" value="{{ old('welcome_headline', $school->welcome_headline) }}" placeholder="Welcome to {{ $school->name }}" />
        @error('welcome_headline')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="welcome_message">Welcome message</label>
        <textarea id="welcome_message" name="welcome_message" rows="3" placeholder="Sign in to continue to your school portal.">{{ old('welcome_message', $school->welcome_message) }}</textarea>
        @error('welcome_message')<div class="err">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="brand_color">Brand colour</label>
        <div class="color-swatches">
          @foreach(config('skulos.brand_color_presets') as $preset)
            <button type="button" class="color-swatch" data-color="{{ $preset }}" style="background:{{ $preset }}" aria-label="Use {{ $preset }}"></button>
          @endforeach
        </div>
        <div class="color-input-row">
          <input id="brand_color_native" type="color" value="{{ old('brand_color', $b['color']) }}" aria-label="Pick any colour" />
          <input id="brand_color" name="brand_color" type="text" value="{{ old('brand_color', $b['color']) }}" placeholder="#E8A33D" />
        </div>
        <div class="hint">Pick a swatch, drag the colour wheel, or type a hex code like #1B87D6. Used for buttons and accents on your pages.</div>
        @error('brand_color')<div class="err">{{ $message }}</div>@enderror
        @if($sections->count() > 1)
          @include('school._scope-picker', ['field' => 'brand_color', 'sections' => $sections])
          <div class="hint" style="margin:-.3rem 0 .7rem">Who this colour is for. Untouched sections keep whatever they already have.</div>
          @error('brand_color_sections')<div class="err">{{ $message }}</div>@enderror
        @endif
      </div>
      <script>
        (function(){
          var text = document.getElementById('brand_color');
          var native = document.getElementById('brand_color_native');
          var hexPattern = /^#[0-9a-fA-F]{6}$/;
          native?.addEventListener('input', function(){ text.value = this.value; });
          text?.addEventListener('input', function(){ if (hexPattern.test(this.value)) { native.value = this.value; } });
          document.querySelectorAll('.color-swatch').forEach(function(btn){
            btn.addEventListener('click', function(){
              var color = this.getAttribute('data-color');
              text.value = color;
              native.value = color;
            });
          });
          // Deferred to DOMContentLoaded (user report 2026-09-22: "when
          // on click on Just some sections under logo, its not showing
          // the sections") -- this script tag sits ABOVE the Logo
          // field's own scope-picker markup further down the page, so
          // running querySelectorAll immediately (the old behaviour)
          // only ever found the Brand colour scope radios, which exist
          // above it; the Logo ones did not exist in the DOM yet.
          document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('input[data-scope]').forEach(function(radio){
              radio.addEventListener('change', function(){
                var panel = document.querySelector('.section-ticks[data-scope-panel="'+this.getAttribute('data-scope')+'"]');
                if (panel) panel.classList.toggle('open', this.value === 'sections');
              });
            });
          });
        })();
      </script>
      <div class="field">
        <label for="logo">Logo</label>
        {{-- Missing before (user report 2026-09-22: "the current logo
             is not showing") -- Stamp/Signature just below both already
             show a preview, Logo never did. --}}
        @if($school->logo_path)
          <img src="{{ \App\Support\TenantAsset::url($school->logo_path) }}" alt="Current school logo" style="height:56px;display:block;margin-bottom:.4rem" />
        @endif
        <input id="logo" name="logo" type="file" accept="image/*" />
        <div class="hint">A square image works best. Up to 2 MB. To assign the logo shown above to just some sections without choosing a file again, pick "Just some sections" below and save.</div>
        @error('logo')<div class="err">{{ $message }}</div>@enderror
        @if($sections->count() > 1)
          {{-- User request 2026-09-09: a school running, say, Nursery &
               Primary under one identity and Secondary under another
               can upload a logo for just those sections instead of the
               whole school. Upload again with the other section(s)
               ticked to give them a different one. --}}
          @include('school._scope-picker', ['field' => 'logo', 'sections' => $sections])
          <div class="hint" style="margin:-.3rem 0 .7rem">Who this upload is for. Untouched sections keep whatever they already have.</div>
          @error('logo_sections')<div class="err">{{ $message }}</div>@enderror
        @endif
      </div>

      @if($sections->count() > 1)
        <div class="field">
          <label><input type="checkbox" name="show_all_section_logos_on_login" value="1" @checked($showAllSectionLogos) /> Show every section's logo on the login page</label>
          <div class="hint">Off shows just the school's default logo. On shows all of your school's distinct logos side by side, above the sign in form.</div>
        </div>
      @endif

      <div class="field">
        <label for="stamp">School stamp</label>
        @if($school->stamp_path)
          <img src="{{ \App\Support\TenantAsset::url($school->stamp_path) }}" alt="Current school stamp" style="height:48px;display:block;margin-bottom:.4rem" />
        @endif
        <input id="stamp" name="stamp" type="file" accept="image/*" />
        <div class="hint">Shown on report cards that turn on "School stamp and signature". Used school wide unless a class teacher uploads their own. Up to 2 MB.</div>
        @error('stamp')<div class="err">{{ $message }}</div>@enderror
      </div>

      <div class="field">
        <label for="signature">Signature</label>
        @if($school->signature_path)
          <img src="{{ \App\Support\TenantAsset::url($school->signature_path) }}" alt="Current signature" style="height:48px;display:block;margin-bottom:.4rem" />
        @endif
        <input id="signature" name="signature" type="file" accept="image/*" />
        <div class="hint">Used the same way as the school stamp. A class teacher can still upload their own from their Class Teacher Entry screen.</div>
        @error('signature')<div class="err">{{ $message }}</div>@enderror
      </div>

      <div class="field">
        <label>Login page design</label>
        <div class="hint" style="margin-bottom:.6rem">How your school's sign in page is arranged. <a href="{{ route('school.login', ['school'=>$school->slug]) }}" target="_blank" rel="noopener">Preview it</a></div>
        <div class="login-picker">
          @foreach(config('skulos.login_layouts') as $key => $meta)
            <label class="lpick">
              <input type="radio" name="login_layout" value="{{ $key }}" @checked($b['login_layout'] === $key) />
              <div class="lpick-card">
                <div class="lpick-mini lpm-{{ $key }}"><span class="lpm-panel"></span><span class="lpm-form"><i></i><i></i><b></b></span></div>
                <div class="lpick-name">{{ $meta['label'] }}</div>
              </div>
            </label>
          @endforeach
        </div>
      </div>

      <div class="field">
        <label>Sign in box position</label>
        <div class="hint" style="margin-bottom:.6rem">For the Split and Showcase designs: which side the sign in box appears on.</div>
        <div class="login-picker" style="grid-template-columns:repeat(2,1fr)">
          <label class="lpick">
            <input type="radio" name="login_box_side" value="right" @checked(($b['login_box_side'] ?? 'right') === 'right') />
            <div class="lpick-card">
              <div class="lpick-mini"><span class="lpm-panel" style="width:55%"></span><span class="lpm-form"><i></i><i></i><b></b></span></div>
              <div class="lpick-name">Right (default)</div>
            </div>
          </label>
          <label class="lpick">
            <input type="radio" name="login_box_side" value="left" @checked(($b['login_box_side'] ?? 'right') === 'left') />
            <div class="lpick-card">
              <div class="lpick-mini" style="flex-direction:row-reverse"><span class="lpm-panel" style="width:55%"></span><span class="lpm-form"><i></i><i></i><b></b></span></div>
              <div class="lpick-name">Left</div>
            </div>
          </label>
        </div>
      </div>

      <div class="field">
        <label>Chart style</label>
        <div class="hint" style="margin-bottom:.6rem">How trend charts across the app are drawn (dashboard, Attendance Dashboard, report card score trend).</div>
        <div class="login-picker" style="grid-template-columns:repeat(2,1fr)">
          @foreach(config('skulos.chart_styles') as $key => $meta)
            <label class="lpick">
              <input type="radio" name="chart_style" value="{{ $key }}" @checked($chartStyle === $key) />
              <div class="lpick-card">
                <div class="lpick-mini" style="align-items:flex-end;padding:.4rem;gap:3px">
                  @if($key === 'bar')
                    <span style="flex:1;height:35%;background:var(--accent);border-radius:2px"></span>
                    <span style="flex:1;height:60%;background:var(--accent);border-radius:2px"></span>
                    <span style="flex:1;height:45%;background:var(--accent);border-radius:2px"></span>
                    <span style="flex:1;height:75%;background:var(--accent);border-radius:2px"></span>
                  @else
                    <svg viewBox="0 0 100 40" style="width:100%;height:100%"><polyline points="5,30 35,15 65,22 95,5" fill="none" stroke="var(--accent)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  @endif
                </div>
                <div class="lpick-name">{{ $meta['label'] }}</div>
              </div>
            </label>
          @endforeach
        </div>
      </div>

      <button class="btn btn-primary" type="submit">Save branding</button>
    </form>
  </div>

  <div class="card" style="text-align:center;background:var(--bg)">
    <p class="hint" style="text-transform:uppercase;letter-spacing:.08em;margin-bottom:1rem">Live preview</p>
    <div style="background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:2rem">
      <div class="auth-logo" style="margin-inline:auto;background:{{ $b['color'] }}">{{ strtoupper(substr($school->name,0,1)) }}</div>
      <h2 style="font-size:1.3rem;font-weight:800;margin-bottom:.3rem">{{ $b['headline'] }}</h2>
      <p class="muted" style="margin-bottom:1.2rem">{{ $b['message'] }}</p>
      <span class="btn btn-primary btn-block" style="background:{{ $b['color'] }}">Sign in</span>
    </div>
    <p class="hint" style="margin-top:1rem">Your page lives at {{ $school->slug }}.{{ config('app.domain','localhost') }}</p>
  </div>
</div>

@if($sections->count() > 1)
  <div class="card" style="margin-top:1.2rem">
    <h3 style="margin-bottom:.3rem">Section branding</h3>
    <p class="hint" style="margin-bottom:.8rem">What each section actually shows right now. A section with no override of its own uses the school default above.</p>
    @foreach($sections as $s)
      @php($sLogo = $s->branding?->logo_path)
      @php($sColor = $s->branding?->brand_color ?: $b['color'])
      <div class="section-row">
        <div class="mark" style="background:{{ $sColor }}">
          @if($sLogo)<img src="{{ \App\Support\TenantAsset::url($sLogo) }}" alt="">@else{{ strtoupper(substr($s->name,0,1)) }}@endif
        </div>
        <div class="name">{{ $s->name }}</div>
        <div class="state">
          @if($s->branding)
            Own {{ collect([$s->branding->logo_path ? 'logo' : null, $s->branding->brand_color ? 'colour' : null])->filter()->implode(' & ') }}
          @else
            Using school default
          @endif
        </div>
        @if($s->branding)
          <form method="POST" action="{{ route('school.branding.sections.reset', ['school' => $school->slug, 'sectionId' => $s->id]) }}">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">Reset</button>
          </form>
        @endif
      </div>
    @endforeach
  </div>
@endif
@endsection
