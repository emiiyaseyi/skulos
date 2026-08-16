<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clubs as a real, assignable thing (2026-08-16).
 *
 * "Club" already appeared on the report card, but only as free text
 * typed per student per term on student_term_remarks — so nothing
 * connected a student to a club, nobody could see a club's membership,
 * and the same child could be in "Debate Club" one term and "debate
 * club" the next with nothing tying them together.
 *
 * Deliberately mirrors houses exactly rather than inventing a second
 * shape: one club per member, the same three member types houses
 * already cover (students, staff, guardians), and the same nullable
 * foreign key on each. That means everything already built for houses
 * -- even distribution, bulk actions, the member lists -- has a direct
 * equivalent here rather than a parallel set of rules to learn.
 *
 * The existing free-text student_term_remarks.club column is left
 * alone: it holds real typed history for terms already recorded, and
 * the report card falls back to it when a student has no assigned club,
 * so no past report card changes retrospectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clubs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // The staff member who runs it, the same role a housemaster
            // plays for a house.
            $table->unsignedBigInteger('patron_staff_id')->nullable();
            $table->unsignedBigInteger('assistant_patron_staff_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('patron_staff_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('club_id')->nullable()->after('house_id');
            $table->index('club_id');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->unsignedBigInteger('club_id')->nullable()->after('house_id');
            $table->index('club_id');
        });

        Schema::table('student_guardians', function (Blueprint $table) {
            $table->unsignedBigInteger('club_id')->nullable()->after('house_id');
            $table->index('club_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['club_id']);
            $table->dropColumn('club_id');
        });
        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex(['club_id']);
            $table->dropColumn('club_id');
        });
        Schema::table('student_guardians', function (Blueprint $table) {
            $table->dropIndex(['club_id']);
            $table->dropColumn('club_id');
        });
        Schema::dropIfExists('clubs');
    }
};
