<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class's own grading scale, finer grained than sections.grading_scale_id
 * (real bug found live on greenfield, 2026-07-24: "i am viewing a report
 * card in sss and it is showing grade as excellent, good etc which is not
 * what we have under the grading structure for the section remember that
 * sss falls under secondary school as set for the school"). JSS and SSS
 * both sit under one shared "Secondary" Section row (confirmed elsewhere
 * in the app, e.g. the class structure loader), so a section level scale
 * alone cannot tell WAEC-graded SSS apart from NECO/BECE-graded JSS; a
 * class can now override its section's own scale, which still overrides
 * the school wide default, same "most specific wins" precedence the
 * class arm stamp/signature override already established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('grading_scale_id')->nullable()->after('sequence')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grading_scale_id');
        });
    }
};
