<?php

/*
| Published (2026-07-21) specifically to allow the separately hosted
| static marketing site (GitHub Pages, see .github/workflows/pages.yml)
| to fetch() the public plan catalog cross origin, see PlanCatalogController
| and site/assets/pricing.js. Nothing else in this app currently needs
| CORS: every other route is either same origin (the school/admin portals,
| rendered server side by this same app) or already public with no
| in-browser fetch consumer of its own (geo/*). Scoped as narrowly as
| possible on purpose: 'plans' is read only, public by definition (it is
| marketing pricing data), and carries no cookies/session, so an open
| allowed_origins is safe there without extending it to anything else.
*/

return [

    'paths' => ['plans'],

    'allowed_methods' => ['GET'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
