<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\School;
use App\Services\Auditing\IntegrityChain;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * One-time (idempotent, safe to re-run) backfill that establishes the
 * hash chain (App\Services\Auditing\IntegrityChain) across every audit_logs
 * row that predates the 2026-07-20 audit log hardening work, on the
 * platform database and every provisioned school's own tenant database.
 * Only touches rows where hash IS NULL, walked in id order from wherever
 * the existing chain (if any) currently ends, so it is safe to run again
 * after new schools are provisioned without re-hashing anything already
 * chained.
 *
 * Honesty note (see also `php artisan audit-logs:verify` and the tracker):
 * this can only prove a row's chain is unbroken from the moment this
 * command established it onward. It cannot detect tampering that happened
 * to historical rows before this feature existed, since it hashes
 * whatever content is currently stored, not what was originally written.
 */
class AuditLogsBuildChain extends Command
{
    protected $signature = 'audit-logs:build-chain {--connection= : Only this connection name (platform default connection, or a school slug)}';

    protected $description = 'Backfill the tamper-evident hash chain onto existing audit_logs rows that predate it';

    public function handle(IntegrityChain $chain, TenantManager $tenants): int
    {
        $only = $this->option('connection');

        if (! $only || $only === 'platform') {
            $this->chainConnection($chain, config('database.default'), 'platform');
        }

        if ($only && $only !== 'platform') {
            $school = School::where('slug', $only)->whereNotNull('provisioned_at')->first();
            if (! $school) {
                $this->error("No provisioned school with slug [{$only}].");

                return self::FAILURE;
            }
            $tenants->connectTo($school, fn () => $this->chainConnection($chain, 'tenant', $school->slug));

            return self::SUCCESS;
        }

        if (! $only) {
            $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();
            foreach ($schools as $school) {
                try {
                    $tenants->connectTo($school, fn () => $this->chainConnection($chain, 'tenant', $school->slug));
                } catch (\Throwable $e) {
                    $this->error("Skipping {$school->slug}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    protected function chainConnection(IntegrityChain $chain, string $connection, string $label): void
    {
        $previousHash = AuditLog::on($connection)
            ->whereNotNull('hash')
            ->orderByDesc('id')
            ->value('hash');

        $chained = 0;

        // chunkById(), not chunk(): each processed row's hash flips from
        // null to non-null, shrinking the whereNull('hash') result set
        // mid-loop. Plain chunk() paginates by OFFSET, so removing rows
        // from the filter as you go shifts every later page and silently
        // skips roughly half the backlog. chunkById() instead keys off
        // the row's own id, immune to the filtered column changing.
        AuditLog::on($connection)
            ->whereNull('hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$previousHash, &$chained, $chain, $connection) {
                // One transaction per chunk rather than one per row: on a
                // backlog of thousands of pre-existing rows (a real
                // possibility on an established install), a commit per row
                // is by far the slowest part of this command.
                \Illuminate\Support\Facades\DB::connection($connection)->transaction(function () use ($rows, &$previousHash, &$chained, $chain, $connection) {
                    foreach ($rows as $row) {
                        $hash = $chain->hashFor($row, $previousHash);

                        // A static query-builder update(), not $row->update():
                        // the latter would hit AuditLog::booted()'s immutable
                        // guard and throw. This is the one sanctioned exception
                        // to that guard, and only ever touches the two chain
                        // columns, never the entry's actual recorded content.
                        AuditLog::on($connection)->whereKey($row->id)->update([
                            'previous_hash' => $previousHash,
                            'hash' => $hash,
                        ]);

                        $previousHash = $hash;
                        $chained++;
                    }
                });
            });

        if ($chained) {
            $this->info("{$label}: chained {$chained} row(s).");
        }
    }
}
