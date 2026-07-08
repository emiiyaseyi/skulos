<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and their teaching assignments. Tenant database only.
 *
 * Staff unique identifier is the Staff ID (school assigned). `position` is
 * a free label the school sets (Accountant, Store Keeper, ICT, Director,
 * Class Teacher, ...); it is descriptive. What a staff member can DO in
 * the platform is governed separately by their RBAC role, not this field.
 * Class teacher is recorded on class_arms.class_teacher_staff_id; subject
 * teaching is recorded here in teaching_assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('gender')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();     // free label, configurable
            $table->date('date_of_birth')->nullable();
            $table->date('employed_on')->nullable();
            $table->string('qualification')->nullable();
            $table->text('address')->nullable();
            $table->string('passport_path')->nullable();
            $table->json('custom')->nullable();
            $table->string('status')->default('active'); // active | on_leave | suspended | resigned
            $table->unsignedBigInteger('user_id')->nullable(); // platform login, no cross db FK
            $table->timestamps();
            $table->softDeletes();
            $table->unique('staff_id');
            $table->index('status');
        });

        Schema::create('teaching_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'subject_id', 'class_arm_id']);
            $table->index('class_arm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
        Schema::dropIfExists('staff');
    }
};
