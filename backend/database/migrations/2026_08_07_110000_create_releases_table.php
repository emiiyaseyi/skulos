<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-hosted licensing: release management (2026-08-07). Platform DB
 * only — a packaged code release a self-hosted school can download
 * through the license-gated endpoint (ReleaseDownloadController) instead
 * of raw git/GitHub access. published_at nullable: a release stays a
 * draft (built, checksummed, downloadable only by a platform admin from
 * the Releases screen) until explicitly published, the same draft/live
 * shape Plan::is_purchasable already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->text('notes')->nullable();
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64); // sha256
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
