<?php

namespace App\Services\Maintenance;

use App\Models\PlatformSetting;
use App\Models\Tenant\Setting as TenantSetting;
use App\Services\Tenancy\TenantManager;

/**
 * How many/how recent a backup, per school and the platform's own, is
 * kept before automatic pruning removes it — the single highest-value
 * addition once real disk quota planning started, since unlike
 * notifications/communication logs (RetentionService), backups were
 * never pruned at all and would otherwise accumulate forever. Same
 * platform-then-school precedence as RetentionService itself.
 *
 * A backup is kept if it satisfies EITHER keep_count (among the most
 * recent N) OR keep_days (newer than X days) — only deleted once it
 * fails both, the more forgiving reading, per direct user choice.
 * prune_manual controls whether a manually-triggered backup follows the
 * same rule or is exempt entirely — also admin-editable, not hardcoded
 * either way, per direct user instruction.
 */
class BackupRetentionService
{
    public const KEY = 'backup_retention';

    public function platformSettings(): array
    {
        return (array) PlatformSetting::grab(self::KEY, []);
    }

    public function savePlatformSettings(array $data): void
    {
        PlatformSetting::put(self::KEY, $data);
    }

    /** Only meaningful once the tenant connection points at a school. */
    public function schoolSettings(): array
    {
        return (array) TenantSetting::grab(self::KEY, []);
    }

    public function saveSchoolSettings(array $data): void
    {
        TenantSetting::put(self::KEY, $data);
    }

    protected function defaults(): array
    {
        return [
            'keep_count' => (int) config('skulos.backup_retention.keep_count', 10),
            'keep_days' => (int) config('skulos.backup_retention.keep_days', 90),
            'prune_manual' => (bool) config('skulos.backup_retention.prune_manual', false),
        ];
    }

    /** The platform-wide default, with no school override applied — used for the platform's own backups (School::backups() has no bearing on those). */
    public function effectiveForPlatform(): array
    {
        $platform = array_filter($this->platformSettings(), fn ($v) => $v !== null && $v !== '');

        return array_merge($this->defaults(), $platform);
    }

    /** Falls in an already-cloned school's own override if the tenant connection is active, else the platform default. */
    public function effective(): array
    {
        $resolved = $this->effectiveForPlatform();

        if (app(TenantManager::class)->current()) {
            $school = array_filter($this->schoolSettings(), fn ($v) => $v !== null && $v !== '');
            $resolved = array_merge($resolved, $school);
        }

        return $resolved;
    }
}
