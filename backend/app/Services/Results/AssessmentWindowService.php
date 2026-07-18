<?php

namespace App\Services\Results;

use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\AssessmentWindow;
use App\Models\Tenant\AssessmentWindowExtension;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Term;

/**
 * Whether a teacher may currently enter scores for one assessment
 * component in one term (user request 2026-07-18). A component/term with
 * no configured window is always open. Within the window: open. Outside
 * it: open only if this specific staff member has an approved extension
 * whose requested_close_at has not passed yet (and, if the window itself
 * has an opens_at, only once that has also arrived).
 */
class AssessmentWindowService
{
    public function windowFor(int $componentId, int $termId): ?AssessmentWindow
    {
        return AssessmentWindow::where('assessment_component_id', $componentId)->where('term_id', $termId)->first();
    }

    public function isOpenFor(AssessmentComponent $component, Term $term, ?Staff $staff): bool
    {
        $window = $this->windowFor($component->id, $term->id);
        if (! $window) {
            return true;
        }

        $now = now();
        $withinDefaultWindow = (! $window->opens_at || $now->gte($window->opens_at))
            && (! $window->closes_at || $now->lte($window->closes_at));
        if ($withinDefaultWindow) {
            return true;
        }

        if (! $staff) {
            return false;
        }

        $approved = AssessmentWindowExtension::where('assessment_window_id', $window->id)
            ->where('staff_id', $staff->id)
            ->where('status', AssessmentWindowExtension::STATUS_APPROVED)
            ->latest('reviewed_at')
            ->first();

        if (! $approved) {
            return false;
        }

        return (! $window->opens_at || $now->gte($window->opens_at)) && $now->lte($approved->requested_close_at);
    }
}
