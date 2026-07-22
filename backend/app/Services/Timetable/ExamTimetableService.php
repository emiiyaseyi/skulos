<?php

namespace App\Services\Timetable;

use App\Models\Tenant\ExamPaper;
use App\Models\Tenant\ExamTimetableEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Examination Timetable (user request 2026-07-21): the exam-day paper
 * structure (duration-driven, auto-populated start/end times) and the
 * date x paper grid of which subject a section/class sits.
 */
class ExamTimetableService
{
    public function papers(): Collection
    {
        return ExamPaper::orderBy('sequence')->get();
    }

    /**
     * Replaces the whole paper catalog (a structural redefinition, not an
     * incremental edit — matches how the admin actually thinks about this:
     * "here is my exam day, start to finish"). Chains each paper's
     * starts_at/ends_at off $firstStart + every prior paper's duration.
     *
     * @param  list<array{name: string, duration_minutes: int, is_break: bool}>  $papers
     */
    public function applyPapers(string $firstStart, array $papers): void
    {
        ExamPaper::query()->delete();

        $cursor = Carbon::createFromFormat('H:i', $firstStart);
        foreach ($papers as $index => $paper) {
            $starts = $cursor->copy();
            $ends = $cursor->copy()->addMinutes((int) $paper['duration_minutes']);

            ExamPaper::create([
                'name' => $paper['name'],
                'sequence' => $index + 1,
                'duration_minutes' => $paper['duration_minutes'],
                'is_break' => (bool) ($paper['is_break'] ?? false),
                'starts_at' => $starts->format('H:i'),
                'ends_at' => $ends->format('H:i'),
            ]);

            $cursor = $ends;
        }
    }

    /**
     * @return array<string, ExamTimetableEntry> keyed "date:paperId" for O(1) grid lookup
     */
    public function entriesFor(string $scopeType, int $scopeId): array
    {
        return ExamTimetableEntry::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->with('subject')
            ->get()
            ->keyBy(fn (ExamTimetableEntry $e) => $e->exam_date.':'.$e->exam_paper_id)
            ->all();
    }

    /**
     * @param  array<string, array<int, int|string|null>>  $rows  date => paper_id => subject_id, blank clears that slot
     */
    public function saveEntries(string $scopeType, int $scopeId, array $rows): void
    {
        foreach ($rows as $date => $papers) {
            foreach ($papers as $paperId => $subjectId) {
                if (! $subjectId) {
                    ExamTimetableEntry::where('scope_type', $scopeType)
                        ->where('scope_id', $scopeId)
                        ->where('exam_date', $date)
                        ->where('exam_paper_id', $paperId)
                        ->delete();

                    continue;
                }

                ExamTimetableEntry::updateOrCreate(
                    ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'exam_date' => $date, 'exam_paper_id' => $paperId],
                    ['subject_id' => $subjectId]
                );
            }
        }
    }
}
