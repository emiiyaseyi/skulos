<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Attendance;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Attendance\AttendanceService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Populates a year of realistic attendance history for every active
 * student and staff member (user request 2026-07-22: "I want you to
 * populate attendance data for staff and learners for the past one
 * year"). Bulk inserted in chunks via insertOrIgnore(), not one Eloquent
 * create() per row: a real school's worth of people across a real
 * school year is tens of thousands of rows (320 students + 76 staff
 * across roughly 250 real school days, for Greenfield specifically), and
 * insertOrIgnore() means it is safe to re-run without duplicating or
 * overwriting whatever a real user has already genuinely marked.
 */
class PopulateAttendance extends Command
{
    protected $signature = 'attendance:populate {school} {--from=} {--to=}';

    protected $description = 'Populate a year of realistic attendance history for every active student and staff member';

    /** Weighted so most days are ordinary, matching how a real register actually looks. */
    protected function randomStatus(): string
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 90 => 'present',
            $roll <= 95 => 'late',
            $roll <= 98 => 'absent',
            default => 'excused',
        };
    }

    public function handle(TenantManager $tenants, AttendanceService $attendanceService): int
    {
        $school = School::where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with that slug.');

            return self::FAILURE;
        }

        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subYear();
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : now();

        $tenants->use($school);

        $nonSchoolDates = $attendanceService->nonSchoolDatesWithin($from, $to);
        $schoolDates = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (! isset($nonSchoolDates[$d->toDateString()])) {
                $schoolDates[] = $d->toDateString();
            }
        }

        $students = Student::active()->whereNotNull('current_class_arm_id')->get(['id', 'current_class_arm_id']);
        $staff = Staff::where('status', 'active')->get(['id']);

        $now = now()->toDateTimeString();
        $rows = [];
        $inserted = 0;
        $flush = function () use (&$rows, &$inserted) {
            if (empty($rows)) {
                return;
            }
            DB::connection('tenant')->table('attendance')->insertOrIgnore($rows);
            $inserted += count($rows);
            $rows = [];
        };

        foreach ($schoolDates as $date) {
            foreach ($students as $student) {
                $rows[] = [
                    'date' => $date, 'person_type' => Attendance::PERSON_STUDENT, 'person_id' => $student->id,
                    'class_arm_id' => $student->current_class_arm_id, 'status' => $this->randomStatus(),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($rows) >= 1000) {
                    $flush();
                }
            }
            foreach ($staff as $member) {
                $rows[] = [
                    'date' => $date, 'person_type' => Attendance::PERSON_STAFF, 'person_id' => $member->id,
                    'class_arm_id' => null, 'status' => $this->randomStatus(),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($rows) >= 1000) {
                    $flush();
                }
            }
        }
        $flush();

        $tenants->forget();
        $schoolDayCount = count($schoolDates);
        $this->info("Populated attendance across {$schoolDayCount} school day(s) for {$students->count()} student(s) and {$staff->count()} staff member(s) ({$inserted} row(s) attempted, existing marks left untouched).");

        return self::SUCCESS;
    }
}
