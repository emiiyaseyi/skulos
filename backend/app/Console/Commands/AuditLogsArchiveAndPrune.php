<?php

namespace App\Console\Commands;

use App\Mail\AuditArchiveMail;
use App\Models\School;
use App\Models\User;
use App\Services\Maintenance\AuditArchiveService;
use App\Services\Maintenance\RetentionService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Monthly: archive last month's audit trail per school, mail the school
 * its own copy (a download link by default, see
 * config('skulos.audit_archive')), then prune rows that are both past
 * the school's retention window AND already durably exported.
 *
 * Scheduled on the 1st in routes/console.php. Safe to re-run: buildCsv()
 * overwrites that month's file rather than appending, and prune() only
 * ever removes rows already below the export watermark.
 */
class AuditLogsArchiveAndPrune extends Command
{
    protected $signature = 'audit-logs:archive-and-prune {--school= : Only this school slug} {--no-mail : Archive and prune without sending the monthly mail}';

    protected $description = 'Archive last month\'s audit trail per school, mail it, and prune exported rows past the retention window';

    public function handle(TenantManager $tenants, AuditArchiveService $archive, RetentionService $retention): int
    {
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to = now()->subMonthNoOverflow()->endOfMonth();
        $periodLabel = $from->format('F Y');

        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')
            ->when($this->option('school'), fn ($q) => $q->where('slug', $this->option('school')))
            ->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            try {
                $days = $archive->effectiveDays($retention);
                $csv = $archive->buildCsv($school, $from, $to);
                $pruned = $archive->prune($days);

                $this->info("{$school->slug}: archived {$csv['rows']} row(s) for {$periodLabel}, pruned {$pruned} row(s) older than {$days} days.");

                if (! $this->option('no-mail') && $csv['rows'] > 0) {
                    $this->mailSchool($school, $periodLabel, $csv, $days);
                }
            } catch (\Throwable $e) {
                $this->error("{$school->slug}: {$e->getMessage()}");
            } finally {
                $tenants->forget();
            }
        }

        return self::SUCCESS;
    }

    protected function mailSchool(School $school, string $periodLabel, array $csv, int $days): void
    {
        // School owners/admins only — never every staff member, since an
        // audit trail is exactly the record a school restricts.
        $recipients = User::whereIn('id', \Illuminate\Support\Facades\DB::table('school_user')
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->whereIn('role_id', \App\Models\Role::where('school_id', $school->id)
                ->whereIn('slug', ['school-owner', 'school-admin', 'principal'])
                ->pluck('id'))
            ->pluck('user_id'))
            ->pluck('email')
            ->filter()
            ->all();

        if ($recipients === []) {
            $this->warn("{$school->slug}: no owner/admin email to send the audit archive to.");

            return;
        }

        $maxBytes = ((int) config('skulos.audit_archive.attachment_max_mb', 15)) * 1024 * 1024;
        $attach = config('skulos.audit_archive.attach_csv', false) && $csv['bytes'] <= $maxBytes
            ? $csv['path']
            : null;

        $downloadUrl = URL::temporarySignedRoute(
            'school.audit.download',
            now()->addDays(30),
            ['school' => $school->slug, 'period' => basename($csv['path'], '.csv')]
        );

        Mail::to($recipients)->send(new AuditArchiveMail(
            $school->name, $periodLabel, $csv['rows'], $csv['bytes'], $days, $downloadUrl, $attach
        ));

        $this->line('  mailed to: '.implode(', ', $recipients).($attach ? ' (CSV attached)' : ' (download link)'));
    }
}
