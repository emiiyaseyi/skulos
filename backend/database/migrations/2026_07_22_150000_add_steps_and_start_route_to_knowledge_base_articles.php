<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User request 2026-07-22: "this should be presented more as a procedure
 * or step. Do same for all like this. Also for everyone, add a button
 * that takes them to where to start the process or knowledge under
 * help." An article's real, structured procedure (steps) is now a
 * genuine array column, not squeezed into the free-text "body" the
 * article was previously limited to; "body" stays as an optional short
 * intro/description shown above the steps. start_route is a named
 * Laravel route (resolved per school subdomain at render time), picked
 * from a curated catalog (config('skulos.knowledge_base_start_routes'))
 * rather than admin-typed freeform text, so a typo cannot silently break
 * the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->json('steps')->nullable()->after('body');
            $table->string('start_route')->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->dropColumn(['steps', 'start_route']);
        });
    }
};
