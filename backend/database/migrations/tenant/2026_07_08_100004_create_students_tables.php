<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students, their guardians, and enrollment history. Tenant database only.
 *
 * Confirmed decisions (CLAUDE.md):
 *  - Student unique identifier is the Admission Number (school assigned),
 *    unique within the school. It is NOT the auto increment id.
 *  - Student email is optional and must never block a workflow.
 *  - Parent unique identifier is their email at the PLATFORM level; a
 *    guardian row links to a platform user by id (no cross database FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('admission_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('gender')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('email')->nullable();          // optional, never required
            $table->string('phone')->nullable();
            $table->string('parent_mobile')->nullable();  // core field from the original spec

            // Placement
            $table->foreignId('admission_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('current_class_arm_id')->nullable()->constrained('class_arms')->nullOnDelete();
            $table->foreignId('house_id')->nullable()->constrained('houses')->nullOnDelete();

            // Extended profile (all optional)
            $table->string('nationality')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('religion')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('genotype')->nullable();
            $table->string('previous_school')->nullable();
            $table->text('address')->nullable();
            $table->string('passport_path')->nullable();
            $table->date('admitted_on')->nullable();

            // School defined custom fields (custom-fields engine, later)
            $table->json('custom')->nullable();

            $table->string('status')->default('active'); // active | graduated | withdrawn | suspended
            $table->unsignedBigInteger('user_id')->nullable(); // platform login, no cross db FK

            $table->timestamps();
            $table->softDeletes();

            $table->unique('admission_number');
            $table->index('status');
            $table->index('current_class_arm_id');
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship')->nullable(); // Father | Mother | Guardian | ...
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('guardian_user_id')->nullable(); // platform parent account
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index('guardian_user_id');
            $table->index(['student_id', 'is_primary']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active | promoted | repeated | graduated | withdrawn
            $table->date('enrolled_on')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_session_id']);
            $table->index('class_arm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('students');
    }
};
