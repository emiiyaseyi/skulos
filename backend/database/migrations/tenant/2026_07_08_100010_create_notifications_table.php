<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app / web notifications, one row per recipient. Everyone in an
 * announcement's audience gets one of these regardless of email settings;
 * they populate the notification section on each user's dashboard.
 * user_id references a platform user (no cross database FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('audience')->nullable(); // which audience this recipient was in
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('category')->default('announcement');
            $table->foreignId('announcement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
