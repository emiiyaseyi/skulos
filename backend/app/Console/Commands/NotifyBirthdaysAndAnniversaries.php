<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Birthday & Anniversary Notifications (user directive 2026-07-16, "ALL
 * THERE": build out the remaining Reports & Analytics/Communication
 * "Soon" items). The dashboard already had a passive "Birthdays" widget
 * (students only, no staff, no anniversaries, nothing actually pushed to
 * anyone), which is a different thing from a genuine *notification*
 * feature: this creates real Notification rows the moment a student's or
 * staff member's birthday, or a staff member's work anniversary, actually
 * falls on today, for the school's leadership plus (for a student) that
 * student's own class teacher, not everyone.
 *
 * Idempotent: a unique title+category+date per person is checked before
 * creating, safe to run more than once on the same day (the schedule
 * fires it once daily, but a manual re-run or a retried failed job must
 * never double up).
 */
class NotifyBirthdaysAndAnniversaries extends Command
{
    protected $signature = 'skulos:notify-birthdays-anniversaries {school? : Only this school\'s slug}';

    protected $description = "Create real in-app notifications for today's student/staff birthdays and staff work anniversaries";

    public function handle(TenantManager $tenants): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')
            ->when($this->argument('school'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $created = $this->notifyForSchool($school);
            $tenants->forget();
            $this->info("{$school->slug}: {$created} notification(s) created.");
        }

        return self::SUCCESS;
    }

    protected function notifyForSchool(School $school): int
    {
        $today = now();
        $todayMonthDay = $today->format('m-d');
        $leadershipUserIds = $this->leadershipUserIds($school);
        $created = 0;

        foreach (Student::active()->whereNotNull('date_of_birth')->with('currentClassArm.schoolClass')->get() as $student) {
            if ($student->date_of_birth->format('m-d') !== $todayMonthDay) {
                continue;
            }
            $age = (int) $today->diffInYears($student->date_of_birth, true);
            $title = "{$student->fullName()}'s birthday today";
            $body = "{$student->fullName()} ({$student->currentClassArm?->label()}) turns {$age} today.";

            $recipients = $leadershipUserIds;
            if ($classTeacherUserId = $this->classTeacherUserId($student)) {
                $recipients = $recipients->push($classTeacherUserId);
            }
            foreach ($recipients->unique() as $userId) {
                $created += $this->notifyOnce($userId, 'birthday', $title, $body);
            }
        }

        foreach (Staff::active()->whereNotNull('date_of_birth')->get() as $staff) {
            if ($staff->date_of_birth->format('m-d') !== $todayMonthDay) {
                continue;
            }
            $age = (int) $today->diffInYears($staff->date_of_birth, true);
            $title = "{$staff->fullName()}'s birthday today";
            $body = "{$staff->fullName()} turns {$age} today.";
            foreach ($leadershipUserIds as $userId) {
                $created += $this->notifyOnce($userId, 'birthday', $title, $body);
            }
        }

        foreach (Staff::active()->whereNotNull('employed_on')->get() as $staff) {
            if ($staff->employed_on->format('m-d') !== $todayMonthDay) {
                continue;
            }
            $years = (int) $today->diffInYears($staff->employed_on, true);
            if ($years < 1) {
                continue; // the hire date itself is not an "anniversary" yet
            }
            $title = "{$staff->fullName()}'s work anniversary today";
            $body = "{$staff->fullName()} marks {$years} year(s) with {$school->name} today.";
            foreach ($leadershipUserIds as $userId) {
                $created += $this->notifyOnce($userId, 'anniversary', $title, $body);
            }
        }

        return $created;
    }

    protected function leadershipUserIds(School $school): \Illuminate\Support\Collection
    {
        $roleIds = Role::where('school_id', $school->id)
            ->whereIn('slug', config('skulos.leadership_role_slugs', []))
            ->pluck('id');

        return DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');
    }

    protected function classTeacherUserId(Student $student): ?int
    {
        $staffId = $student->currentClassArm?->class_teacher_staff_id;

        return $staffId ? Staff::find($staffId)?->user_id : null;
    }

    /** Creates the notification if (and only if) one for this exact person+day does not already exist; returns 1 if created, 0 if it already did. */
    protected function notifyOnce(int $userId, string $category, string $title, string $body): int
    {
        $today = now()->toDateString();
        $exists = Notification::where('user_id', $userId)
            ->where('category', $category)
            ->where('title', $title)
            ->whereDate('created_at', $today)
            ->exists();
        if ($exists) {
            return 0;
        }

        Notification::create([
            'user_id' => $userId,
            'category' => $category,
            'type' => Notification::typeFor($category),
            'title' => $title,
            'body' => $body,
        ]);

        return 1;
    }
}
