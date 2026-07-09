<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which side of a two panel login layout (split/showcase) the sign in box
 * appears on. Admin editable per school; defaults to 'right' (the panel's
 * existing behaviour) so nothing changes for schools that don't set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('login_box_side')->default('right')->after('login_layout');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('login_box_side');
        });
    }
};
