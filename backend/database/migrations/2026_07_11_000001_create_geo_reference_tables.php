<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country to State to LGA reference data (user request 2026-07-11: a
 * reusable cascading picker for every person type a school creates,
 * student first). Platform level, not tenant, since it is shared reference
 * data with nothing school specific about it (tracker's hybrid
 * multi-tenancy: dedicated DB per school plus one shared platform DB, this
 * belongs in the shared one). Seeded by GeoSeeder from real, fetched data
 * (dr5hn/countries-states-cities-database for African countries and
 * states, xosasx/nigerian-local-government-areas for Nigeria's 774 LGAs),
 * not hand typed. Read only from the app's point of view: no admin screen
 * edits these, the /geo/* endpoints only ever list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('iso2', 2)->unique();
            // Nigeria gets 1 so it sorts first; every other African country
            // is null and sorts alphabetically after it. Not used for any
            // other country, but any future "pin this one to the top" need
            // (a second Africa first market) can reuse the same column.
            $table->unsignedInteger('sequence')->nullable();
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unique(['country_id', 'name']);
            $table->timestamps();
        });

        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            // Only ever populated for Nigeria's states; every other
            // country's states simply have zero rows here, and the picker
            // hides the LGA <select> whenever that is the case.
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unique(['state_id', 'name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgas');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
