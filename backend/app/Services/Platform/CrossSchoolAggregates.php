<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Tenancy\TenantManager;

/**
 * Total Students/Staff/Parents on the platform dashboard (Dashboard Design
 * Cross-Check, 2026-07-13: "Would need summing across every school's own
 * database live; not built"). Each school's own numbers only exist inside
 * that school's own tenant database, so this genuinely has to loop every
 * provisioned school and connect to each in turn, same pattern
 * MigrateTenants/OptimizeTenantDatabases already use. One school's tenant
 * database failing to connect (corrupt file, mid-restore, ...) must never
 * take the whole platform dashboard down with it, so each school is wrapped
 * in its own try/catch and simply skipped, not fatal.
 */
class CrossSchoolAggregates
{
    /**
     * @return array{schools_counted: int, students: int, staff: int, parents: int}
     */
    public function totals(TenantManager $tenants): array
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        $students = 0;
        $staff = 0;
        $parents = 0;
        $counted = 0;

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
                $students += Student::active()->count();
                $staff += Staff::active()->count();
                $parents += StudentGuardian::get(['email', 'phone', 'name'])
                    ->map(fn ($g) => $g->email ?: ($g->phone ?: $g->name))
                    ->unique()
                    ->count();
                $counted++;
            } catch (\Throwable $e) {
                // Skip a school whose tenant database cannot be reached
                // right now, rather than failing the whole dashboard.
            } finally {
                $tenants->forget();
            }
        }

        return ['schools_counted' => $counted, 'students' => $students, 'staff' => $staff, 'parents' => $parents];
    }
}
