<?php

namespace App\Services\Admissions;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;

/**
 * Automated admissions pipeline (2026-08-06, user directive: "the admin
 * determines which class teacher if the class has more than one stream
 * but by default, the class with the lowest number of child get the new
 * student"). Admin override lives on Applicant::preferred_class_arm_id,
 * set at offer time (AdmissionPipelineController::offer()) — this
 * service is only ever consulted as the fallback when that is null.
 */
class ClassArmAssignmentService
{
    public function lowestPopulationArm(SchoolClass $schoolClass): ?ClassArm
    {
        return ClassArm::where('school_class_id', $schoolClass->id)
            ->withCount(['students' => fn ($q) => $q->active()])
            ->orderBy('students_count')
            ->orderBy('id')
            ->first();
    }
}
