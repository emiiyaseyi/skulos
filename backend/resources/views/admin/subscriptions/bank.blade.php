@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Subscription Bank Details'])
@section('title', 'Subscription Bank Details')
@section('heading', 'Subscription Bank Details')
@section('crumb', 'The account every school transfers its SKULOS subscription payment into')
@section('content')
<div class="card" style="max-width:32rem">
  <p class="muted" style="margin-bottom:1.2rem">Shown to every school on their own Subscription page.</p>
  <form method="POST" action="{{ route('admin.settings.subscription-bank.update') }}">
    @csrf
    <div class="field">
      <label for="bank_name">Bank name</label>
      <input id="bank_name" name="bank_name" value="{{ $settings['bank_name'] ?? '' }}" required />
    </div>
    <div class="field">
      <label for="account_name">Account name</label>
      <input id="account_name" name="account_name" value="{{ $settings['account_name'] ?? '' }}" required />
    </div>
    <div class="field">
      <label for="account_number">Account number</label>
      <input id="account_number" name="account_number" value="{{ $settings['account_number'] ?? '' }}" required />
    </div>
    <button class="btn btn-primary btn-sm" type="submit">Save</button>
  </form>
</div>
@endsection
