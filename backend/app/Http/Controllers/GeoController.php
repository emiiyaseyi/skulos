<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Lga;
use App\Models\State;
use Illuminate\Http\JsonResponse;

/**
 * Read only JSON lookups behind the reusable country to state to LGA
 * picker (user request 2026-07-11, "applicable for all type of user
 * created, student, staff, parents etc"). Platform level reference data,
 * so these routes carry no domain constraint (see routes/web.php, the "on
 * any host" group) and no tenant middleware: they must answer the same way
 * whether the request comes in on the bare app domain or any school
 * subdomain, and resolving a tenant DB connection for a lookup that has
 * nothing to do with any specific school would be wasted work. No auth
 * required, this data is non sensitive; nothing here writes.
 */
class GeoController extends Controller
{
    public function countries(): JsonResponse
    {
        return response()->json(
            Country::query()->ordered()->get(['id', 'name', 'iso2'])
        );
    }

    public function states(string $countryId): JsonResponse
    {
        return response()->json(
            State::query()->where('country_id', $countryId)->orderBy('name')->get(['id', 'name'])
        );
    }

    public function lgas(string $stateId): JsonResponse
    {
        return response()->json(
            Lga::query()->where('state_id', $stateId)->orderBy('name')->get(['id', 'name'])
        );
    }
}
