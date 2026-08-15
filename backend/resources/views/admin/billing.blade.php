@extends('layouts.app', ['portal' => 'admin', 'pageTitle' => 'Billing'])
@section('title', 'Billing')
@section('heading', 'Billing')
@section('crumb', 'Plans, features, add ons, and free term waivers')
@section('content')

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Plans</h3>
  <p class="muted" style="margin-bottom:1rem">Per student, per term. Foundation and Advance are open for signup today; Professional and Enterprise show as Coming soon until built, and cannot be assigned to a school until then.</p>
  <div style="overflow-x:auto">
  <table class="table-stack">
    <thead><tr><th>Plan</th><th>Price / student / term</th><th>Features</th><th>Status</th><th></th></tr></thead>
    <tbody>
      @foreach($plans as $plan)
        <tr>
          <td data-label="Plan"><strong>{{ $plan->name }}</strong></td>
          <td class="muted" data-label="Price / student / term">&#8358;{{ number_format($plan->price_per_student_per_term, 2) }}</td>
          <td class="muted" data-label="Features">{{ count($plan->features ?? []) }} features, {{ count($plan->modules ?? []) }} modules</td>
          <td style="white-space:nowrap" data-label="Status">
            <span class="badge {{ $plan->is_active ? 'badge-ok' : 'badge-warn' }}">{{ $plan->is_active ? 'Offered' : 'Retired' }}</span>
            <span class="badge {{ $plan->is_purchasable ? 'badge-ok' : 'badge-warn' }}">{{ $plan->is_purchasable ? 'Sellable' : 'Coming soon' }}</span>
          </td>
          <td style="white-space:nowrap">
            <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('edit-plan-{{ $plan->id }}').classList.toggle('show')">Edit</button>
            <form method="POST" action="{{ route('admin.billing.plans.toggle-active', $plan) }}" style="display:inline">
              @csrf
              <button class="btn btn-ghost btn-sm" type="submit">{{ $plan->is_active ? 'Retire' : 'Offer again' }}</button>
            </form>
            <form method="POST" action="{{ route('admin.billing.plans.toggle-purchasable', $plan) }}" style="display:inline">
              @csrf
              <button class="btn btn-ghost btn-sm" type="submit">{{ $plan->is_purchasable ? 'Mark Coming soon' : 'Mark sellable' }}</button>
            </form>
          </td>
        </tr>
        <tr id="edit-plan-{{ $plan->id }}" class="plan-edit-row">
          <td colspan="5">
            <form method="POST" action="{{ route('admin.billing.plans.update', $plan) }}" style="padding:.8rem 0">
              @csrf
              <div class="grid cols-2" style="margin-bottom:.8rem">
                <div class="field"><label>Name</label><input name="name" value="{{ $plan->name }}" required /></div>
                <div class="field"><label>Price per student per term</label><input name="price_per_student_per_term" type="number" step="0.01" min="0" value="{{ $plan->price_per_student_per_term }}" required /></div>
                <div class="field"><label>Storage limit (MB, optional)</label><input name="storage_limit_mb" type="number" min="1" value="{{ $plan->storage_limit_mb }}" placeholder="No limit" /></div>
              </div>

              <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)">RBAC modules included</label>
              <div class="module-grid" style="margin-bottom:.8rem">
                @foreach($optionalModules as $code => $meta)
                  <label class="module-check"><input type="checkbox" name="modules[]" value="{{ $code }}" @checked(in_array($code, $plan->modules ?? [])) /> {{ $meta['name'] }}</label>
                @endforeach
              </div>

              <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)">Marketing feature checklist</label>
              <div style="margin-bottom:.8rem">
                @foreach($featureCatalog as $catKey => $category)
                  <details style="margin-bottom:.4rem">
                    <summary style="cursor:pointer;font-size:.82rem;font-weight:600;padding:.3rem 0">{{ $category['label'] }}</summary>
                    <div class="module-grid">
                      @foreach($category['features'] as $fKey => $fLabel)
                        <label class="module-check"><input type="checkbox" name="features[]" value="{{ $fKey }}" @checked(in_array($fKey, $plan->features ?? [])) /> {{ $fLabel }}</label>
                      @endforeach
                    </div>
                  </details>
                @endforeach
              </div>

              <button class="btn btn-primary btn-sm" type="submit">Save {{ $plan->name }}</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.8rem">Add a plan</h3>
  <form method="POST" action="{{ route('admin.billing.plans.store') }}">
    @csrf
    <div class="grid cols-2" style="margin-bottom:.8rem">
      <div class="field"><label for="np_name">Name</label><input id="np_name" name="name" required /></div>
      <div class="field"><label for="np_price">Price per student per term</label><input id="np_price" name="price_per_student_per_term" type="number" step="0.01" min="0" required /></div>
      <div class="field"><label for="np_storage">Storage limit (MB, optional)</label><input id="np_storage" name="storage_limit_mb" type="number" min="1" placeholder="No limit" /></div>
    </div>

    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)">RBAC modules included</label>
    <div class="module-grid" style="margin-bottom:.8rem">
      @foreach($optionalModules as $code => $meta)
        <label class="module-check"><input type="checkbox" name="modules[]" value="{{ $code }}" /> {{ $meta['name'] }}</label>
      @endforeach
    </div>

    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--mut-dim)">Marketing feature checklist</label>
    <div style="margin-bottom:.8rem">
      @foreach($featureCatalog as $catKey => $category)
        <details style="margin-bottom:.4rem">
          <summary style="cursor:pointer;font-size:.82rem;font-weight:600;padding:.3rem 0">{{ $category['label'] }}</summary>
          <div class="module-grid">
            @foreach($category['features'] as $fKey => $fLabel)
              <label class="module-check"><input type="checkbox" name="features[]" value="{{ $fKey }}" /> {{ $fLabel }}</label>
            @endforeach
          </div>
        </details>
      @endforeach
    </div>

    <button class="btn btn-primary btn-sm" type="submit">Add plan</button>
  </form>
