<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication: announcements and a per-message delivery log. Tenant only.
 *
 * The delivery log records every message sent on any channel (email now;
 * SMS, WhatsApp, push later) with its status, so the future Notification
 * Center can show delivery/read state and retries. Channels are enabled
 * from config (nothing hardcoded); only email is wired in this build.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('audience')->default('all'); // all | staff | parents | students | class
            $table->foreignId('class_arm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');  // draft | published
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'audience']);
        });

        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel');                  // email | sms | whatsapp | push | in_app
            $table->string('recipient');                // email address / phone / user id
            $table->string('subject')->nullable();
            $table->string('status')->default('queued'); // queued | sent | failed
            $table->string('error')->nullable();
            $table->foreignId('announcement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'status']);
            $table->index('announcement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('announcements');
    }
};
