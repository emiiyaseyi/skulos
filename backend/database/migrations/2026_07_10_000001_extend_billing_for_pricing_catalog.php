<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends billing (tracker section 14, sourced from "SKULOS PRICING PLAN
 * AND FEATURES.docx"): a plan now carries a customer-facing feature
 * checklist ("features") drawn from config('skulos.plan_feature_catalog'),
 * separate from the RBAC "modules" it enables, plus whether it can
 * actually be sold yet ("is_purchasable" — Professional/Enterprise start
 * false, shown "Coming soon" on the marketing site until built). Also
 * adds school_add_ons for the add on marketplace, purchasable on any plan
 * regardless of tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('features')->nullable()->after('modules');
            $table->boolean('is_purchasable')->default(true)->after('is_active');
        });

        Schema::create('school_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('add_on_code'); // key into config('skulos.add_on_catalog')
            $table->text('note')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'add_on_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_add_ons');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['features', 'is_purchasable']);
        });
    }
};