</div>

<div class="card" style="margin-bottom:1.25rem">
  <h3 style="margin-bottom:.4rem">Add on catalog</h3>
  <p class="muted" style="margin-bottom:.8rem">Purchasable on any plan, regardless of tier. Grant or remove these per school from that school's page.</p>
  <div class="module-grid">
    @foreach($addOnCatalog as $category)
      @foreach($category['items'] as $code => $item)
        <span class="badge badge-ok" style="justify-content:flex-start">{{ $item['name'] }}</span>
      @endforeach
    @endforeach
  </div>
</div>

<div class="card">
  <h3 style="margin-bottom:.4rem">Schools</h3>
  <p class="muted" style="margin-bottom:.8rem">"Price / term" is what this school is actually billed per student — its own override if set, otherwise its plan's own price. Overriding never changes which features/modules the school has, only the amount.</p>
  @if($schools->isEmpty())
    <p class="muted">No schools yet.</p>
  @else
    <form id="bulkPriceForm" method="POST" action="{{ route('admin.billing.schools.price.bulk') }}">@csrf</form>
    <div style="overflow-x:auto">
    <table class="table-stack">
      <thead><tr>
        <th style="width:2.2rem"><input type="checkbox" id="billing-school-select-all" aria-label="Select all schools" /></th>
        <th>School</th><th>Current plan</th><th>Change plan</th><th>Price / term</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($schools as $school)
        @php($effectivePrice = $school->price_per_student_per_term_override ?? $school->plan?->price_per_student_per_term)
        <tr>
          <td><input type="checkbox" form="bulkPriceForm" class="billing-school-row-check" name="school_ids[]" value="{{ $school->id }}" aria-label="Select {{ $school->name }}" /></td>
          <td data-label="School"><strong>{{ $school->name }}</strong></td>
          <td class="muted" data-label="Current plan">{{ $school->plan?->name ?? 'No plan set' }}</td>
          <td data-label="Change plan">
            <form method="POST" action="{{ route('admin.billing.schools.assign-plan', $school) }}" style="display:flex;gap:.5rem">
              @csrf
              <select name="plan_id">
                @foreach($plans->where('is_active', true)->where('is_purchasable', true) as $plan)
                  <option value="{{ $plan->id }}" @selected($school->plan_id === $plan->id)>{{ $plan->name }}</option>
                @endforeach
              </select>
              <button class="btn btn-ghost btn-sm" type="submit">Apply</button>
            </form>
          </td>
          <td data-label="Price / term">
            <form method="POST" action="{{ route('admin.billing.schools.price', $school) }}" style="display:flex;gap:.4rem;align-items:center">
              @csrf
              <input type="number" name="price_per_student_per_term" step="0.01" min="0" value="{{ $school->price_per_student_per_term_override }}" placeholder="{{ $school->plan?->price_per_student_per_term ?? '0.00' }}" style="width:7rem" />
              <button class="btn btn-ghost btn-sm" type="submit">Save</button>
            </form>
            @if($school->price_per_student_per_term_override !== null)
              <div class="muted" style="font-size:.72rem;margin-top:.2rem">Custom (plan price: &#8358;{{ number_format((float) ($school->plan?->price_per_student_per_term ?? 0), 2) }})</div>
            @endif
          </td>
          <td><a class="btn btn-ghost btn-sm" href="{{ route('admin.schools.show', $school) }}">Manage features, add ons & waivers</a></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <div style="margin-top:1rem">{{ $schools->links() }}</div>

    <div class="row-between" style="margin-top:.8rem;flex-wrap:wrap;gap:.6rem">
      <span class="muted" style="font-size:.85rem">Tick any number of schools above to change their price per student per term all at once.</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <input form="bulkPriceForm" type="number" name="price_per_student_per_term" step="0.01" min="0" placeholder="New price" style="width:8rem" />
        <button form="bulkPriceForm" class="btn btn-primary btn-sm" type="submit">Apply to selected</button>
        <button form="bulkPriceForm" class="btn btn-ghost btn-sm" type="submit" onclick="this.form.querySelector('[name=price_per_student_per_term]').value=''; return true;">Clear override for selected</button>
      </div>
    </div>
    <script>
      (function(){
        var all = document.getElementById('billing-school-select-all');
        var boxes = document.querySelectorAll('.billing-school-row-check');
        all?.addEventListener('change', function(){ boxes.forEach(function(cb){ cb.checked = all.checked; }); });
        document.getElementById('bulkPriceForm')?.addEventListener('submit', function(e){
          var anyChecked = Array.prototype.some.call(boxes, function(cb){ return cb.checked; });
          if (! anyChecked) { e.preventDefault(); alert('Tick at least one school first.'); }
        });
      })();
    </script>
  @endif
</div>

<style>
  {{--
    UX audit finding (2026-08-10): this table also has class="table-stack"
    (app.blade.php's mobile table-to-cards rule), and .table-stack tr{
    display:block} is TWO class selectors, a higher specificity than the
    single-class .plan-edit-row{display:none} below — on mobile that rule
    silently won regardless of source order, so every plan's edit form
    (RBAC module checklist included) rendered permanently expanded for
    all four plans stacked one after another, producing a page roughly
    9x taller than desktop. Re-declared as a compound selector so it
    wins on its own specificity rather than depending on which stylesheet
    happens to load last; "show" now targets both the plain-table
    (table-row) and stacked-mobile (block) cases explicitly.
  --}}
  tr.plan-edit-row{display:none}
  tr.plan-edit-row.show{display:table-row}
  .table-stack tr.plan-edit-row.show{display:block}
  .module-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.4rem .8rem}
  .module-check{display:flex;align-items:center;gap:.4rem;font-size:.82rem;font-weight:500}
  .module-check input{width:auto}
</style>
@endsection
