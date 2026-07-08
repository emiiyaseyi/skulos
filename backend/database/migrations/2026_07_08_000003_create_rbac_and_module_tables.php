<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permission catalog: "{module}.{action}", e.g. "students.bulk".
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('module');
            $table->string('label');
            $table->timestamps();
            $table->index('module');
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unique(['permission_id', 'role_id']);
        });

        // Per-user overrides within one school. "deny" beats a role grant;
        // "allow" grants beyond the role (tracker §4.2: per-user control).
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('mode'); // allow | deny
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'permission_id']);
        });

        // Module catalog (core modules default enabled, optional disabled).
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_core')->default(false);
            $table->timestamps();
        });

        // Feature-toggle states: Enable / Disable / Hide / Read-only, at
        // school, role, or user scope. Most specific scope wins:
        // user > role > school > catalog default.
        Schema::create('module_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type'); // school | role | user
            $table->unsignedBigInteger('scope_id')->nullable(); // role_id or user_id; null for school scope
            $table->string('state'); // enabled | disabled | hidden | readonly
            $table->timestamps();
            $table->unique(['school_id', 'module_id', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_states');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
    }
};
