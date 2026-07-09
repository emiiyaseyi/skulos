@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Mail Settings'])
@section('title', 'Mail Settings')
@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Mail settings</h1>
    <p class="subtitle">Optional. Leave this blank to use SKULOS's shared mail settings; fill it in to send from your own.</p>
  </div>
</div>
<div class="card" style="max-width:36rem">
  <form method="POST" action="{{ route('school.settings.mail.update', ['school' => $school->slug]) }}">
    @csrf
    @include('settings.mail-form-fields')
    <button class="btn btn-primary btn-sm" type="submit">Save mail settings</button>
  </form>
  <form method="POST" action="{{ route('school.settings.mail.test', ['school' => $school->slug]) }}" style="margin-top:1rem">
    @csrf
    <button class="btn btn-ghost btn-sm" type="submit">Send test email to myself</button>
  </form>
</div>
@endsection
