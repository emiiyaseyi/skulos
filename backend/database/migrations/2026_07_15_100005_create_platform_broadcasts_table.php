<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform-owner broadcast (2026-07-15, messaging increment 3): the
 * content and audience choice live here in the platform database (a
 * super admin, not any one school, authored it), but delivery to each
 * matched school's owner/admin is written into that school's own tenant
 * database as a real Announcement + Notification rows (see
 * App\Services\Communication\PlatformBroadcastService), the same sign-in
 * pop-up + acknowledge mechanism school-level broadcasts already use.
 * This row is what lets the sender see aggregated read receipts across
 * every school it reached ("this is tracked to the sender to see").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->string('recipient_group'); // owners | admins | owners_and_admins | leadership
            $table->string('school_scope'); // all | plan | selected
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('schools_reached')->default(0);
            $table->unsignedInteger('recipients_reached')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_broadcasts');
    }
};
