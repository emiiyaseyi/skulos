<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level user additions: single identity across the ecosystem
        // (TD-005). Parent unique ID = email, already unique on users.
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_super_admin')->default(false)->after('password');
        });

        // Role definitions. school_id null = platform-provided template
        // (Principal, Bursar, Class Teacher, ...); non-null = a role the
        // school created or customized for itself.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'slug']);
        });

        // Which users belong to which school, and as what.
        Schema::create('school_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active | suspended
            $table->timestamps();
            $table->unique(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_user');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_super_admin']);
        });
    }
};
