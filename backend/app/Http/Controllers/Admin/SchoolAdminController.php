<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform super admin: create and oversee schools. Creating a school
 * provisions its dedicated database, seeds its calendar defaults, and gives
 * it a subdomain, all from this one place.
 */
class SchoolAdminController extends Controller
{
    public function dashboard(): \Illuminate\View\View
    {
        $schools = School::orderByDesc('created_at')->get();

        return view('admin.dashboard', [
            'schools' => $schools,
            'stats' => [
                'schools' => $schools->count(),
                'active' => $schools->where('status', School::STATUS_ACTIVE)->count(),
                'pending' => $schools->where('status', School::STATUS_PENDING)->count(),
            ],
        ]);
    }

    public function store(Request $request, TenantManager $tenants): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'alpha_dash', 'max:60', Rule::unique('schools', 'slug')],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $slug = $data['slug'] ?: Str::slug($data['name']);
        if (School::where('slug', $slug)->exists()) {
            return back()->with('error', "A school with the address '{$slug}' already exists.")->withInput();
        }

        $school = School::create([
            'name' => $data['name'],
            'slug' => $slug,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        $tenants->provision($school);

        // The creator becomes the school owner so they can set it up.
        $school->users()->syncWithoutDetaching([
            Auth::id() => ['role_id' => null, 'status' => 'active'],
        ]);

        return redirect()->route('admin.dashboard')
            ->with('status', "{$school->name} is ready at {$school->slug}. Open it to finish setup.");
    }

    public function show(School $school): \Illuminate\View\View
    {
        return view('admin.school', ['school' => $school]);
    }
}
