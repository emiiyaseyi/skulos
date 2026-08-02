# Load testing the Assessment Hub

Built after a live check found `php artisan serve` (this project's local dev
server) crashes outright under as few as 20 concurrent connections. That
means the local/SQLite dev environment cannot answer "can this handle 100
concurrent exam-takers" at all — the only honest way to answer that is to
point real load at a real staging deployment (PHP-FPM/Nginx + MySQL, the
actual stack this project targets), which is what this is for.

## What's here

- `admission-assessment-load-test.js` — a [k6](https://k6.io) script that
  signs in as N applicants concurrently, starts (or resumes) an admission
  assessment, answers a handful of questions with realistic pacing between
  each, and submits. Reports response times and failure rates broken out
  per step (login / start / save-answer / submit), so a slowdown shows up
  as *which* step, not just "something is slow".

## Before running it

1. Install k6: https://k6.io/docs/get-started/installation/
2. Deploy the code to a real staging environment — not this dev machine.
   `php artisan serve` is single-process and not representative of
   anything.
3. Seed N disposable test applicant accounts on that staging school:

   ```
   php artisan loadtest:seed-applicants <school-slug> \
     --count=100 --start=1 --prefix=APP/ --class="JSS1"
   ```

   `--class` must match whatever the target assessment's
   `applicant_school_class_id` is scoped to (or omit both if the
   assessment has no class restriction at all) — otherwise every start
   attempt correctly 403s as ineligible, which would look like a load
   failure but is actually the eligibility check doing its job.

4. Note the real admission assessment's ID (`CbtAssessment.id`) you want
   to hit.

## Running it

```
BASE_URL=https://your-staging-domain.example \
SCHOOL_SLUG=<school-slug> \
ASSESSMENT_ID=<the assessment's real id> \
APPLICANT_PREFIX=APP/ \
APPLICANT_COUNT=100 \
APPLICANT_START=1 \
k6 run --vus 100 --duration 5m admission-assessment-load-test.js
```

Each pending_fee applicant's own reference number doubles as their sign-in
password (see `Applicant::canSignIn()`), so no separate password list is
needed — the script derives both from the same `APPLICANT_PREFIX` +
serial number.

## After running it

Clean up the disposable accounts so they never show up in a real
Admissions pipeline board:

```
php artisan loadtest:seed-applicants <school-slug> --cleanup --prefix=APP/
```

## What to actually watch, beyond k6's own report

- **PHP-FPM `pm.max_children`** — if this is too low for the hosting
  plan, requests queue or 502 well before the application code is ever
  the bottleneck.
- **MySQL `max_connections`** — same shape of ceiling, one layer down.
- **The real cPanel plan's concurrent-process limit.** Shared hosting
  (this project's actual deployment target, see the repo's own
  `DEVELOPMENT_TRACKER.md`) often caps concurrent PHP processes well
  under 100 — this can hard-block a live cohort exam regardless of
  anything in the application code, and k6 running from outside that
  host won't see the limit directly, only its symptom (timeouts/502s
  once the plan's ceiling is hit).
- Whether `cbt:auto-submit-expired-sessions` (scheduled every minute) is
  actually reached by a working cron on that host — confirm via the
  deployment's own cron log, not by inference from this test.
