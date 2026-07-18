<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-hosted licensing (user request 2026-07-18: a school can run its
 * own copy of this codebase on its own cPanel/subdomain, in place of the
 * hidden/unremovable "backdoor" account the user first asked for and
 * which was declined — see App\Services\Licensing\LicenseService for the
 * full mechanism). These columns are dual-purpose, since the identical
 * schema is deployed twice for a self-hosted customer:
 *  - On the main platform's own `schools` row for that customer: set by
 *    the platform admin (is_self_hosted, license_key, license_issued_at),
 *    with license_last_verified_at stamped every time that instance
 *    successfully calls home.
 *  - On the self-hosted instance's own local `schools` row (a different
 *    database, on the school's own server): the same license_key is
 *    configured there too, and license_grace_started_at/
 *    license_last_verified_at track that instance's own local
 *    grace-then-lockout state, enforced through the school's existing
 *    status/suspension_reason columns (see School::SUSPENSION_REASON_LICENSE_EXPIRED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('is_self_hosted')->default(false)->after('status');
            $table->string('license_key')->nullable()->unique()->after('is_self_hosted');
            $table->timestamp('license_issued_at')->nullable()->after('license_key');
            $table->timestamp('license_last_verified_at')->nullable()->after('license_issued_at');
            $table->timestamp('license_grace_started_at')->nullable()->after('license_last_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['is_self_hosted', 'license_key', 'license_issued_at', 'license_last_verified_at', 'license_grace_started_at']);
        });
    }
};
