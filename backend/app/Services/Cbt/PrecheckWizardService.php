<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtPrecheckSession;
use Illuminate\Database\Eloquent\Model;

/**
 * SKULOS Assessment Hub, Phase 2d: walks a taker through the pre-exam
 * wizard's own steps (see CbtPrecheckSession::activeSteps()) before any
 * timed CbtSession exists. Completing the wizard's final step is the
 * controller's own job (it calls CbtSessionService::start() and records
 * the resulting session id back onto this row) — this service only ever
 * owns wizard progress, never the timed attempt itself.
 */
class PrecheckWizardService
{
    /** Resumes an in-progress wizard attempt for the same taker+assessment if one exists, otherwise starts a fresh one. */
    public function start(Model $taker, CbtAssessment $assessment): CbtPrecheckSession
    {
        $existing = CbtPrecheckSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', $taker::class)
            ->where('takeable_id', $taker->getKey())
            ->where('status', CbtPrecheckSession::STATUS_IN_PROGRESS)
            ->first();

        if ($existing) {
            return $existing;
        }

        $precheck = CbtPrecheckSession::create([
            'assessment_id' => $assessment->id,
            'takeable_type' => $taker::class,
            'takeable_id' => $taker->getKey(),
            'steps_completed' => [],
        ]);
        $precheck->setRelation('assessment', $assessment);
        $precheck->forceFill(['current_step' => $precheck->activeSteps()[0]])->save();

        return $precheck;
    }

    /**
     * Marks the wizard's CURRENT step done (rejects completing any other
     * step — the only way to move forward is through the step actually
     * being shown, no jumping ahead via a crafted request) and advances
     * to the next active step. `$stepData` is merged onto the precheck
     * row for steps that capture something (photo_id_path, etc).
     * Returns true once every step is done (the controller should then
     * start the real timed session); false if more steps remain.
     */
    public function completeStep(CbtPrecheckSession $precheck, string $step, array $stepData = []): bool
    {
        abort_unless($precheck->status === CbtPrecheckSession::STATUS_IN_PROGRESS, 422, 'This precheck has already finished.');
        abort_unless($step === $precheck->current_step, 422, 'That is not your current step.');

        $completed = array_unique([...($precheck->steps_completed ?? []), $step]);
        $next = $precheck->nextStepAfter($step);

        $updates = array_merge($stepData, ['steps_completed' => array_values($completed)]);

        if ($next === null) {
            $updates['status'] = CbtPrecheckSession::STATUS_COMPLETED;
            $updates['completed_at'] = now();
        } else {
            $updates['current_step'] = $next;
        }

        $precheck->forceFill($updates)->save();

        return $next === null;
    }
}
