# CLAUDE.md — SKULOS project context

This file exists so any future Claude session (or a different agent entirely) can pick up this project cold and have full context. Read this first, then [DEVELOPMENT_TRACKER.md](./DEVELOPMENT_TRACKER.md) for the complete build backlog and live status.

## What SKULOS is

SKULOS ("The Complete Operating System for Modern Schools") is a multi-tenant Education Operating System / Education ERP SaaS platform for schools, built Africa-first (Nigeria-first specifically). It unifies admissions, academics, finance, communication, and learning into one platform — one login, one source of truth, modules enabled per-school. Full product vision lives in [SKULOS.docx](./SKULOS.docx) (Chapters 1–7 of Volume 1 are complete; everything else is unwritten — see tracker §1).

Positioning: **not** a "School Management System" — an **Education Operating System**. Don't drift back to generic SMS framing in copy or naming.

## Source of truth hierarchy

1. **This file** — orientation, conventions, environment reality.
2. **[DEVELOPMENT_TRACKER.md](./DEVELOPMENT_TRACKER.md)** — the actual backlog, what's built, what's deferred, live build-log status per item, open questions. Always check this before starting work and update it as you go (status/issues/bugs/resolution per item — see the "Build Log" format near the top of the file).
3. **[SKULOS.docx](./SKULOS.docx)** — the original product vision doc. Long, narrative, sometimes repeats itself (it's a captured planning conversation). The tracker already distills everything actionable out of it — you shouldn't need to re-read the docx unless checking original wording/reasoning behind a decision.

## Confirmed technology stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 (requires **PHP ^8.2**) |
| Frontend | Next.js + React + TypeScript |
| UI | Tailwind CSS, ShadCN UI, Framer Motion |
| Database | MySQL (dedicated database per school + one shared platform DB — hybrid multi-tenancy) |
| Queue/Cache | Redis |
| Storage | S3-compatible + local option |
| Search | Laravel Scout + Meilisearch |
| Auth | Laravel Sanctum (SSO); future OAuth, 2FA, passwordless |
| Deployment | **Both** cPanel (Laravel + MySQL) and Vercel (Next.js frontend) must stay viable — don't make architecture choices that lock out either |

## ⚠️ Local environment reality (updated 2026-07-08, second pass)

A working PHP 8.3 + Composer loop **was provisioned in-session on 2026-07-08** (user approved "set up the real stack now"). Current state:

- **PHP 8.3.32** — installed via winget at
  `C:\Users\HP\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`
  with a hand-written `php.ini` in that same folder (extension_dir pointed at the package's `ext\`; curl, fileinfo, mbstring, openssl, pdo_sqlite, sqlite3, zip, sodium, intl, gd, exif enabled). All Laravel 12 required extensions load.
- **Composer 2.10.x** — `composer.phar` + `php.bat`/`composer.bat` shims at `C:\Users\HP\AppData\Local\composer-bin\`
- **PATH trap:** bare `php` on PATH still resolves to the old **PHP 8.1.34** install — always use the 8.3 full path or the shims above, never bare `php`.
- **No MySQL, no Docker, no Redis** — local dev uses **SQLite** as the MySQL stand-in (`pdo_sqlite` is loaded) and `sync`/`database` queue driver instead of Redis. Production targets remain MySQL/Redis per the stack table; don't write SQLite-only SQL (no MySQL-isms in raw queries either — keep to the query builder/Eloquent so both work).

**Practical implication:** verify the shims still exist at the start of a build session (winget upgrades can move package paths and orphan the php.ini). If gone, re-create per above — it takes ~2 minutes and needs no admin rights.

## Confirmed business/data-model decisions

- **Student unique identifier = Admission Number** (school-assigned, not the DB auto-increment id). Used for lookups, bulk-import matching, everywhere a student is referenced externally.
- **Student email is optional.** Never make it a required field or block a workflow on its absence.
- **Parent unique identifier = email address.** Required and unique at the **platform** level (not per-school) — this is how the system recognizes "this is the same parent" across multiple children and multiple schools under common ownership, and what they log in with.
- **Pricing (confirmed):** Starter ₦500 / Growth ₦800 / Professional ₦1,200 / Enterprise ₦1,500 — per student, per term. Must be admin-editable (not hardcoded) once the CMS exists — see tracker §13.
- Full list of confirmed decisions: tracker "Confirmed Business Decisions" section near the top.

## Repo structure (monorepo — decided 2026-07-08)

```
/                     — project docs at root
  CLAUDE.md             this file
  DEVELOPMENT_TRACKER.md  the real backlog — read this
  README.md             GitHub-facing project overview
  SKULOS.docx           source vision doc
  site/                 static marketing site (index/about/pricing/contact.html)
                        deployed to GitHub Pages via .github/workflows/pages.yml
                        (Actions-based deploy — branch-based Pages can't serve /site)
  backend/              Laravel 12 — API-agnostic services PLUS the whole product UI (see below)
  web/                  Next.js frontend — not started, and not currently planned to be (see below)
```

The marketing site in `/site` is a **stopgap**, hardcoded HTML, explicitly temporary until the admin-CMS-backed version replaces it (tracker §13). If you touch anything under `site/`, the Pages workflow redeploys it automatically on push.

**Architecture pivot (decided 2026-07-08, still current):** the product UI (platform admin area + every branded school portal) is built as **Laravel server-rendered Blade pages** inside `backend/`, not the originally planned separate Next.js SPA talking to a REST API. This was to make the whole platform clickable end to end from one entry point without standing up a second app. The services layer (`app/Services/*`) stays UI-agnostic on purpose, so a Next.js frontend in `web/` could still be layered on later if ever wanted — but do not assume that's an active, in-progress goal. Building new screens means building Blade views + controllers in `backend/`, not React components in `web/`.

## Design system rules (apply to every future build — marketing site and product UI)

Established 2026-07-26 from direct design feedback. Full detail in tracker "Design System Rules":
- Card icon + title: **center-aligned**. Card body text: **justified**.
- Section headers/taglines: same — heading centered, paragraph justified.
- Grids must resolve to complete rows/columns at every breakpoint, no dangling last row. Reinforced 2026-07-08: when a set has N items, pick a column count that divides N evenly (8 items render 4x2, not a flex-wrap that leaves 7+1). Do not rely on `flex-wrap` for item sets where a lone orphan can land on the last row; use an explicit grid column count chosen from the item total. This applies to pill rows, card grids, everything.
- Keep section spacing tight and deliberate.

## Working conventions

- **No dashes in on-screen text** (user directive 2026-07-08, restated firmly). User-facing copy must not use hyphens or em/en dashes as punctuation — the user considers it an "AI-generated" tell. This includes text rendered via **CSS `content:` values** and **`aria-label`/`title`/`alt` attributes**, not just `>text<` nodes. When sweeping, check all three: text nodes, CSS `content`, and rendered attributes. Code comments do not render and are exempt. Verify with byte-level checks using unicode escapes (`—` em, `–` en), not literal characters piped through a shell — the shell can mangle the char so a naive check reports a false "0 remaining" (this bit us once on 2026-07-08).
- **Thorough QA after every item build** (user directive 2026-07-08). After finishing each build item, before declaring it done, run a real QA pass and record it in the tracker: (1) run tests / exercise the actual behavior, note issues, bugs, DB errors; (2) ask and answer honestly: *Did I fully solve what the user asked? Did I miss anything they requested? Did I change or break something I should not have touched? Is anything half-done or faked?* Fix what QA surfaces before moving on. This is not optional polish; it is part of "done."
- **Nothing hardcoded — ever** (user directive 2026-07-08, reinforcing docx TD-007 / "Configuration Before Customization"). Anything a school or the Platform Super Admin could plausibly want to change — pricing, plan features, grading formulas, role permissions, module lists, contact details, copy, term counts, thresholds — must live in config, the database, or admin-editable settings, never as literals baked into code paths. The `config/skulos.php` catalog + per-school `settings` table + module/permission tables are the sanctioned homes for this. If you find yourself typing a business value into a controller or view, stop and move it.
- **Security in everything:** deny-by-default access checks, explicit `$fillable` (no `$guarded = []`), sensitive/hidden attributes excluded from audit logs and serialization, tenant isolation preserved even for logs, no raw SQL with interpolated input (the one `CREATE DATABASE` statement uses a slug sanitized to `[a-z0-9_]`), 404 (not 403) for resources whose existence shouldn't leak.
- **Always commit AND push at every completed item** (user directive, restated 2026-07-08). A local commit is not enough — `git push origin main` after every commit so GitHub (https://github.com/emiiyaseyi/skulos) always reflects the latest work. If a push fails (the dev machine has hit transient DNS/network failures), retry until it succeeds and leave a note in the tracker handoff if you must stop before it lands. Never batch many items into one end-of-session push.
- **Git commits:** always author as the user (`emiiyaseyi`), **no** `Co-Authored-By: Claude` trailer in this repo — see tracker/memory note, this overrides the general default commit workflow for this project specifically.
- **No dummy/placeholder info.** When real information is needed (contact details, pricing, legal names, etc.) and isn't known, ask — don't invent plausible-sounding fake details. Where content had to be authored ahead of real business info being available (About/Contact page copy), it was explicitly authorized by the user as real draft copy, not fabricated placeholder text — see tracker §13.
- **Update the tracker as you go**, not just at the end of a session: mark items in progress, log issues/bugs found and how they were resolved, using the Build Log format defined near the top of the tracker.
- Deferred scope (Admissions & Enrollment, Finance, Visitor Management, School Assets Management as of 2026-07-08) lives in tracker §5b — don't build these until they're explicitly moved back into active scope.
- **Domain routing + route parameters gotcha (found 2026-07-09).** On any route under `Route::domain('{school}.'.$domain)` that also has its own path parameters (e.g. `/campuses/{campusId}`), do **not** declare those as typed method parameters on the controller action (`function destroy(Request $request, string $campusId)`). Laravel resolves untyped/scalar controller parameters **positionally** against the full route parameter list, domain parameter included, not by name — so `$campusId` silently receives the school's slug instead. It fails quietly: `findOrFail()` on the wrong value throws `ModelNotFoundException`, which Laravel renders as a clean 404 with no visible exception, so it looks like a routing problem rather than a parameter-binding one. Always read these via `$request->route('paramName')` instead. (A route-bound parameter typed `int` also throws a `TypeError` under Laravel's reflection-based dispatch even with no `declare(strict_types=1)` anywhere in the app — use `string` and let Eloquent/SQLite handle the numeric string.)

## What's actually been built so far

This section intentionally stays brief — **[DEVELOPMENT_TRACKER.md](./DEVELOPMENT_TRACKER.md)'s Build Log is the live, authoritative status**, updated per item as work lands. As of 2026-07-09: full multi-tenant Laravel backend (tenancy, RBAC, audit trail, bulk import/export framework), core modules (students, staff, academic structure incl. configurable per-class subject offerings, attendance, configurable grading/results, calendar, communication/notifications), a complete server-rendered product UI (platform admin + branded, per-school portals with selectable dashboard and login page designs, role-specific dashboards, report card templates), and the static marketing site. See the tracker for exact scope per item and what remains (§5b deferrals, Results/Scores entry screen, guided setup wizard, marketing CMS).
