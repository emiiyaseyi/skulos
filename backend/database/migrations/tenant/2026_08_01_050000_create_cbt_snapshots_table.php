<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2g: evidence captures taken alongside a
 * concerning proctoring signal (a face mismatch, an object detection) —
 * same "path only, never bytes in the database" rule as every other
 * upload in this app. Deliberately its own table, not bolted onto
 * cbt_security_events, since not every event carries a snapshot (most
 * of the JS-only signals from Phase 2c never do) and a snapshot is
 * viewed as evidence in its own right on the live monitor (Phase 2g).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('cbt_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['session_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cbt_snapshots');
    }
};
