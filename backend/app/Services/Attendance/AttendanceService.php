<?php

namespace App\Services\Attendance;

use App\Models\Tenant\Attendance;
use App\Models\Tenant\CalendarEvent;
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
     * Why a date is not a normal school day, or null if it is (user
     * directive 2026-07-16: "GREY OUT WEEKEND (SATURDAY AND SUNDAY) AND
     * HOLIDAYS FROM THE ATTENDANCE SO THAT IT WAS NOT PICKED"). A ranged
     * "break" calendar entry (e.g. Mid Term Break) counts as a non school
     * day too, not just a single dated "holiday" entry, since no class is
     * actually in session either way.
     */
    public function nonSchoolDayReason(string|Carbon $date): ?string
    {
        $date = Carbon::parse($date);

        if ($date->isWeekend()) {
            return $date->format('l');
        }

        // whereDate(), not where(): the 'date' cast still saves the full
        // "Y-m-d H:i:s" string (only reads truncate the time), so a raw
        // string equality check against the plain date never matches.
        $event = CalendarEvent::whereIn('category', ['holiday', 'break'])
            ->whereNotNull('starts_on')
            ->where(function ($q) use ($date) {
                $q->whereDate('starts_on', $date->toDateString())
                    ->orWhere(function ($ranged) use ($date) {
                        $ranged->where('starts_on', '<=', $date->toDateString())
                            ->where('ends_on', '>=', $date->toDateString());
                    });
            })
            ->first();

        return $event?->title;
    }

    /**
     * Every non school date (weekends included) within a range, keyed by
     * ISO date, for a client side calendar to grey out before it even
     * submits. Kept to a bounded window by the caller, not the whole
     * calendar's history.
     *
     * @return array<string, string> date => reason
     */
    public function nonSchoolDatesWithin(Carbon $from, Carbon $to): array
    {
        $dates = [];
        $events = CalendarEvent::whereIn('category', ['holiday', 'break'])
            ->whereNotNull('starts_on')
            ->where('starts_on', '<=', $to->toDateString())
            ->where(function ($q) use ($from) {
                $q->where('ends_on', '>=', $from->toDateString())->orWhereNull('ends_on');
            })
            ->get();

        foreach ($events as $event) {
            $start = $event->starts_on->max($from);
            $end = ($event->ends_on ?: $event->starts_on)->min($to);
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dates[$d->toDateString()] = $event->title;
            }
        }

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($d->isWeekend()) {
                $dates[$d->toDateString()] = $d->format('l');
            }
        }

        return $dates;
    }

    /**
     * Mark a whole class register for a date.
     *
     * @param array<int, string> $marks studentId => status
     * @return array{marked: int, date: string}
     */
    public function markClass(int $classArmId, string|Carbon $date, array $marks, ?int $recordedByUserId = null): array
    {
        $date = Carbon::parse($date)->toDateString();
        if ($reason = $this->nonSchoolDayReason($date)) {
            throw new InvalidArgumentException("Attendance cannot be marked for {$date}: it is a {$reason}.");
        }
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
        $dateString = Carbon::parse($date)->toDateString();
        if ($reason = $this->nonSchoolDayReason($dateString)) {
            throw new InvalidArgumentException("Attendance cannot be marked for {$dateString}: it is a {$reason}.");
        }

        return Attendance::updateOrCreate(
            [
                'person_type' => Attendance::PERSON_STAFF,
                'person_id' => $staffId,
                'date' => $dateString,
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
