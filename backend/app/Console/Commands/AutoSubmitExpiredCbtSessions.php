<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\CbtSessionService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * SKULOS Assessment Hub, Phase 2b: the client-side countdown timer
 * auto-submits on its own, but a browser tab that crashes, loses
 * network, or is simply closed early would otherwise leave a session
 * "in_progress" forever past its own deadline — this is the server-side
 * backstop, the same "never trust the client alone for a deadline"
 * reasoning as admissions:archive-expired-applicants. Runs frequently
 * (every minute, see routes/console.php), not daily, since an exam
 * deadline matters far sooner than an applicant's month-long window.
 */
class AutoSubmitExpiredCbtSessions extends Command
{
    protected $signature = 'cbt:auto-submit-expired-sessions';

    protected $description = 'Force-submits any in_progress CBT session past its own deadline';

    public function handle(TenantManager $tenants, CbtSessionService $sessions): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $expired = CbtSession::where('status', CbtSession::STATUS_IN_PROGRESS)
                ->where('ends_at', '<', now())
                ->get();

            foreach ($expired as $session) {
                $sessions->submit($session, CbtSession::STATUS_AUTO_SUBMITTED);
            }

            $tenants->forget();

            if ($expired->isNotEmpty()) {
                $this->info("{$school->slug}: auto-submitted {$expired->count()} expired session(s).");
            }
        }

        return self::SUCCESS;
    }
}
