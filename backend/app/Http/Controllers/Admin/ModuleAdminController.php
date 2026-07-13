<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\School;
use App\Services\Rbac\Rbac;

/**
 * Manage Modules (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * dedicated modules screen; module state is set per school inside Billing
 * today"). Every school's optional module state already lives in the
 * platform `module_states` table (Rbac::moduleState()/setModuleState()),
 * this is a cross-school matrix over that same real data instead of
 * having to open each school's own Billing page one at a time. The actual
 * toggle reuses BillingController::toggleFeature() as is, no new mutation
 * logic, this screen is a different way to reach the same real action.
 */
class ModuleAdminController extends Controller
{
    public function index(Rbac $rbac): \Illuminate\View\View
    {
        $schools = School::whereNotNull('provisioned_at')->orderBy('name')->get();
        $modules = Module::optional();

        $matrix = $schools->mapWithKeys(fn (School $school) => [
            $school->id => collect($modules)->keys()->mapWithKeys(
                fn ($code) => [$code => $rbac->moduleState($school, $code)]
            )->all(),
        ]);

        return view('admin.modules', [
            'schools' => $schools,
            'modules' => $modules,
            'matrix' => $matrix,
        ]);
    }
}
