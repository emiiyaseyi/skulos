@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => $meta['label']])
@section('title', $meta['label'])
@section('heading', $meta['label'])
@section('crumb', $meta['description'])
@section('content')

<div class="row-between" style="margin-bottom:1.2rem">
  <a class="btn btn-ghost btn-sm" href="{{ route('admin.mail-templates.index') }}">Back to all emails</a>
  @if($isEdited)
    <form method="POST" action="{{ route('admin.mail-templates.reset', ['key' => $key]) }}"
          onsubmit="return confirm('Revert to the built-in wording? Your edits are removed.')">
      @csrf
      <button class="btn btn-ghost btn-sm" style="color:var(--bad)" type="submit">Revert to built in</button>
    </form>
  @endif
</div>

<form method="POST" action="{{ route('admin.mail-templates.update', ['key' => $key]) }}">
  @csrf
  <div class="card" style="margin-bottom:1.2rem">
    <div class="field">
      <label for="subject">Subject</label>
      <input id="subject" name="subject" value="{{ old('subject', $subject) }}" required maxlength="500" />
      @error('subject')<div class="err">{{ $message }}</div>@enderror
    </div>

    <div class="field" style="margin-bottom:.4rem">
      <label for="body">Message</label>
      <textarea id="body" name="body" rows="18" required maxlength="20000"
                style="font-family:inherit;line-height:1.6">{{ old('body', $body) }}</textarea>
      @error('body')<div class="err">{{ $message }}</div>@enderror
    </div>
    <p class="hint">
      Write in plain paragraphs. Line breaks are kept. The SKULOS header, footer and sign in details
      are added around this automatically, so there is no need to repeat them here.
    </p>
  </div>

  <div class="card" style="margin-bottom:1.2rem">
    <h3 style="margin-bottom:.3rem">Placeholders you can use</h3>
    <p class="muted" style="margin-bottom:.8rem">
      Type these anywhere in the subject or message and they are replaced with the real values when
      the email is sent. Click one to insert it.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem">
      @foreach($meta['placeholders'] as $placeholder)
        <button type="button" class="btn btn-ghost btn-sm" data-insert="{{ '{'.$placeholder.'}' }}">{{ '{'.$placeholder.'}' }}</button>
      @endforeach
    </div>
  </div>

  <div class="card">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn btn-primary btn-sm" type="submit">Save wording</button>
      <button class="btn btn-ghost btn-sm" type="submit"
              formaction="{{ route('admin.mail-templates.test', ['key' => $key]) }}">Send me a test</button>
    </div>
    <p class="hint" style="margin-top:.6rem">
      A test is sent to your own address with sample values in place of the placeholders, never a
      real school's details or a real password.
    </p>
  </div>
</form>

<script>
  // Insert at the cursor rather than appending, so a placeholder can be
  // dropped into the middle of a sentence where it is actually needed.
  document.querySelectorAll('[data-insert]').forEach(function (button) {
    button.addEventListener('click', function () {
      var field = document.getElementById('body');
      var token = this.dataset.insert;
      var start = field.selectionStart || 0;
      var end = field.selectionEnd || 0;
      field.value = field.value.slice(0, start) + token + field.value.slice(end);
      field.focus();
      field.selectionStart = field.selectionEnd = start + token.length;
    });
  });
</script>
@endsection
