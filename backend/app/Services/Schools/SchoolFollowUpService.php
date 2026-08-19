<?php

namespace App\Services\Schools;

use App\Mail\SchoolFollowUpNudgeMail;
use App\Models\Role;
use App\Models\School;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the manual "checking in on your setup" nudge (2026-08-19) to
 * every active owner-tier admin of a school. One place for this rather
 * than duplicating it in both callers: Admin\SchoolAdminController's
 * own nudge action (for a directly-created school) and
 * Admin\TrialRequestAdminController's (for an approved trial, once its
 * School exists) need the identical recipient resolution and send.
 */
class SchoolFollowUpService
{
    /** @return int how many admins were actually emailed */
    public function nudge(School $school): int
    {
        // Same school-scoped-role lookup SchoolAdminController::show()
        // already uses (2026-07-16 fix: a school's own cloned "School
        // Owner" role, not the shared template id=1) -- reused here
        // rather than re-derived, so the two never drift apart.
        $ownerRoleId = Role::where('school_id', $school->id)->where('slug', 'school-owner')->value('id');

        $admins = $school->users()
            ->wherePivot('role_id', $ownerRoleId)
            ->wherePivot('status', 'active')
            ->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->send(new SchoolFollowUpNudgeMail($school, $admin->name));
        }

        return $admins->count();
    }
}
