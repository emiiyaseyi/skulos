<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Base (Foundation "Support" feature, 2026-07-21: resolved the
 * long-standing ambiguity between the Foundation checklist listing it as
 * launch blocking and nav-school.blade.php excluding the whole "support"
 * category as service commitments — user directive "both", it is a real
 * in-app help/FAQ screen. Platform level, not per-school: this is SKULOS's
 * own product help content ("how do I do X"), the same article set is
 * useful to every school regardless of plan tier or which school it is,
 * so it lives in the platform database, authored once by the platform
 * admin, read by every school's signed-in members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_base_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_category_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body');
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index('is_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_articles');
        Schema::dropIfExists('knowledge_base_categories');
    }
};
