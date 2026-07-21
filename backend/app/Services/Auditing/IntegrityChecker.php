<?php

namespace App\Services\Auditing;

use App\Models\AuditLog;

/**
 * Walks one connection's audit_logs chain in id order and confirms every
 * row's stored hash still matches what IntegrityChain would compute for
 * it, and that its stored previous_hash still matches the row before it.
 * Used by `php artisan audit-logs:verify` and the admin Audit Log
 * browser's "Verify integrity" action (2026-07-20 audit log hardening).
 */
class IntegrityChecker
{
    public function __construct(protected IntegrityChain $chain)
    {
    }

    /**
     * @return array{ok: bool, checked: int, broken_at_id: int|null, reason: string|null}
     */
    public function verify(?string $connection = null): array
    {
        $connection = $connection ?? config('database.default');

        $previousHash = null;
        $checked = 0;
        $brokenAtId = null;
        $reason = null;

        AuditLog::on($connection)->orderBy('id')->chunk(500, function ($rows) use (&$previousHash, &$checked, &$brokenAtId, &$reason) {
            foreach ($rows as $row) {
                $checked++;

                if ((string) $row->previous_hash !== (string) $previousHash) {
                    $brokenAtId = $row->id;
                    $reason = 'previous_hash does not match the row before it';

                    return false;
                }

                $expected = $this->chain->hashFor($row, $previousHash);
                if (! $row->hash || ! hash_equals($expected, (string) $row->hash)) {
                    $brokenAtId = $row->id;
                    $reason = 'stored hash does not match this row\'s own recomputed hash';

                    return false;
                }

                $previousHash = $row->hash;
            }

            return true;
        });

        return [
            'ok' => $brokenAtId === null,
            'checked' => $checked,
            'broken_at_id' => $brokenAtId,
            'reason' => $reason,
        ];
    }
}
