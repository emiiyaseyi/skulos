<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment score-entry windows (user request 2026-07-18: "let each
 * assessment and exam has a window for the teachers to enter their
 * scores, only admin can set this, it can grant extension which the
 * teacher will have to request within the window period, give a reason
 * and when they want the new window to close for them"). One window per
 * (assessment component, term) pair, since the same component (e.g.
 * "First CA") recurs every term but needs its own dates each time. A
 * pair with no row here has no restriction at all, so a school that
 * never sets any window keeps behaving exactly as before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->timestamps();
            $table->unique(['assessment_component_id', 'term_id'], 'assessment_window_component_term_unique');
        });

        // A teacher's personal extension request against one window: "for
        // them" specifically, not a change to the window everyone else
        // sees. Rejecting leaves the window's own opens_at/closes_at as
        // the only thing that applies to that teacher; approving makes
        // requested_close_at effectively their own personal close time,
        // the admin can edit this value before approving ("adjust their
        // window") rather than only ever accept-as-is or reject.
        Schema::create('assessment_window_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->dateTime('requested_close_at');
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_window_extensions');
        Schema::dropIfExists('assessment_windows');
    }
};
