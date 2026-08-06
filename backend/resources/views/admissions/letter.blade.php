@extends('layouts.app', ['accent' => $accent ?? null])
@section('title', 'Admission Letter — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@push('head')
<style>
  .adl-wrap{max-width:46rem;margin:2rem auto;padding:0 1.25rem}
  .adl-actions{display:flex;justify-content:flex-end;gap:.6rem;margin-bottom:1rem}
  .adl-sheet{background:#fff;color:#1a1a1a;border-radius:10px;padding:2.4rem;box-shadow:var(--shadow);font-size:.85rem;line-height:1.6}
  .adl-head{display:flex;align-items:center;gap:.9rem;text-align:center;border-bottom:2px solid var(--accent);padding-bottom:.8rem;margin-bottom:1.2rem}
  .adl-head-text{flex:1;min-width:0}
  .adl-logo{width:56px;height:56px;border-radius:12px;object-fit:contain;flex:none}
  .adl-head h1{font-size:1.5rem;font-weight:800;margin:0}
  .adl-head p{font-size:.75rem;color:#555;margin:.15rem 0 0}
  .adl-ref{display:flex;justify-content:space-between;font-size:.78rem;color:#555;margin-bottom:1.2rem;flex-wrap:wrap;gap:.4rem}
  .adl-body p{margin:0 0 .9rem}
  .adl-details{background:#f7f8fa;border-radius:10px;padding:1rem 1.2rem;margin:0 0 1.2rem}
  .adl-details div{display:flex;justify-content:space-between;padding:.25rem 0;font-size:.8rem}
  .adl-details b{font-weight:700}
  .adl-signoff{display:flex;justify-content:space-around;gap:1.2rem;text-align:center;margin-top:1.6rem;padding-top:.9rem;border-top:1px solid #e2e2e2}
  .adl-signoff .line{border-top:1px solid #999;margin-top:1.6rem;padding-top:.3rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.03em;color:#777}
  .adl-footer{margin-top:1.2rem;padding-top:.8rem;border-top:1px solid #e2e2e2;font-size:.72rem;color:#777;text-align:center}
  @media print{
    .adl-actions{display:none}
    .adl-sheet{box-shadow:none;border-radius:0;padding:0}
    body{background:#fff}
  }
</style>
@endpush

@section('content')
<div class="adl-wrap">
  <div class="adl-actions">
    <button class="btn btn-primary btn-sm" onclick="window.print()" type="button">Print / Save as PDF</button>
    @if(isset($backRoute))<a class="btn btn-ghost btn-sm" href="{{ $backRoute }}">Back</a>@endif
  </div>

  <div class="adl-sheet">
    <div class="adl-head">
      @php($logoUrl = $school->logo_path ? \Illuminate\Support\Facades\Storage::url($school->logo_path) : null)
      @if($logoUrl)<img class="adl-logo" src="{{ $logoUrl }}" alt="" />@endif
      <div class="adl-head-text">
        <h1>{{ $school->name }}</h1>
        @if($school->address)<p>{{ $school->address }}</p>@endif
        @if($school->email || $school->phone)
          <p>{{ collect([$school->email, $school->phone])->filter()->implode(' &middot; ') }}</p>
        @endif
      </div>
    </div>

    <div class="adl-ref">
      <span>Ref: {{ $applicant->admission_number }}</span>
      <span>Date: {{ ($applicant->decided_at ?? now())->toFormattedDateString() }}</span>
    </div>

    <div class="adl-body">
      <p>Dear {{ $applicant->name }},</p>
      <p><strong>Offer of Admission</strong></p>
      <p>{{ $letterContent['intro'] }}</p>

      <div class="adl-details">
        <div><span>Applicant</span><b>{{ $applicant->name }}</b></div>
        <div><span>Reference number</span><b>{{ $applicant->admission_number }}</b></div>
        <div><span>Class offered</span><b>{{ $applicant->schoolClass?->name ?? 'N/A' }}</b></div>
        <div><span>Session</span><b>{{ $applicant->session?->name ?? 'N/A' }}</b></div>
      </div>

      <p>{{ $letterContent['nextSteps'] }}</p>
      <p>We look forward to welcoming {{ $applicant->name }} to our community.</p>
    </div>

    <div class="adl-signoff">
      <div>
        <div class="line">Authorized Signature</div>
      </div>
      <div>
        <div class="line">School Stamp</div>
      </div>
    </div>

    @if($school->motto)
      <div class="adl-footer">{{ $school->motto }}</div>
    @endif
  </div>
</div>
@endsection
