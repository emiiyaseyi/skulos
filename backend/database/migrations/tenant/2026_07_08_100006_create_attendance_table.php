<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily attendance for both students and staff, one row per person per day.
 * A single table with a person_type discriminator keeps student and staff
 * attendance consistent. class_arm_id is set for students (the register a
 * teacher marks); it is null for staff. Status values come from the
 * configurable list in config/skulos.php, not a hardcoded enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('person_type');            // student | staff
            $table->unsignedBigInteger('person_id');
            $table->foreignId('class_arm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');                 // present | absent | late | excused | ...
            $table->string('note')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['person_type', 'person_id', 'date']);
            $table->index(['class_arm_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
