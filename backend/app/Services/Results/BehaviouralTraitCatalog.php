<?php

namespace App\Services\Results;

use App\Models\Tenant\BehaviouralTrait;

/**
 * Seeds the tenant behavioural_traits table from config('skulos.
 * default_behavioural_traits') the first time a school needs it, exactly
 * the same seed-then-DB-backed shape as App\Services\Results\CommentBank
 * (grading_scales/comments already established this pattern; this is the
 * same thing for behavioural/psychomotor traits, 2026-07-18). Public so
 * an explicit "reload defaults" admin action can call it directly later
 * too.
 */
class BehaviouralTraitCatalog
{
    public function ensureSeeded(): void
    {
        if (BehaviouralTrait::exists()) {
            return;
        }

        foreach (config('skulos.default_behavioural_traits', []) as $i => $trait) {
            BehaviouralTrait::create([
                'name' => $trait['name'],
                'type' => $trait['type'] ?? BehaviouralTrait::TYPE_REMARK,
                'max_score' => $trait['max_score'] ?? null,
                'sequence' => $i,
                'is_active' => true,
            ]);
        }
    }
}
