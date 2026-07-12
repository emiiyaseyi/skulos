<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subject's own position in its curriculum's declared order (found live
 * 2026-07-12, "Class Order & Structure.docx": a class's subject list was
 * rendering alphabetically instead of the real curriculum order). Set by
 * AcademicDefaults::applySubjectCatalog() from each default_subjects
 * catalog's declared array order, admin editable afterwards via
 * AcademicsController::updateSubject().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
