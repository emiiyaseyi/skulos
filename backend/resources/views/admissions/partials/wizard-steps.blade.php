{{--
  Clickable stepper nav across the whole application wizard (2026-09-11,
  user directive: "applicant should be able to move from one section to
  another without using the continue button... name of each section at
  the top and user can click on any"). Jumping to any step was already
  safe server-side -- this is the UI for it. Expects $stepList, built by
  HasWizardSteps::wizardStepList().
--}}
@if(! empty($stepList))
  <div style="display:flex;gap:.4rem;flex-wrap:wrap;justify-content:center;margin:0 0 1.3rem">
    @foreach($stepList as $i => $step)
      <a href="{{ $step['url'] }}"
         style="display:flex;align-items:center;gap:.4rem;padding:.4rem .75rem;border-radius:999px;font-size:.82rem;font-weight:700;text-decoration:none;border:1px solid {{ $step['current'] ? 'var(--accent)' : 'var(--line)' }};background:{{ $step['current'] ? 'var(--accent)' : 'transparent' }};color:{{ $step['current'] ? 'var(--accent-ink)' : 'var(--mut)' }}">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.3rem;height:1.3rem;border-radius:50%;background:{{ $step['current'] ? 'rgba(0,0,0,.15)' : 'var(--soft)' }};font-size:.72rem">{{ $i + 1 }}</span>
        {{ $step['label'] }}
      </a>
    @endforeach
  </div>
@endif
