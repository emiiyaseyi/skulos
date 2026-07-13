@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Support Contact'])
@section('title', 'Support Contact')
@section('heading', 'Support Contact')
@section('crumb', 'The email and phone number shown on the login page and in every transactional email')
@section('content')
<div class="card" style="max-width:32rem">
  <p class="muted" style="margin-bottom:1.2rem">Shown to every user platform wide, on the login page and in the footer of every email SKULOS sends.</p>
  <form method="POST" action="{{ route('admin.settings.support-contact.update') }}">
    @csrf
    <div class="field">
      <label for="email">Support email</label>
      <input id="email" name="email" type="email" value="{{ $settings['email'] ?? '' }}" required />
    </div>
    <div class="field">
      <label for="phone">Support phone</label>
      <input id="phone" name="phone" type="text" value="{{ $settings['phone'] ?? '' }}" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
