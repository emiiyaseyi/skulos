<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One or more House Sponsor(s) (user directive 2026-07-12: "let's have the
 * option of selecting from parents as House Sponsor(s) (we can have more
 * than one sponsor) - also, one can type the name of sponsor if the
 * sponsor is not a parent"). Either student_guardian_id (an existing
 * parent) or name (free typed) is set, never both, enforced in
 * HouseController rather than at the DB level (SQLite's CHECK constraint
 * support is limited enough that a mismatch between local and any future
 * MySQL production behaviour is not worth risking over one XOR rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_sponsors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('house_id');
            $table->unsignedBigInteger('student_guardian_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->index('house_id');
            $table->index('student_guardian_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_sponsors');
    }
};
