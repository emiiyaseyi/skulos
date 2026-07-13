<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending edit to a school member's own profile (name/email/phone),
 * held for a school admin to approve before it actually takes effect
 * (user request: "a user profile page... gated behind a school-admin
 * approval workflow before any change takes effect"). user_id and
 * reviewed_by_user_id reference platform `users` rows (plain ids, no
 * FK, cross-database like every other staff/guardian user_id link in
 * this app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->json('original');
            $table->json('requested');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
