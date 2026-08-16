<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every email this platform sends (2026-08-16).
 *
 * The existing communication_logs table only ever covered a school's
 * own announcements and messages, and lives in each school's own tenant
 * database — so nothing recorded platform mail at all: trial approvals,
 * contact form submissions, password resets, admission letters, license
 * notices. There was no single place to answer "did that email actually
 * go out, and what happened to it".
 *
 * Written by a listener on Laravel's own mail events rather than by
 * each Mailable, so a new email added later is captured automatically
 * instead of depending on someone remembering to log it.
 *
 * Grows unbounded, so it is pruned by the existing retention cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient');
            $table->string('subject', 500)->nullable();
            // The Mailable class, so a row can be traced back to the
            // feature that sent it without parsing the subject line.
            $table->string('mailable')->nullable();
            $table->string('status')->default('sending');
            $table->text('error')->nullable();
            // Which school this concerned, when it is knowable. Nullable
            // because plenty of platform mail (trial requests, contact
            // form) belongs to no school yet.
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index('recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
