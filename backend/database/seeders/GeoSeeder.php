<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Country to State to LGA reference data for the reusable cascading picker
 * (user request 2026-07-11: applies to every person type a school creates,
 * student, staff, parent, etc). The data itself is not hand typed: it is
 * fetched from two real, public sources and stored as a JSON asset at
 * database/seeders/data/africa-countries-states-lgas.json so this file
 * stays readable rather than embedding thousands of array literals.
 *
 * Sources (fetched 2026-07-11):
 * - dr5hn/countries-states-cities-database (github) for the 54 African
 *   countries and their 1117 states/provinces. The raw dataset tags 60
 *   entries region=Africa; 6 are non-sovereign territories (British Indian
 *   Ocean Territory, French Southern Territories, Mayotte, Reunion, Saint
 *   Helena, Western Sahara), excluded to land on exactly the 54 UN
 *   recognised African states.
 * - xosasx/nigerian-local-government-areas (github) for Nigeria's 774
 *   LGAs. That source spells the FCT state "Federal Capital Territory";
 *   dr5hn spells it "Abuja Federal Capital Territory" — reconciled here so
 *   every LGA maps to the right state row. Every other Nigerian state name
 *   matched exactly between the two sources.
 *
 * Idempotent via upsert on (iso2) / (country_id, name) / (state_id, name),
 * safe to run again.
 */
class GeoSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/africa-countries-states-lgas.json');
        $asset = json_decode(File::get($path), true);

        $now = now();

        // Countries: upsert keyed on iso2, the one column guaranteed
        // unique and stable across re-seeds.
        $countryRows = array_map(fn ($c) => [
            'name' => $c['name'],
            'iso2' => $c['iso2'],
            'sequence' => $c['sequence'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $asset['countries']);
        DB::table('countries')->upsert($countryRows, ['iso2'], ['name', 'sequence', 'updated_at']);

        // Map the source file's own country id (from the dr5hn dataset) to
        // this database's real country id, via the iso2 code both share.
        $iso2ByAssetCountryId = array_column($asset['countries'], 'iso2', 'id');
        $countryIdByIso2 = DB::table('countries')->pluck('id', 'iso2');

        $stateRows = [];
        foreach ($asset['states'] as $s) {
            $iso2 = $iso2ByAssetCountryId[$s['country_id']] ?? null;
            $countryId = $iso2 ? ($countryIdByIso2[$iso2] ?? null) : null;
            if (! $countryId) {
                continue;
            }
            $stateRows[] = [
                'country_id' => $countryId,
                'name' => $s['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        collect($stateRows)->chunk(500)->each(
            fn ($chunk) => DB::table('states')->upsert($chunk->all(), ['country_id', 'name'], ['updated_at'])
        );

        // Nigeria's own states, name to id, to attach LGAs.
        $nigeriaCountryId = $countryIdByIso2['NG'] ?? null;
        $nigeriaStateIdByName = $nigeriaCountryId
            ? DB::table('states')->where('country_id', $nigeriaCountryId)->pluck('id', 'name')
            : collect();

        $lgaRows = [];
        foreach ($asset['lgas'] as $l) {
            $stateId = $nigeriaStateIdByName[$l['state_name']] ?? null;
            if (! $stateId) {
                continue;
            }
            $lgaRows[] = [
                'state_id' => $stateId,
                'name' => $l['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        collect($lgaRows)->chunk(500)->each(
            fn ($chunk) => DB::table('lgas')->upsert($chunk->all(), ['state_id', 'name'], ['updated_at'])
        );
    }
}
