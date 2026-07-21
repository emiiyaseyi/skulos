<?php

namespace App\Services\Auditing;

use App\Models\AuditLog;

/**
 * The one place the audit log hash-chain algorithm lives (2026-07-20 audit
 * log hardening). Recorder calls hashFor() when writing a new row;
 * IntegrityChecker calls the exact same method when re-verifying an
 * existing one, so the two can never drift apart. Each row's hash covers
 * its own fields plus the previous row's hash, so altering any stored row
 * (by any means other than the immutable model's own insert path, e.g. a
 * raw SQL UPDATE) changes what the NEXT row's hash should have been,
 * breaking the chain from that point forward.
 */
class IntegrityChain
{
    public function hashFor(AuditLog $log, ?string $previousHash): string
    {
        $canonical = implode('|', [
            (string) $log->action,
            (string) $log->auditable_type,
            (string) $log->auditable_id,
            json_encode($log->old_values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($log->new_values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string) $log->user_id,
            (string) $log->ip_address,
            (string) $log->user_agent,
            (string) $log->url,
            optional($log->created_at)->toIso8601String() ?? '',
            (string) $previousHash,
        ]);

        return hash('sha256', $canonical);
    }
}
