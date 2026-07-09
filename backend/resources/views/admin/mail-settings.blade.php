@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Mail Settings'])
@section('title', 'Mail Settings')
@section('heading', 'Mail Settings')
@section('crumb', 'Outgoing mail (SMTP) for the whole platform')
@section('content')
<div class="card" style="max-width:36rem">
  <p class="muted" style="margin-bottom:1.2rem">This is the platform wide default. A school can set its own under its Settings, which takes over for that school's mail only.</p>
  <form method="POST" action="{{ route('admin.settings.mail.update') }}">
    @csrf
    @include('settings.mail-form-fields')
    <button class="btn btn-primary btn-sm" type="submit">Save mail settings</button>
  </form>
  <form method="POST" action="{{ route('admin.settings.mail.test') }}" style="margin-top:1rem">
    @csrf
    <button class="btn btn-ghost btn-sm" type="submit">Send test email to myself</button>
  </form>
</div>
@endsection
