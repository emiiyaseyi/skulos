<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the new report card needed a place to set (user directive
 * 2026-09-25, "double check that all the items on the report have where
 * teachers can enter them or admin can set it up"):
 *
 * - terms.next_term_begins_on: the date printed beside "Trimester Begins".
 *   It only ever existed as a per-student field that no screen wrote, so
 *   every report said "To be announced". One date per term, set by the
 *   admin with the term dates; a per-student value still wins if present.
 * - class_subject.sequence: a class's own subject order on its report card
 *   (JSS starts English, Mathematics, Civic Education, Basic Technology).
 *   subjects.sequence is school wide, so a subject shared by several
 *   classes cannot sit in a different place in each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->date('next_term_begins_on')->nullable()->after('ends_on');
        });
        Schema::table('class_subject', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->nullable()->after('is_examinable');
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn('next_term_begins_on');
        });
        Schema::table('class_subject', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
