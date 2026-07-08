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
  backend/              Laravel 12 API + platform admin (being built from 2026-07-08)
  web/                  Next.js frontend (not started yet)
```

The marketing site in `/site` is a **stopgap**, hardcoded HTML, explicitly temporary until the admin-CMS-backed version replaces it (tracker §13). If you touch anything under `site/`, the Pages workflow redeploys it automatically on push.

## Design system rules (apply to every future build — marketing site and product UI)

Established 2026-07-26 from direct design feedback. Full detail in tracker "Design System Rules":
- Card icon + title: **center-aligned**. Card body text: **justified**.
- Section headers/taglines: same — heading centered, paragraph justified.
- Grids must resolve to complete rows/columns at every breakpoint — no dangling last row.
- Keep section spacing tight and deliberate.

## Working conventions

- **Git commits:** always author as the user (`emiiyaseyi`), **no** `Co-Authored-By: Claude` trailer in this repo — see tracker/memory note, this overrides the general default commit workflow for this project specifically.
- **No dummy/placeholder info.** When real information is needed (contact details, pricing, legal names, etc.) and isn't known, ask — don't invent plausible-sounding fake details. Where content had to be authored ahead of real business info being available (About/Contact page copy), it was explicitly authorized by the user as real draft copy, not fabricated placeholder text — see tracker §13.
- **Update the tracker as you go**, not just at the end of a session: mark items in progress, log issues/bugs found and how they were resolved, using the Build Log format defined near the top of the tracker.
- Deferred scope (Admissions & Enrollment, Finance, Visitor Management, School Assets Management as of 2026-07-08) lives in tracker §5b — don't build these until they're explicitly moved back into active scope.

## What's actually been built so far (as of 2026-07-08)

- Documentation: SKULOS.docx Volume 1 Chapters 1–7 (pre-existing, not authored by an agent)
- DEVELOPMENT_TRACKER.md — full backlog derived from the docx + raw feature request
- Static marketing site (index/about/pricing/contact.html) — see tracker §13, explicitly a stopgap
- **No application code exists yet.** Backend, frontend, database schema — none of it has been started. The current build phase (starting 2026-07-08) is the first real application code: Core Modules per tracker §5, minus the four items deferred to §5b.
