<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Campus;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's physical campuses/branches (user request 2026-07-09: owners
 * and admins want a campus count on their dashboard). Deliberately simple:
 * a name, address, and whether it is the main site.
 */
class CampusController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.campuses.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'campuses' => Campus::orderByDesc('is_main')->orderBy('name')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'school_info.manage_settings'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:400'],
            'is_main' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_main')) {
            Campus::query()->update(['is_main' => false]);
        }

        Campus::create([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'is_main' => $request->boolean('is_main'),
        ]);

        return back()->with('status', 'Campus added.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        Campus::findOrFail($request->route('campusId'))->delete();

        return back()->with('status', 'Campus removed.');
    }

    public function bulkDestroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate([
            'campus_ids' => ['required', 'array', 'min:1'],
            'campus_ids.*' => ['exists:tenant.campuses,id'],
        ]);

        $count = Campus::whereIn('id', $data['campus_ids'])->count();
        Campus::whereIn('id', $data['campus_ids'])->delete();

        return back()->with('status', "{$count} campus(es) removed.");
    }
}
