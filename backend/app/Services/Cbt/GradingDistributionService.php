<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtGraderAssignment;
use App\Models\Tenant\CbtGradingConfig;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;

/**
 * Shares an assessment's submitted attempts out among its layer-1
 * graders, mirroring the reference repo's own
 * learnguard_distribute_grading().
 *
 * Modes:
 *  - all     no assignment rows at all; every grader sees every attempt
 *  - split   round-robin, so the pile divides evenly (plus or minus one)
 *  - random  each grader takes up to `cap` attempts (default: an even
 *            share), sampled without replacement, so trailing graders
 *            can legitimately end up with none
 *  - manual  no automatic distribution; an admin assigns each attempt
 *            by hand
 *
 * Two deliberate departures from the reference, both fixing real
 * problems rather than preferences:
 *
 * 1. It does NOT wipe and re-deal every existing assignment on each
 *    run. Re-dealing would silently move a half-graded attempt to a
 *    different grader. Already-assigned attempts stay put; only
 *    unassigned ones are dealt, so this is safe to re-run as more
 *    attempts come in.
 * 2. Shuffling is seeded from the assessment id, so a re-run deals the
 *    same way twice. The reference uses an unseeded shuffle(), which
 *    makes its distribution impossible to reproduce or audit.
 */
class GradingDistributionService
{
    public function configFor(CbtAssessment $assessment): CbtGradingConfig
    {
        return CbtGradingConfig::firstOrCreate(
            ['assessment_id' => $assessment->id],
            ['mode' => 'all', 'cap' => 0]
        );
    }

    /** @return \Illuminate\Support\Collection<int, CbtGrader> */
    public function layerOneGraders(CbtAssessment $assessment)
    {
        return CbtGrader::where('grade_layer', CbtGrader::LAYER_FIRST)
            ->where(fn ($q) => $q->where('assessment_id', $assessment->id)->orWhereNull('assessment_id'))
            ->orderBy('id')->get();
    }

    /**
     * Deals every not-yet-assigned submitted attempt. Returns how many
     * assignments were created.
     */
    public function distribute(CbtAssessment $assessment): int
    {
        $config = $this->configFor($assessment);

        if (in_array($config->mode, ['all', 'manual'], true)) {
            return 0; // 'all' needs no rows; 'manual' is assigned by hand
        }

        $graders = $this->layerOneGraders($assessment);
        if ($graders->isEmpty()) {
            return 0;
        }

        $alreadyAssigned = CbtGraderAssignment::where('assessment_id', $assessment->id)->pluck('session_id');

        $pending = CbtSession::where('assessment_id', $assessment->id)
            ->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED])
            ->whereNotIn('id', $alreadyAssigned)
            ->orderBy('id')->pluck('id')->all();

        if ($pending === []) {
            return 0;
        }

        // Seeded so a re-run deals identically and the split can be audited.
        mt_srand($assessment->id);
        shuffle($pending);
        mt_srand();

        $created = $config->mode === 'split'
            ? $this->dealRoundRobin($assessment, $pending, $graders)
            : $this->dealCapped($assessment, $pending, $graders, $config->cap);

        return $created;
    }

    protected function dealRoundRobin(CbtAssessment $assessment, array $pending, $graders): int
    {
        $count = $graders->count();
        foreach ($pending as $index => $sessionId) {
            $this->assign($assessment, $sessionId, $graders[$index % $count]->user_id);
        }

        return count($pending);
    }

    protected function dealCapped(CbtAssessment $assessment, array $pending, $graders, int $cap): int
    {
        $effectiveCap = $cap > 0 ? $cap : (int) ceil(count($pending) / max(1, $graders->count()));
        $created = 0;

        foreach ($graders as $grader) {
            for ($i = 0; $i < $effectiveCap; $i++) {
                $sessionId = array_shift($pending);
                if ($sessionId === null) {
                    return $created; // pile exhausted; later graders get none
                }
                $this->assign($assessment, $sessionId, $grader->user_id);
                $created++;
            }
        }

        return $created;
    }

    protected function assign(CbtAssessment $assessment, int $sessionId, int $graderUserId): void
    {
        CbtGraderAssignment::updateOrCreate(
            ['assessment_id' => $assessment->id, 'session_id' => $sessionId],
            ['grader_user_id' => $graderUserId]
        );
    }

    /** Explicitly hands one attempt to one grader (the 'manual' mode's own action). */
    public function assignManually(CbtAssessment $assessment, CbtSession $session, int $graderUserId): void
    {
        $this->assign($assessment, $session->id, $graderUserId);
    }

    /**
     * The attempts a given grader should actually see.
     *
     * A layer-2 reviewer always sees every submitted attempt — their
     * job is to moderate, which they cannot do on a subset. A layer-1
     * grader sees everything under 'all', and only their own dealt
     * pile under any other mode.
     */
    public function sessionsFor(CbtAssessment $assessment, int $graderUserId, int $layer)
    {
        $query = CbtSession::where('assessment_id', $assessment->id)
            ->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED]);

        if ($layer === CbtGrader::LAYER_REVIEW || $this->configFor($assessment)->mode === 'all') {
            return $query;
        }

        $mine = CbtGraderAssignment::where('assessment_id', $assessment->id)
            ->where('grader_user_id', $graderUserId)->pluck('session_id');

        return $query->whereIn('id', $mine);
    }

    /** How many written answers are still waiting on a human, for reminder emails and badge counts. */
    public function outstandingCount(CbtAssessment $assessment, int $graderUserId, int $layer): int
    {
        $sessionIds = $this->sessionsFor($assessment, $graderUserId, $layer)->pluck('id');

        return CbtResponse::whereIn('session_id', $sessionIds)
            ->where('status', CbtResponse::STATUS_UNGRADED)
            ->count();
    }
}
