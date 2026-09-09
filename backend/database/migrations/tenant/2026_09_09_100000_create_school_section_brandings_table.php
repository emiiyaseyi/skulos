<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section branding override (2026-09-09, user directive: a school
 * running Nursery & Primary under one identity and Secondary under
 * another should be able to give each its own logo and/or colour).
 *
 * One row per section that opts OUT of the school's own default. No row
 * at all means that section simply inherits School.logo_path/brand_color
 * unchanged, so every existing school is completely unaffected. Either
 * column can be overridden independently of the other (a section can
 * override just its logo and still inherit the school's colour, or vice
 * versa) — same "own override replaces the fallback" shape already used
 * by ClassArm.stamp_path/signature_path falling back to School's, and by
 * TimetablePeriod.section_id's "universal row, per-section fork" pattern.
 *
 * `unsignedBigInteger` + a plain index rather than `foreignId()->
 * constrained()`, matching this codebase's own established tenant-
 * migration convention (see 2026_08_16_110000_create_clubs_tables.php) —
 * a real FK constraint on an indexed column has already caused a
 * MySQL-only migration failure once in this project (dropping the index
 * a foreign key depended on), so tenant migrations here avoid them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_section_brandings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('section_id')->unique();
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_section_brandings');
    }
};
