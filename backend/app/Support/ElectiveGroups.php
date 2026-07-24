<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Collapses a school wide "only one of these" rule (user directive
 * 2026-07-24: "no student can take both Christian Religious Studies and
 * Islamic Studies, they have to select one. Same as the languages,
 * student must only have one of Yoruba, Hausa or Igbo") down from a
 * stream/class's full subject MENU (config('skulos.mutually_exclusive_subjects'),
 * every option really offered, a real choice) to the single subject an
 * individual student actually keeps a score for. Applies school wide,
 * not just to SSS streams, since Junior Secondary also carries both
 * Christian Religious Studies and Islamic Studies as separate subjects.
 */
class ElectiveGroups
{
    /**
     * @param  Collection<int, \App\Models\Tenant\Subject>  $offeredSubjects  the full menu a stream/class offers
     * @param  int  $studentSeed  used for a deterministic pick when the student has no existing score in a group (e.g. the student's own id)
     * @param  Collection<int, int>|null  $alreadyChosenSubjectIds  subject ids the student already has a score for, honoured over the deterministic pick so an existing choice never silently flips on a re-run
     * @return Collection<int, int> subject ids the student should actually be scored on, from within $offeredSubjects
     */
    public static function resolve(Collection $offeredSubjects, int $studentSeed, ?Collection $alreadyChosenSubjectIds = null): Collection
    {
        $alreadyChosenSubjectIds ??= collect();
        $groups = config('skulos.mutually_exclusive_subjects', []);
        $drop = collect();

        foreach ($groups as $group) {
            // Deliberately not $offeredSubjects->keyBy('name')->only($group):
            // Illuminate\Database\Eloquent\Collection::only() overrides the
            // base Collection::only() to mean "only these primary keys", not
            // "only these array keys", so it silently matched nothing here
            // (found via a failing test 2026-07-24, every student kept
            // every menu subject with no collapsing at all).
            $present = $offeredSubjects->filter(fn ($subject) => in_array($subject->name, $group, true))->values();
            if ($present->count() <= 1) {
                continue;
            }

            $existingPick = $present->filter(fn ($subject) => $alreadyChosenSubjectIds->contains($subject->id));
            $chosen = $existingPick->isNotEmpty()
                ? $existingPick->sortBy('id')->first()
                : $present[$studentSeed % $present->count()];

            $drop = $drop->merge($present->reject(fn ($subject) => $subject->id === $chosen->id)->pluck('id'));
        }

        return $offeredSubjects->pluck('id')->diff($drop)->values();
    }
}
