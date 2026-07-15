@extends('layouts.app', ['portal' => 'school', 'school' => $school, 'accent' => $accent, 'pageTitle' => 'New Broadcast'])
@section('title', 'New Broadcast')

@section('content')
<div class="page-head">
  <div>
    <h1 class="title">New Broadcast</h1>
    <p class="subtitle">Send a message. Recipients see it as a pop-up on their next sign-in and acknowledge it.</p>
  </div>
  <a class="btn btn-ghost btn-sm" href="{{ route('school.messages.index', ['school' => $school->slug]) }}">Back to messages</a>
</div>

<div class="card" style="max-width:40rem">
  <form method="POST" action="{{ route('school.messages.store', ['school' => $school->slug]) }}" enctype="multipart/form-data">
    @csrf
    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" required maxlength="150" value="{{ old('title') }}" />
    </div>

    <div class="grid cols-2" style="gap:1rem">
      <div class="field">
        <label for="audience">Who</label>
        <select id="audience" name="audience" required>
          @foreach($audiences as $key => $label)
            <option value="{{ $key }}" @selected(old('audience') === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="scope">Scope</label>
        <select id="scope" name="scope" required onchange="document.getElementById('sectionField').style.display=this.value==='section'?'block':'none';document.getElementById('classField').style.display=this.value==='class'?'block':'none'">
          @foreach($scopes as $key => $label)
            <option value="{{ $key }}" @selected(old('scope') === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div id="sectionField" class="field" style="display:{{ old('scope') === 'section' ? 'block' : 'none' }}">
      <label for="section_id">Section</label>
      <select id="section_id" name="section_id">
        <option value="">Select a section</option>
        @foreach($sections as $section)
          <option value="{{ $section->id }}" @selected(old('section_id') == $section->id)>{{ $section->name }}</option>
        @endforeach
      </select>
    </div>

    <div id="classField" class="field" style="display:{{ old('scope') === 'class' ? 'block' : 'none' }}">
      <label for="class_arm_id">Class</label>
      <select id="class_arm_id" name="class_arm_id">
        <option value="">Select a class</option>
        @foreach($classArms as $arm)
          <option value="{{ $arm->id }}" @selected(old('class_arm_id') == $arm->id)>{{ $arm->label() }}</option>
        @endforeach
      </select>
    </div>

    <div class="field">
      <label>Message</label>
      @include('school.widgets._richtext', ['name' => 'body', 'value' => old('body')])
    </div>

    <div class="field">
      <label for="image">Image (optional)</label>
      <input id="image" name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif" />
      <p class="hint">A light photo works best. It is compressed automatically.</p>
    </div>

    <button class="btn btn-primary btn-sm" type="submit">Send broadcast</button>
  </form>
</div>
@endsection
