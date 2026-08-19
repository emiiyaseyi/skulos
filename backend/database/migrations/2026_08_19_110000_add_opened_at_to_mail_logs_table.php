<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a sent email was actually opened, best-effort (2026-08-19,
 * direct question: "is it possible to have a read status along with
 * our sent mails. Sent, Delivered, Not delivered, Read/Open").
 *
 * Set the first time a 1x1 tracking pixel embedded in the mail is
 * requested (see MailTrackingController) -- never on any later request,
 * so it records when the mail was FIRST opened, not every reload.
 *
 * Deliberately named "opened_at", not "read_at": nothing here proves a
 * person actually read the message, only that their mail client loaded
 * the pixel image, which is the honest limit of what this technique can
 * ever claim (see the listener's own docblock for why this signal is
 * imprecise in both directions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropColumn('opened_at');
        });
    }
};
