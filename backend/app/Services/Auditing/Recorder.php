<?php

namespace App\Services\Auditing;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes audit records. Model events flow in via the Auditable trait;
 * anything else (result publishing, logins, bulk imports, ...) can call
 * record() directly with a custom action name.
 */
class Recorder
{
    /**
     * Log a lifecycle event for a model, into that model's own database.
     */
    public function recordModel(Model $model, string $action, array $old = [], array $new = []): void
    {
        $this->write($model->getConnectionName(), [
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    /**
     * Log a custom action, optionally tied to a subject model.
     * $connection defaults to the subject's connection, else the platform DB.
     */
    public function record(string $action, ?Model $subject = null, array $old = [], array $new = [], ?string $connection = null): void
    {
        $this->write($connection ?? $subject?->getConnectionName(), [
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    protected function write(?string $connection, array $data): void
    {
        $request = app()->bound('request') ? request() : null;
        $inConsole = app()->runningInConsole();

        $log = new AuditLog(array_merge($data, [
            'user_id' => auth()->id(),
            'ip_address' => $inConsole ? null : $request?->ip(),
            'user_agent' => $inConsole
                ? 'console'
                : Str::limit((string) $request?->userAgent(), 500, ''),
            'url' => $inConsole ? null : Str::limit((string) $request?->fullUrl(), 2048, ''),
        ]));

        $connectionName = $connection ?? config('database.default');
        $log->setConnection($connectionName);

        // Set created_at explicitly (rather than letting Eloquent's own
        // auto-timestamp fill it in on save) so the value hashed below is
        // guaranteed to be exactly what ends up stored on the row.
        $log->created_at = now();

        // Tamper-evident hash chain (2026-07-20 audit log hardening): each
        // row's hash covers its own fields plus the previous row's hash on
        // this same connection, so an edit made outside this immutable
        // model (e.g. a raw SQL UPDATE) is detectable by IntegrityChecker.
        $previousHash = AuditLog::on($connectionName)->orderByDesc('id')->value('hash');
        $log->previous_hash = $previousHash;
        $log->hash = app(IntegrityChain::class)->hashFor($log, $previousHash);

        $log->save();
    }
}
