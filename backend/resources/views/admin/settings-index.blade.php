@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Platform Settings'])
@section('title', 'Platform Settings')
@section('heading', 'Platform Settings')
@section('crumb', 'Every platform-wide default, in one place')

@section('content')
<div class="grid cols-2" style="gap:1rem">
  <a class="card" href="{{ route('admin.settings.mail.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Mail Settings</h3>
    <p class="muted">The platform-wide SMTP default every school inherits unless it sets its own.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.landing-pages.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Landing Pages</h3>
    <p class="muted">The default welcome/login page look a newly provisioned school starts with.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.idle-timeout.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Idle Timeout</h3>
    <p class="muted">How long an inactive session stays signed in before a school must set its own.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.retention.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Retention</h3>
    <p class="muted">How long generated files, logs, and temporary uploads are kept before cleanup.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.support-contact.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Support Contact</h3>
    <p class="muted">The support email and phone number shown to every school.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.subscription-bank.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Subscription Bank</h3>
    <p class="muted">The bank account schools transfer their subscription payment to.</p>
  </a>
  <a class="card" href="{{ route('admin.marketing-pages.index') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Marketing Pages</h3>
    <p class="muted">Edit the public marketing site's HTML directly.</p>
  </a>
  <a class="card" href="{{ route('admin.settings.self-hosted-license.edit') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Self-Hosted License</h3>
    <p class="muted">Only for a self-hosted installation: the license key it checks in with, and its current status.</p>
  </a>
  <a class="card" href="{{ route('admin.releases.index') }}" style="text-decoration:none;color:inherit;display:block">
    <h3 style="margin-bottom:.3rem">Releases</h3>
    <p class="muted">Build and publish the code releases self-hosted schools pull through their license.</p>
  </a>
</div>
@endsection
