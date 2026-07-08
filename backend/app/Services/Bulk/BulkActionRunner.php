<?php

namespace App\Services\Bulk;

use App\Services\Auditing\Recorder;
use Throwable;

/**
 * Applies one action to many records, collecting per-record outcomes
 * instead of dying on the first failure — bulk promote, bulk archive,
 * bulk delete, bulk assign all run through this (tracker §4.3).
 */
class BulkActionRunner
{
    /**
     * @param iterable<int|string> $ids
     * @param callable(int|string): void $action Throw to mark one id failed.
     * @return array{succeeded: list<int|string>, failed: array<int|string, string>}
     */
    public function run(string $auditAction, iterable $ids, callable $action, ?string $connection = null): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            try {
                $action($id);
                $succeeded[] = $id;
            } catch (Throwable $e) {
                $failed[$id] = $e->getMessage();
            }
        }

        app(Recorder::class)->record(
            "bulk.{$auditAction}",
            null,
            [],
            [
                'succeeded' => count($succeeded),
                'failed' => count($failed),
                'ids_failed' => array_keys($failed),
            ],
            $connection ?? config('tenancy.connection'),
        );

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }
}
