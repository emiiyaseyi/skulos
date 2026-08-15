<?php

namespace App\Services\Maintenance;

use App\Models\Backup;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Storage Dashboard (Dbase recommendation): a real, per-school breakdown
 * of what is actually using space, not an estimate. Database size comes
 * from the tenant SQLite file's real size locally, or MySQL's own
 * information_schema in production; row counts are grouped by what they
 * actually are, only counting tables that exist so this never breaks as
 * the schema grows. File-category byte totals (fileCategories() below,
 * added 2026-08-12 for feedback items 12-13) walk the real directories
 * each upload feature writes to — real sizes, never an estimate.
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
        // Attendance and the per-student report card content tables were
        // missing entirely until 2026-08-15, which mattered once a school
        // held real history: on greenfield they are ~140k rows the
        // breakdown simply never mentioned, so the listed groups could
        // not account for the database size the same screen reported.
        'Attendance' => ['attendance'],
        'Report card content' => ['subject_remarks', 'student_term_remarks', 'student_behavioural_ratings', 'behavioural_traits'],
        'Communication' => ['notifications', 'communication_logs', 'announcements', 'calendar_events', 'chat_threads', 'chat_messages'],
        'Finance' => ['payments', 'fee_items', 'fee_item_school_class', 'bank_accounts'],
        'Admissions' => ['applicants', 'admission_payments', 'admission_documents', 'admission_form_values', 'admission_windows'],
        'Assessments (CBT)' => ['cbt_questions', 'cbt_question_answers', 'cbt_sessions', 'cbt_responses', 'cbt_session_questions', 'cbt_assessments'],
        'Audit trail' => ['audit_logs'],
    ];

    public function breakdown(School $school): array
    {
        $driver = DB::connection('tenant')->getDriverName();

        $files = $this->fileCategories($school);
        $filesBytes = array_sum(array_column($files, 'bytes'));
        $databaseBytes = $this->databaseBytes($driver, $school);
        $backupsBytes = (int) Backup::where('school_id', $school->id)->sum('size_bytes');

        return [
            'database_bytes' => $databaseBytes,
            'groups' => $this->tableGroupCounts(),
            'files' => $files,
            'files_bytes' => $filesBytes,
            'total_bytes' => ($databaseBytes ?? 0) + $filesBytes + $backupsBytes,
            'backups_bytes' => $backupsBytes,
            'backups_count' => Backup::where('school_id', $school->id)->count(),
            'limit_mb' => $school->plan?->storage_limit_mb,
        ];
    }

    /**
     * A light cross-school summary for the platform admin's own storage
     * matrix (feedback items 12-13: "do same for all applicable roles
     * like the platform owner too") — database, files, and backups byte
     * totals without the per-table row counts, so looping every
     * provisioned school stays cheap. Establishes its own temporary
     * tenant connection per school via TenantManager::connectTo(),
     * unlike breakdown() which assumes the caller already connected.
     *
     * @return array{database_bytes: ?int, files_bytes: int, backups_bytes: int, total_bytes: int}
     */
    public function summaryFor(School $school): array
    {
        $databaseBytes = app(\App\Services\Tenancy\TenantManager::class)->connectTo(
            $school,
            fn () => $this->databaseBytes(DB::connection('tenant')->getDriverName(), $school)
        );
        $filesBytes = array_sum(array_column($this->fileCategories($school), 'bytes'));
        $backupsBytes = (int) Backup::where('school_id', $school->id)->sum('size_bytes');

        return [
            'database_bytes' => $databaseBytes,
            'files_bytes' => $filesBytes,
            'backups_bytes' => $backupsBytes,
            'total_bytes' => ($databaseBytes ?? 0) + $filesBytes + $backupsBytes,
        ];
    }

    /**
     * Real per-category file byte totals (feedback list 8/7/2026, items
     * 12-13: "its not showing much about the data usage, what is using
     * more data in terms of breakdown... size of all profiles, size of
     * all profile images, etc"). Each category maps to the exact
     * directory its own upload feature actually writes to (found by
     * sweeping every toWebp()/store() call site), summed by walking the
     * real files on disk — never an estimate. A directory that does not
     * exist yet (feature unused by this school) simply counts 0. Report
     * cards deliberately have no category here: they are generated on
     * demand and never stored, per the standing Database & Storage
     * Efficiency rule, so their disk cost is genuinely zero.
     *
     * @return list<array{label: string, bytes: int, count: int}>
     */
    public function fileCategories(School $school): array
    {
        $categories = [
            'Student profile photos' => ['public', "students/{$school->id}"],
            'School branding, stamps & signatures' => ['public', "schools/{$school->id}"],
            'Chat & message images' => ['public', "chat/{$school->slug}"],
            'Assessment question images' => ['public', "cbt/{$school->slug}"],
            'Admission documents & fee evidence' => ['local', "admissions/{$school->slug}"],
            'Payment evidence' => ['local', "payments/{$school->slug}"],
            'Subscription payment evidence' => ['local', "subscription-payments/{$school->slug}"],
            'Assignment submissions' => ['local', "assignment-submissions/{$school->slug}"],
            'Assessment proctoring captures' => ['local', "assessment/{$school->slug}"],
        ];

        $rows = [];
        foreach ($categories as $label => [$disk, $directory]) {
            [$bytes, $count] = $this->directorySize($disk, $directory);
            $rows[] = ['label' => $label, 'bytes' => $bytes, 'count' => $count];
        }

        usort($rows, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        return $rows;
    }

    /** @return array{0: int, 1: int} total bytes, file count */
    protected function directorySize(string $disk, string $directory): array
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($directory)) {
            return [0, 0];
        }

        $bytes = 0;
        $count = 0;
        foreach ($storage->allFiles($directory) as $file) {
            $bytes += $storage->size($file);
            $count++;
        }

        return [$bytes, $count];
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

    /**
     * Real per-group size on disk alongside the row count (2026-08-15,
     * user report looking at a school holding 235MB of database: the
     * screen showed which groups held the most RECORDS but never which
     * held the most SPACE, so "what is actually using this" was
     * unanswerable from it — the audit trail alone turned out to be the
     * overwhelming majority).
     *
     * 'bytes' is null on SQLite (local dev), where the whole database is
     * one file and per-table sizes need SQLite's optional dbstat module;
     * the view falls back to showing counts alone there rather than
     * inventing a number.
     *
     * @return list<array{label: string, count: int, bytes: ?int}>
     */
    protected function tableGroupCounts(): array
    {
        $sizes = $this->tableSizes();
        $groups = [];
        $accountedTables = [];

        foreach (self::TABLE_GROUPS as $label => $tables) {
            $count = 0;
            $bytes = null;
            foreach ($tables as $table) {
                if (! Schema::connection('tenant')->hasTable($table)) {
                    continue;
                }
                $accountedTables[] = $table;
                $count += DB::connection('tenant')->table($table)->count();
                if (isset($sizes[$table])) {
                    $bytes = ($bytes ?? 0) + $sizes[$table];
                }
            }
            $groups[] = ['label' => $label, 'count' => $count, 'bytes' => $bytes];
        }

        // Catch-all so the listed groups genuinely reconcile to the
        // database total shown at the top of the same screen. Without
        // it, every table nobody thought to add to a group above is
        // silently invisible, which is exactly how a large table can
        // grow unnoticed.
        if ($sizes !== []) {
            $otherBytes = 0;
            foreach ($sizes as $table => $tableBytes) {
                if (! in_array($table, $accountedTables, true)) {
                    $otherBytes += $tableBytes;
                }
            }
            if ($otherBytes > 0) {
                $groups[] = ['label' => 'Everything else', 'count' => 0, 'bytes' => $otherBytes];
            }
        }

        // Largest first, so the answer to "what is using the space" is
        // the first row rather than something to hunt for.
        usort($groups, fn ($a, $b) => ($b['bytes'] ?? 0) <=> ($a['bytes'] ?? 0));

        return $groups;
    }

    /**
     * Real bytes per table, table name => bytes. Empty when the driver
     * cannot report it (SQLite), which callers treat as "unknown" rather
     * than zero.
     *
     * @return array<string, int>
     */
    protected function tableSizes(): array
    {
        if (DB::connection('tenant')->getDriverName() !== 'mysql') {
            return [];
        }

        $rows = DB::connection('tenant')->select(
            'select table_name as name, (data_length + index_length) as bytes from information_schema.tables where table_schema = database()'
        );

        $sizes = [];
        foreach ($rows as $row) {
            $sizes[$row->name] = (int) $row->bytes;
        }

        return $sizes;
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
