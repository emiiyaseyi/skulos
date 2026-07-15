<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging increment 4 (2026-07-15, user directive: "NOTE THAT MESSAGES
 * WILL HAVE A CHAT WIDGET" / chat reply model confirmed as "private 1-to-1
 * threads"). Scoped to the concrete pairing the user actually asked for:
 * a teacher (class/subject teacher, class assistant) and a guardian of one
 * of their students, about that student. Not a general any-role-to-any-
 * role chat, see App\Http\Controllers\School\ChatController for the
 * authorization that enforces this pairing.
 *
 * `user_one_id`/`user_two_id` are always stored with the smaller id first
 * (normalized in App\Services\Communication\ChatService::threadFor()) so a
 * thread between the same two people about the same student is never
 * created twice regardless of who initiated it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_one_id');
            $table->unsignedBigInteger('user_two_id');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['user_one_id', 'user_two_id', 'student_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->unsignedBigInteger('sender_user_id');
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
