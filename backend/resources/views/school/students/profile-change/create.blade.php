@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'Request Changes'])
@section('title', 'Request Changes')

@push('head')
<style>
  .req::after{ content:" *"; color:var(--bad); }
</style>
@endpush

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">Request Changes to {{ $student->fullName() }}'s Details</h1>
    <p class="subtitle">Changes are sent to the class teacher, then the school admin, for approval before they apply. Admission number, class, and house cannot be changed here.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.students.show', ['school' => $school->slug, 'studentId' => $student->id]) }}">Back to Profile</a>
</div>

@if($pending)
  <div class="card" style="margin-bottom:1.1rem">
    <h3 style="margin-bottom:.5rem">A request is already awaiting a decision</h3>
    <p class="muted" style="margin-bottom:.6rem">Status: <span class="badge badge-warn">{{ \App\Models\Tenant\StudentProfileChangeRequest::statusLabel($pending->status) }}</span></p>
    <table>
      <thead><tr><th>Field</th><th>Current</th><th>Requested</th></tr></thead>
      <tbody>
        @foreach($pending->requested as $field => $value)
          <tr>
            <td>{{ \Illuminate\Support\Str::headline($field) }}</td>
            <td>{{ $pending->original[$field] ?? 'Not set' }}</td>
            <td>{{ $value ?: 'Not set' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@else

  @if($lastDecided && $lastDecided->isDecided())
    <div class="card" style="margin-bottom:1.1rem">
      <h3 style="margin-bottom:.5rem">Your last request</h3>
      <p class="muted" style="margin-bottom:0">
        <span class="badge {{ $lastDecided->status === \App\Models\Tenant\StudentProfileChangeRequest::STATUS_APPROVED ? 'badge-ok' : 'badge-bad' }}">{{ \App\Models\Tenant\StudentProfileChangeRequest::statusLabel($lastDecided->status) }}</span>
        @if($lastDecided->status === \App\Models\Tenant\StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER && $lastDecided->class_teacher_notes)
          &middot; {{ $lastDecided->class_teacher_notes }}
        @elseif($lastDecided->status === \App\Models\Tenant\StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN && $lastDecided->admin_notes)
          &middot; {{ $lastDecided->admin_notes }}
        @endif
      </p>
    </div>
  @endif

  <div class="card" style="max-width:44rem">
    <form method="POST" action="{{ route('school.students.profile-change.store', ['school' => $school->slug, 'studentId' => $student->id]) }}">
      @csrf
      <div class="grid cols-2">
        <div class="field">
          <label class="req" for="first_name">First Name</label>
          <input id="first_name" name="first_name" value="{{ old('first_name', $student->first_name) }}" required />
          @error('first_name')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label class="req" for="last_name">Last Name</label>
          <input id="last_name" name="last_name" value="{{ old('last_name', $student->last_name) }}" required />
          @error('last_name')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label @class(['req' => in_array('other_names', $requiredFields)]) for="other_names">Other Names</label>
          <input id="other_names" name="other_names" value="{{ old('other_names', $student->other_names) }}" @required(in_array('other_names', $requiredFields)) />
          @error('other_names')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label @class(['req' => in_array('gender', $requiredFields)]) for="gender">Gender</label>
          <select id="gender" name="gender" @required(in_array('gender', $requiredFields))>
            <option value="">Not set</option>
            <option value="male" @selected(old('gender', $student->gender) === 'male')>Male</option>
            <option value="female" @selected(old('gender', $student->gender) === 'female')>Female</option>
          </select>
          @error('gender')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label @class(['req' => in_array('date_of_birth', $requiredFields)]) for="date_of_birth">Date of Birth</label>
          <input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth', optional($student->date_of_birth)->toDateString()) }}" @required(in_array('date_of_birth', $requiredFields)) />
          @error('date_of_birth')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label for="email">Email (optional)</label>
          <input id="email" name="email" type="email" value="{{ old('email', $student->email) }}" />
          @error('email')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div class="field">
          <label @class(['req' => in_array('parent_mobile', $requiredFields)]) for="parent_mobile">Parent Mobile</label>
          <input id="parent_mobile" name="parent_mobile" value="{{ old('parent_mobile', $student->parent_mobile) }}" @required(in_array('parent_mobile', $requiredFields)) />
          @error('parent_mobile')<div class="err">{{ $message }}</div>@enderror
        </div>
        <x-geo-picker
          country-name="country"
          state-name="state_of_origin"
          lga-name="lga"
          :country-value="old('country', $student->country)"
          :state-value="old('state_of_origin', $student->state_of_origin)"
          :lga-value="old('lga', $student->lga)"
          :country-required="in_array('country', $requiredFields)"
          :state-required="in_array('state_of_origin', $requiredFields)"
          :lga-required="in_array('lga', $requiredFields)"
        />
      </div>
      <div class="field">
        <label @class(['req' => in_array('address', $requiredFields)]) for="address">Address</label>
        <textarea id="address" name="address" rows="2" @required(in_array('address', $requiredFields))>{{ old('address', $student->address) }}</textarea>
        @error('address')<div class="err">{{ $message }}</div>@enderror
      </div>
      <button class="btn btn-primary" type="submit">Send for approval</button>
    </form>
  </div>
@endif
@endsection
