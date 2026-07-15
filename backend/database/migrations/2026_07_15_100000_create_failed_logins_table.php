<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed Logins tracking (Dashboard Design Cross-Check, ICT Officer:
 * "Failed logins/storage/queue monitoring skipped, none of that is
 * tracked yet"). Passive tracking only (log + a dashboard count/list),
 * scope decision 2026-07-15: no account lockout policy. Lives in the
 * platform database, like Backup, since a login attempt happens before
 * any tenant connection is established and school_id can be null (the
 * base platform /login has no school at all).
 *
 * Never stores the attempted password, only the identifier string typed
 * (email/phone/admission number) and why it failed, per the standing
 * "sensitive attributes excluded from logs" security rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identifier');
            $table->string('reason'); // not_found | bad_password | not_member
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_logins');
    }
};
