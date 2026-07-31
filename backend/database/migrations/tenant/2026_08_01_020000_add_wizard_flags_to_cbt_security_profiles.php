<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2d: extends the four fixed security tiers
 * with the richer flag set the pre-exam wizard needs (audio check, photo
 * ID, face match, screen share, environment scan, a periodic-check
 * interval) — the tier concept itself stays SKULOS's own (LearnGuard has
 * no "tiers," only per-activity flags), but the flag SET now matches
 * what the reference repo actually exposes. See config('skulos.default_
 * cbt_security_profiles') for the seeded values per tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_security_profiles', function (Blueprint $table) {
            $table->boolean('require_audio_check')->default(false)->after('require_object_detection');
            $table->boolean('require_photo_id')->default(false)->after('require_audio_check');
            $table->boolean('require_face_match')->default(false)->after('require_photo_id');
            $table->boolean('require_screen_share')->default(false)->after('require_face_match');
            $table->boolean('require_environment_scan')->default(false)->after('require_screen_share');
            $table->unsignedInteger('snapshot_interval_seconds')->nullable()->after('require_environment_scan');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_security_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'require_audio_check', 'require_photo_id', 'require_face_match',
                'require_screen_share', 'require_environment_scan', 'snapshot_interval_seconds',
            ]);
        });
    }
};
