@extends('layouts.app', ['accent' => $accent])
@section('title', 'Seek Admission — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@push('head')
<style>
  .adm-logo{width:64px;height:64px;border-radius:16px;background:var(--accent);color:var(--accent-ink);display:grid;place-items:center;font-weight:800;font-size:1.6rem;margin:0 auto 1.1rem;overflow:hidden}
  .adm-logo img{width:100%;height:100%;object-fit:contain}
</style>
@endpush

@section('content')
<div style="max-width:34rem;margin:2.5rem auto;padding:0 1.25rem">
  <div class="page-head" style="justify-content:center;text-align:center">
    <div>
      @php($logo = $school->branding()['logo'] ?? null)
      <div class="adm-logo">
        @if($logo)
          <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="{{ $school->name }} logo" />
        @else
          {{ strtoupper(substr($school->name ?? 'S', 0, 1)) }}
        @endif
      </div>
      <h1 class="title">Seek Admission</h1>
      <p class="subtitle">Apply for admission to {{ $school->name }}.</p>
    </div>
  </div>

  @if($errors->any())
    <div class="flash flash-bad" style="margin-bottom:1rem">{{ $errors->first() }}</div>
  @endif

  @if($batches->isEmpty())
    <div class="card" style="text-align:center;padding:2.5rem 1.5rem">
      <span class="badge badge-warn" style="display:inline-block;margin-bottom:1rem">Admissions closed</span>
      <h3 style="margin-bottom:.5rem">No admission window is open right now</h3>
      <p class="muted" style="max-width:24rem;margin:0 auto 1.2rem">
        {{ $school->name }} is not accepting new applications at the moment. Please check back later,
        @if($school->email || $school->phone)
          or reach the school directly in the meantime.
        @else
          or reach out to the school directly to ask when the next window opens.
        @endif
      </p>
      @if($school->email || $school->phone)
        <div style="display:flex;justify-content:center;gap:1.2rem;flex-wrap:wrap;font-weight:700">
          @if($school->phone)<span>{{ $school->phone }}</span>@endif
          @if($school->email)<span>{{ $school->email }}</span>@endif
        </div>
      @endif
    </div>
  @else
    <div class="card">
      <form method="POST" action="{{ route('school.admissions.apply.submit', ['school' => $school->slug]) }}">
        @csrf
        @if($batches->count() > 1)
          <div class="field">
            <label for="batch_uuid">Admission window</label>
            <select id="batch_uuid" name="batch_uuid" required onchange="skulosFilterApplyClasses(this.value)">
              <option value="">Choose an admission window</option>
              @foreach($batches as $batch)
                <option value="{{ $batch->batch_uuid }}" @selected(old('batch_uuid') == $batch->batch_uuid)>
                  {{ $batch->name }} &middot; {{ $batch->session?->name }}
                  @if($batch->campus) &middot; {{ $batch->campus->name }} @endif
                  (&#8358;{{ number_format($batch->application_fee_amount, 2) }} application fee)
                </option>
              @endforeach
            </select>
          </div>
        @else
          <input type="hidden" name="batch_uuid" value="{{ $batches->first()->batch_uuid }}" />
          <p class="muted" style="margin-bottom:.6rem">Application fee: &#8358;{{ number_format($batches->first()->application_fee_amount, 2) }}</p>
        @endif
        <div class="field">
          <label for="school_class_id">Class applying for</label>
          <select id="school_class_id" name="school_class_id" required>
            <option value="">Choose a class</option>
            @foreach($batches as $batch)
              @foreach($batch->classes as $class)
                <option value="{{ $class->id }}" data-batch="{{ $batch->batch_uuid }}"
                  @selected(old('school_class_id') == $class->id)
                  @if($batches->count() > 1 && old('batch_uuid') && old('batch_uuid') != $batch->batch_uuid) hidden @endif>
                  {{ $class->section }} &middot; {{ $class->name }}
                </option>
              @endforeach
            @endforeach
          </select>
        </div>
        @if($batches->count() > 1)
          <script>
            function skulosFilterApplyClasses(batchUuid) {
              var select = document.getElementById('school_class_id');
              var firstVisible = null;
              Array.prototype.forEach.call(select.options, function (opt) {
                if (! opt.value) return;
                var matches = opt.getAttribute('data-batch') === batchUuid;
                opt.hidden = ! matches;
                if (matches && ! firstVisible) firstVisible = opt;
              });
              select.value = firstVisible ? firstVisible.value : '';
            }
          </script>
        @endif
        <div class="field">
          <label for="first_name">Applicant's first name</label>
          <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required />
        </div>
        <div class="field">
          <label for="other_names">Applicant's other name(s) (optional)</label>
          <input id="other_names" name="other_names" type="text" value="{{ old('other_names') }}" />
        </div>
        <div class="field">
          <label for="last_name">Applicant's last name</label>
          <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required />
        </div>
        <div class="field">
          <label for="date_of_birth">Applicant's date of birth</label>
          <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" required max="{{ now()->subDay()->toDateString() }}" />
        </div>
        <div class="field">
          <label for="phone">Parent/guardian phone number</label>
          <input id="phone" name="phone" type="text" value="{{ old('phone') }}" required autocomplete="tel" />
          <div class="hint">This becomes part of how you sign back in later, along with a password issued once the application fee is confirmed.</div>
        </div>
        <div class="field">
          <label for="email">Parent/guardian email (optional)</label>
          <input id="email" name="email" type="email" value="{{ old('email') }}" />
        </div>
        <button class="btn btn-primary btn-block" type="submit">Start application</button>
      </form>
    </div>
  @endif

  <p class="support-line" style="margin-top:1rem;text-align:center">
    Already applied? <a href="{{ route('school.admissions.login', ['school' => $school->slug]) }}">Continue your application</a>
  </p>
</div>
@endsection
