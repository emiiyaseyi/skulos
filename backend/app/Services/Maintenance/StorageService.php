<?php

namespace App\Services\Maintenance;

use App\Models\Backup;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storage Dashboard (Dbase recommendation): a real, per-school breakdown
 * of what is actually using space, not an estimate. Database size comes
 * from the tenant SQLite file's real size locally, or MySQL's own
 * information_schema in production; row counts are grouped by what they
 * actually are, only counting tables that exist so this never breaks as
 * the schema grows. No image/document/log-file byte totals are shown
 * here (student photos, generated PDFs, uploaded documents) because none
 * of those features exist in the product yet — nothing here is invented.
 */
class StorageService
{
    /**
     * Logical groupings of tenant tables a school admin actually
     * recognizes, rather than a raw table-by-table dump.
     */
    protected const TABLE_GROUPS = [
        'Students & Guardians' => ['students', 'student_guardians'],
        'Staff' => ['staff'],
        'Academics' => ['academic_sessions', 'terms', 'sections', 'school_classes', 'class_arms', 'arms', 'subjects', 'class_subject', 'teaching_assignments', 'houses'],
        'Results' => ['scores', 'assessment_components', 'grading_scales', 'grading_bands', 'report_card_reviews', 'report_card_feedbacks'],
        'Communication' => ['notifications', 'communication_logs', 'announcements', 'calendar_events'],
        'Audit trail' => ['audit_logs'],
    ];

    public function breakdown(School $school): array
    {
        $driver = DB::connection('tenant')->getDriverName();

        return [
            'database_bytes' => $this->databaseBytes($driver, $school),
            'groups' => $this->tableGroupCounts(),
            'backups_bytes' => (int) Backup::where('school_id', $school->id)->sum('size_bytes'),
            'backups_count' => Backup::where('school_id', $school->id)->count(),
            'limit_mb' => $school->plan?->storage_limit_mb,
        ];
    }

    protected function databaseBytes(string $driver, School $school): ?int
    {
        if ($driver === 'sqlite') {
            $path = config('tenancy.sqlite_path')."/{$school->slug}.sqlite";

            return file_exists($path) ? filesize($path) : null;
        }

        if ($driver === 'mysql') {
            $row = DB::connection('tenant')->selectOne(
                'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()'
            );

            return $row?->bytes !== null ? (int) $row->bytes : null;
        }

        return null;
    }

    /** @return list<array{label: string, count: int}> */
    protected function tableGroupCounts(): array
    {
        $groups = [];

        foreach (self::TABLE_GROUPS as $label => $tables) {
            $count = 0;
            foreach ($tables as $table) {
                if (Schema::connection('tenant')->hasTable($table)) {
                    $count += DB::connection('tenant')->table($table)->count();
                }
            }
            $groups[] = ['label' => $label, 'count' => $count];
        }

        return $groups;
    }

    public static function humanBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Unknown';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
