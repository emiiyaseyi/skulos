{{--
  Which classes a fee item applies to (user request 2026-07-25: "fee
  items can be different for each class... make it possible to select
  the classes that item is applicable to"). Expects $schoolClasses
  (Section-sorted, section eager loaded) and $selectedClassIds (array,
  empty on the add form). Leaving every box unticked means "every
  class", the field's own default meaning, so this never blocks adding a
  simple school-wide fee item.
--}}
@php($grouped = $schoolClasses->groupBy(fn($c) => $c->section?->name ?? 'Other'))
<div class="field">
  <label>Applies to</label>
  <p class="hint" style="margin:0 0 .5rem">Leave every box unticked for a fee that applies to the whole school.</p>
  <div style="border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;max-height:14rem;overflow-y:auto">
    @foreach($grouped as $sectionName => $classes)
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim);margin:{{ $loop->first ? '0' : '.7rem' }} 0 .3rem">{{ $sectionName }}</div>
      <div class="grid cols-2" style="gap:.3rem .8rem">
        @foreach($classes as $class)
          <label style="display:flex;align-items:center;gap:.4rem;font-size:.88rem;margin:0">
            <input type="checkbox" name="class_ids[]" value="{{ $class->id }}" @checked(in_array($class->id, $selectedClassIds, true)) />
            <span>{{ $class->name }}</span>
          </label>
        @endforeach
      </div>
    @endforeach
  </div>
</div>
