<?php

namespace App\View\Components;

use App\Models\Country;
use App\Models\Lga;
use App\Models\State;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The reusable country to state to LGA cascading picker (user request
 * 2026-07-11: "for uniformity... applicable for all type of user created,
 * student, staff, parents etc"). One partial, field names configurable so
 * a form can prefix/name its three inputs however it needs (the Student
 * form uses "country"/"state_of_origin"/"lga" to match existing columns; a
 * future staff or parent form can pass whatever names it needs, no
 * component changes required).
 *
 * All three stored values are plain name strings (e.g. "Nigeria",
 * "Lagos", "Ikeja"), not foreign key ids: a school's tenant database is a
 * physically separate database/connection from the platform database
 * these reference tables live in, so a cross database foreign key is not
 * possible, and it matches how state_of_origin/lga were already stored
 * before this component existed. The <select> options still carry the
 * platform id as a data attribute purely so the picker's own JS can call
 * the /geo/* endpoints to cascade; only the option's value (the name) is
 * ever submitted.
 *
 * When editing an existing record, the full cascade (state list for the
 * saved country, LGA list for the saved state) is rendered up front
 * server side, so the page never shows an empty state/LGA select on load
 * while the browser plays catch up over JS.
 */
class GeoPicker extends Component
{
    public $countries;

    public $initialStates;

    public $initialLgas;

    public $showLga;

    public function __construct(
        public string $countryName = 'country',
        public string $stateName = 'state',
        public string $lgaName = 'lga',
        public ?string $countryValue = null,
        public ?string $stateValue = null,
        public ?string $lgaValue = null,
        public bool $countryRequired = false,
        public bool $stateRequired = false,
        public bool $lgaRequired = false,
    ) {
        $this->countries = Country::query()->ordered()->get(['id', 'name', 'iso2']);

        $this->initialStates = collect();
        $this->initialLgas = collect();
        $this->showLga = false;

        $selectedCountry = $countryValue
            ? $this->countries->firstWhere('name', $countryValue)
            : null;

        if ($selectedCountry) {
            $this->initialStates = State::query()
                ->where('country_id', $selectedCountry->id)
                ->orderBy('name')
                ->get(['id', 'name']);

            if ($selectedCountry->iso2 === 'NG' && $stateValue) {
                $selectedState = $this->initialStates->firstWhere('name', $stateValue);
                if ($selectedState) {
                    $this->initialLgas = Lga::query()
                        ->where('state_id', $selectedState->id)
                        ->orderBy('name')
                        ->get(['id', 'name']);
                    $this->showLga = $this->initialLgas->isNotEmpty();
                }
            }
        }
    }

    public function render(): View
    {
        return view('components.geo-picker');
    }
}
