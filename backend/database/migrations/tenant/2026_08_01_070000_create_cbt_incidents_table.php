<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2g: a incident is either raised
 * automatically (a session's risk_score crosses the "high" threshold
 * for the first time — see RiskScoringService::recordEvent()) or
 * manually by a member of staff watching the live monitor.
 * raised_by_user_id is null for an automatic one, matching this app's
 * existing "null actor = the system" convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('cbt_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->string('type');
            $table->string('severity')->default('info');
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('raised_by_user_id')->nullable();
            $table->string('status')->default('open');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cbt_incidents');
    }
};
