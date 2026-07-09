<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School calendar and event planner. Tenant only. A new school is seeded
 * with a set of default notable days (Beginning/End of Term, Mid Term
 * Break, Children's Day, Art and Culture Day, Professional Day, Inter
 * House Sports Day, Public Holiday) which the admin can edit, remove, add
 * to, and date. `is_default` marks the seeded ones. Dates are nullable so
 * a default can exist before the school sets its date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->default('general'); // term | break | holiday | celebration | sports | staff | exam | general
            $table->text('description')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('all_day')->default(true);
            $table->boolean('is_default')->default(false); // seeded notable day
            $table->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['starts_on', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
