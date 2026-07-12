<?php

namespace App\Services\Maintenance;

use App\Models\Backup;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use Illuminate\Support\Carbon;

/**
 * SKULOS Health Engine (Dbase recommendation: "a health check producing a
 * simple health score and actionable findings"). Computed live on page
 * load rather than a stored nightly snapshot — every signal here is cheap
 * to compute (a handful of counts), so a persisted history table was not
 * worth the added complexity for a first pass; revisit if trending over
 * time is wanted later. Every finding here is real: no slow query log,
 * missing index detection, or duplicate/orphaned file scanning exists
 * because no upload feature or query profiler exists in the product yet
 * — those are not faked with a placeholder "OK".
 */
class HealthService
{
    /**
     * @return array{score: int, findings: list<array{severity: string, message: string, action_route: ?string, action_label: ?string}>}
     */
    public function check(School $school): array
    {
        $findings = [];

        $term = Term::current()->first();
        if (! $term) {
            $findings[] = $this->finding('warning', 'No current academic term is set.', 'school.settings.calendar.edit', 'Set a term');
        }

        $unassigned = Student::active()->whereNull('current_class_arm_id')->count();
        if ($unassigned > 0) {
            $findings[] = $this->finding('warning', "{$unassigned} active student(s) have no class assigned.", 'school.students', 'Review students');
        }

        $staffWithoutLogin = Staff::active()->whereNull('user_id')->count();
        $staffTotal = Staff::active()->count();
        if ($staffTotal > 0 && $staffWithoutLogin === $staffTotal) {
            $findings[] = $this->finding('info', 'No staff member has a login yet.', 'school.staff.index', 'Review staff');
        }

        $lastBackup = Backup::where('school_id', $school->id)->orderByDesc('created_at')->first();
        if (! $lastBackup) {
            $findings[] = $this->finding('critical', 'No backup has ever been taken.', 'school.backups.index', 'Back up now');
        } elseif ($lastBackup->created_at->lt(Carbon::now()->subDays(14))) {
            $findings[] = $this->finding('warning', 'Last backup was more than 2 weeks ago.', 'school.backups.index', 'Back up now');
        }

        return [
            'score' => $this->score($findings),
            'findings' => $findings,
        ];
    }

    protected function finding(string $severity, string $message, ?string $actionRoute, ?string $actionLabel): array
    {
        return ['severity' => $severity, 'message' => $message, 'action_route' => $actionRoute, 'action_label' => $actionLabel];
    }

    /** 100, minus a weight per finding severity, floored at 0. */
    protected function score(array $findings): int
    {
        $weights = ['critical' => 30, 'warning' => 15, 'info' => 5];
        $score = 100;

        foreach ($findings as $finding) {
            $score -= $weights[$finding['severity']] ?? 5;
        }

        return max(0, $score);
    }
}
