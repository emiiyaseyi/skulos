<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable copy for every email the platform sends (2026-08-16).
 *
 * Every message was previously written into its Mailable, so changing a
 * word meant a code change and a deploy. Schools differ in tone and in
 * what they need said, and the owner asked to edit all of it.
 *
 * A row here OVERRIDES the code default; no row means the code default
 * stands. That direction matters: it means this table can be empty, a
 * template can be reverted by deleting it, and a mail added later keeps
 * working with no row at all rather than sending a blank message.
 *
 * Platform-level, not per school: these are SKULOS's own messages
 * (trial approval, welcome, password reset). A school's own outgoing
 * announcements are separate and already composed by the school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            // The Mailable's short class name, e.g. TrialRequestStatusMail.
            $table->string('key')->unique();
            $table->string('subject', 500);
            $table->text('body');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
