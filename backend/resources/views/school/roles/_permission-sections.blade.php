{{--
  Shared "main sections, each with its own sub section of features"
  checklist (user directive 2026-07-16: "CHECK THE BILLING FEATURES AGAIN
  WHERE WE HAVE SO MANY MAIN SECTIONS AND SUB SECTION WITH ALL THE
  FEATURES UNDER EACH SECTION, THAT IS HOW I WANT IT TO APPEAR"), used by
  both "Create a role" (nothing checked) and a role's own "Edit
  permissions" form ($checked pre-filled with its current permission ids).
  One <details> per module, one checkbox per real Permission row in it.
--}}
@foreach($catalog as $moduleCode => $meta)
  @if($meta['permissions']->isNotEmpty())
    <details class="perm-section">
      <summary>{{ $meta['name'] }} <span class="muted" style="font-weight:400">({{ $meta['permissions']->count() }})</span></summary>
      <div class="module-grid" style="margin-top:.5rem">
        @foreach($meta['permissions'] as $permission)
          <label class="module-check">
            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($checked->contains($permission->id)) />
            {{ \Illuminate\Support\Str::headline(\Illuminate\Support\Str::after($permission->code, $moduleCode.'.')) }}
          </label>
        @endforeach
      </div>
    </details>
  @endif
@endforeach
