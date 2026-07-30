@extends('layouts.app', ['accent' => $accent])
@section('title', 'Application Received — '.($school->name ?? 'SKULOS'))
@section('bare', true)

@section('content')
<div style="max-width:30rem;margin:2.5rem auto;padding:0 1.25rem;text-align:center">
  <div class="card">
    <h1 class="title" style="margin-bottom:.6rem">Application Received</h1>
    <p class="subtitle" style="margin-bottom:1.2rem">Thank you, {{ $applicant->name }}. Your application reference is:</p>
    <p style="font-size:1.6rem;font-weight:800;letter-spacing:.02em;margin-bottom:1.2rem">{{ $applicant->admission_number }}</p>
    <p class="muted">Keep this reference safe, you'll need it (or the parent/guardian phone number you gave) to sign back in once your application fee is confirmed and your account is activated. The school will contact you with payment instructions.</p>
  </div>
</div>
@endsection
