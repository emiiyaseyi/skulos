<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing (user request 2026-07-09): per-child, per-term pricing plans
 * that bundle a set of optional modules, a school's current plan, and
 * per-school free-term waivers. Nothing here is hardcoded — the 4
 * confirmed plans (Starter/Growth/Professional/Enterprise) are seeded as
 * editable rows, not literals in code (CLAUDE.md's standing pricing
 * decision: "must be admin-editable once the CMS exists" — this is
 * that CMS). Core modules (tracker §5) are always available regardless
 * of plan; a plan only gates the optional/toggleable modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price_per_student_per_term', 10, 2);
            $table->json('modules'); // optional module codes included in this plan
            $table->boolean('is_default')->default(false); // offered to a new school by default
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('backup_time')->constrained()->nullOnDelete();
        });

        // A free term (or any other waived billing period) for one school.
        // Terms are tenant scoped data (a different database per school),
        // so this can't be a real foreign key — "label" is a plain
        // description the admin types, e.g. "First Term 2025/2026".
        Schema::create('billing_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('note')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_waivers');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::dropIfExists('plans');
    }
};
