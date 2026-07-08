<?php

namespace App\Services\Attendance;

use App\Models\Tenant\Attendance;
use App\Services\Auditing\Recorder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Records attendance. Marking is idempotent: marking the same person on the
 * same day again updates that day's mark rather than creating a duplicate,
 * so a teacher can correct a register without piling up rows.
 */
class AttendanceService
{
    /**
     * Mark a whole class register for a date.
     *
     * @param array<int, string> $marks studentId => status
     * @return array{marked: int, date: string}
     */
    public function markClass(int $classArmId, string|Carbon $date, array $marks, ?int $recordedByUserId = null): array
    {
        $date = Carbon::parse($date)->toDateString();
        $valid = Attendance::statuses();

        foreach ($marks as $status) {
            if (! in_array($status, $valid, true)) {
                throw new InvalidArgumentException("Invalid attendance status [{$status}].");
            }
        }

        $marked = 0;
        foreach ($marks as $studentId => $status) {
            Attendance::updateOrCreate(
                [
                    'person_type' => Attendance::PERSON_STUDENT,
                    'person_id' => $studentId,
                    'date' => $date,
                ],
                [
                    'class_arm_id' => $classArmId,
                    'status' => $status,
                    'recorded_by_user_id' => $recordedByUserId,
                ]
            );
            $marked++;
        }

        app(Recorder::class)->record(
            'attendance.class_marked',
            null,
            [],
            ['class_arm_id' => $classArmId, 'date' => $date, 'count' => $marked],
            config('tenancy.connection'),
        );

        return ['marked' => $marked, 'date' => $date];
    }

    /**
     * Mark one staff member for a date.
     */
    public function markStaff(int $staffId, string|Carbon $date, string $status, ?int $recordedByUserId = null): Attendance
    {
        if (! in_array($status, Attendance::statuses(), true)) {
            throw new InvalidArgumentException("Invalid attendance status [{$status}].");
        }

        return Attendance::updateOrCreate(
            [
                'person_type' => Attendance::PERSON_STAFF,
                'person_id' => $staffId,
                'date' => Carbon::parse($date)->toDateString(),
            ],
            ['status' => $status, 'recorded_by_user_id' => $recordedByUserId]
        );
    }

    /**
     * Summary counts by status for a class on a date.
     *
     * @return array<string, int>
     */
    public function classSummary(int $classArmId, string|Carbon $date): array
    {
        $date = Carbon::parse($date)->toDateString();

        return Attendance::students()
            ->where('class_arm_id', $classArmId)
            ->forDate($date)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }
}
