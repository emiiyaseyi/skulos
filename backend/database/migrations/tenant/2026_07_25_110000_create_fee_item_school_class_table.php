<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which classes a fee item actually applies to (user request 2026-07-25:
 * "fee items can be different for each class... make it possible to
 * select the classes that item is applicable to"). No rows for a given
 * fee item means "applies to every class", the same meaning it already
 * had before this table existed, so every fee item created before this
 * migration keeps behaving exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_item_school_class', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['fee_item_id', 'school_class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_item_school_class');
    }
};
