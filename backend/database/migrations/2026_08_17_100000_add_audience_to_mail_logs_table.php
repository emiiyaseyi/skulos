<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a logged email was actually sent to — parent, student, staff, or
 * applicant (2026-08-17, for the platform mail report: "who the mails
 * are sent to").
 *
 * Nullable, and left null for whole classes of mail that are genuinely
 * none of the four (a trial applicant, a school's own welcome letter, a
 * contact-form enquiry) rather than forcing a guess into one of the
 * four buckets that would misrepresent it. See
 * RecordSentMail::deriveAudience() for how this is actually determined
 * — from the Mailable's own carried data at send time, never from the
 * class name alone, since several Mailables (AccountCreatedMail,
 * every password reset) can legitimately go to any of the four
 * depending on who is actually being emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->string('audience')->nullable()->after('school_id');
            $table->index('audience');
        });
    }

    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropIndex(['audience']);
            $table->dropColumn('audience');
        });
    }
};
