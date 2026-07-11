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
        })();
      </script>
      <div class="field">
        <label for="logo">Logo</label>
        <input id="logo" name="logo" type="file" accept="image/*" />
        <div class="hint">A square image works best. Up to 2 MB.</div>
        @error('logo')<div class="err">{{ $message }}</div>@enderror
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
@endsection
