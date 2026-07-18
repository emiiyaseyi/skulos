<?php

namespace App\Services\Licensing;

use App\Models\School;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Self-hosted license check (user request 2026-07-18). Built as the
 * disclosed, technical alternative to a hidden/unremovable "backdoor"
 * super admin account, which was declined. A school running its own
 * copy of this codebase on its own server calls home to the main
 * platform periodically; if the license can no longer be verified, a
 * grace period starts, then full lockout, exactly the same
 * status/suspension_reason mechanism ResolveTenant already enforces for
 * an overdue platform-hosted subscription (School::SUSPENSION_REASON_BILLING),
 * just a different reason.
 *
 * This one service plays two different roles depending on which side of
 * a self-hosted relationship the current deployment is:
 *  - issueFor()/regenerate()/revoke() and verifyIncoming() run on the
 *    main platform, where the customer's School row lives.
 *  - checkSelfHosted() runs on the school's own separate deployment
 *    (only when config('skulos.self_hosted') is true), calling the
 *    main platform's verify endpoint and enforcing the result against
 *    its own local School row.
 */
class LicenseService
{
    public function generateKey(): string
    {
        return 'SKL-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
    }

    /** Platform side: enable self-hosting for a customer and issue their first key. */
    public function issueFor(School $school): School
    {
        $school->update([
            'is_self_hosted' => true,
            'license_key' => $this->generateKey(),
            'license_issued_at' => now(),
        ]);

        return $school;
    }

    /** Platform side: invalidate the old key and hand out a fresh one, e.g. after a suspected leak. */
    public function regenerate(School $school): School
    {
        $school->update([
            'license_key' => $this->generateKey(),
            'license_issued_at' => now(),
        ]);

        return $school;
    }

    /** Platform side: stop treating this school as self-hosted; every future check-in fails, so its own grace/lockout takes over. */
    public function revoke(School $school): School
    {
        $school->update(['is_self_hosted' => false, 'license_key' => null]);

        return $school;
    }

    /**
     * Platform side: answers an incoming check-in from a self-hosted
     * instance. Valid means the customer's School row here is active;
     * the same suspend/reinstate actions already used for billing
     * (SubscriptionController::demand()/reinstate()) or the schools
     * index's own Suspend action naturally also cut off a self-hosted
     * customer's license the next time it calls home.
     */
    public function verifyIncoming(string $licenseKey): array
    {
        $school = School::where('license_key', $licenseKey)->where('is_self_hosted', true)->first();

        if (! $school) {
            return ['valid' => false, 'reason' => 'unknown_license_key'];
        }

        $school->update(['license_last_verified_at' => now()]);

        if ($school->status !== School::STATUS_ACTIVE) {
            return ['valid' => false, 'reason' => $school->suspension_reason ?: 'inactive'];
        }

        return ['valid' => true, 'reason' => null, 'school_name' => $school->name];
    }

    /**
     * Self-hosted side: called on a schedule (license:check). No-ops
     * unless this deployment is actually configured as self-hosted, so
     * it is safe to always schedule, on every deployment including the
     * main platform's own.
     */
    public function checkSelfHosted(): void
    {
        if (! config('skulos.self_hosted')) {
            return;
        }

        $schools = School::where('is_self_hosted', true)->whereNotNull('license_key')->get();

        foreach ($schools as $school) {
            $this->checkOne($school);
        }
    }

    protected function checkOne(School $school): void
    {
        $endpoint = config('skulos.self_hosted_license.endpoint');
        $graceDays = (int) config('skulos.self_hosted_license.grace_days', 14);

        $valid = false;

        try {
            $response = Http::timeout(10)->post($endpoint, ['license_key' => $school->license_key]);
            $valid = $response->successful() && (bool) $response->json('valid');
        } catch (\Throwable $e) {
            Log::warning("License check-in failed for school {$school->slug}: ".$e->getMessage());
        }

        if ($valid) {
            $updates = ['license_last_verified_at' => now(), 'license_grace_started_at' => null];
            if ($school->status === School::STATUS_SUSPENDED && $school->suspension_reason === School::SUSPENSION_REASON_LICENSE_EXPIRED) {
                $updates['status'] = School::STATUS_ACTIVE;
                $updates['suspension_reason'] = null;
            }
            $school->update($updates);

            return;
        }

        // Invalid response or unreachable: start the grace clock the
        // first time this happens, then lock out once it has run out.
        // An unreachable endpoint counts the same as an explicit
        // "invalid" on purpose — otherwise a school could simply
        // firewall the endpoint to escape the check forever, which
        // would defeat the entire point of a disclosed license check.
        $graceStart = $school->license_grace_started_at ?? now();
        $updates = ['license_grace_started_at' => $graceStart];

        if ($graceStart->diffInDays(now()) >= $graceDays) {
            $updates['status'] = School::STATUS_SUSPENDED;
            $updates['suspension_reason'] = School::SUSPENSION_REASON_LICENSE_EXPIRED;
        }

        $school->update($updates);
    }

    /** For the grace-period warning banner: null once no grace is active, else whole days left before lockout. */
    public function graceDaysRemaining(School $school): ?int
    {
        if (! $school->license_grace_started_at) {
            return null;
        }

        $graceDays = (int) config('skulos.self_hosted_license.grace_days', 14);
        $elapsed = $school->license_grace_started_at->diffInDays(now());

        return max(0, $graceDays - $elapsed);
    }
}
