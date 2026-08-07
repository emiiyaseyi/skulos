# SKULOS deployment and preview

Two very different pieces, deployed differently.

| Piece | What it is | Where it can run |
|---|---|---|
| `site/` | Static marketing site (HTML, CSS, JS) | Netlify, Vercel, GitHub Pages, any static host |
| `backend/` | Laravel 12 API + platform admin (PHP + MySQL) | cPanel, a VPS, or any PHP host. **Not** Netlify or Vercel |

Netlify and Vercel do not run PHP applications, so they host the marketing site only. The Laravel backend goes on the cPanel host (the originally planned target) or any server with PHP 8.2+ and MySQL.

---

## Preview the marketing site

### Localhost
The site is plain static files. Any of these work from the repo root:

```bash
# Option 1: Python (built in)
cd site && python -m http.server 8899
# then open http://127.0.0.1:8899

# Option 2: PHP built in server
php -S 127.0.0.1:8899 -t site
# then open http://127.0.0.1:8899
```

You can also just open `site/index.html` in a browser, though a local server is closer to production (relative asset paths, clean URLs).

### Netlify
1. Connect the GitHub repo in Netlify.
2. `netlify.toml` at the repo root already sets `publish = "site"` with no build command and clean URLs, so no manual settings are needed.
3. Deploy. Every push to `main` redeploys.

### Vercel
`vercel.json` at the repo root sets `"outputDirectory": "site"`, which tells
Vercel to serve that folder as the site root with no build step and no
dashboard configuration needed — `/` serves `site/index.html`, `/about`
serves `site/about.html` (clean URLs on), etc. Just import the repo and
deploy; every push to `main` redeploys automatically.

> **If you still see `404: NOT_FOUND` after a redeploy** (this has recurred
> more than once — `outputDirectory` alone does not always take effect on
> every Vercel project depending on when/how it was first imported), the
> guaranteed fix is the dashboard setting: Vercel project → **Settings →
> Build and Deployment → Root Directory** → set to `site`, framework preset
> **Other**, no build command. Save and redeploy. This makes Vercel treat
> `/site` as the project root directly, independent of `vercel.json`, and
> is the more reliable of the two mechanisms.
>
> Root cause of the original 404: the project served the repo root, which
> has no `index.html` (the real site lives in `/site`).

### GitHub Pages
Already wired: `.github/workflows/pages.yml` deploys `site/` on every push that touches it. Enable Pages once in the repo settings (Source: GitHub Actions).

---

## Run the backend locally

The backend is the full product UI now (platform admin + every branded school portal), server rendered with Laravel Blade — not API only.

```bash
cd backend
# use the provisioned PHP 8.3 + Composer (see CLAUDE.md for exact paths/shims), or just double-click serve.bat
php artisan migrate            # platform database (SQLite locally)
php artisan db:seed            # RBAC catalog: modules, permissions, role templates
php artisan school:provision "Demo Academy"   # create a school + its tenant database
php artisan test               # full test suite
php artisan serve              # serves the app at http://127.0.0.1:8000
```

Local dev uses SQLite as a stand in for MySQL. Production uses MySQL per the confirmed stack; keep schema and queries driver neutral (no SQLite only or MySQL only SQL).

### Backend on cPanel (production, the real target)
- PHP 8.2+ with the usual Laravel extensions, plus a MySQL user allowed to `CREATE DATABASE` (needed for per school tenant databases).
- Point the domain document root at `backend/public`.
- Set `.env` for production (APP_ENV=production, MySQL credentials, `TENANCY_DRIVER=mysql`), run `composer install --no-dev`, `php artisan migrate --force`, `php artisan db:seed --force`.
- **Every deploy that adds or changes a file under `database/migrations/tenant/`, a new `extra_permissions` entry, or a module/plan change, run `php artisan skulos:resync` (add `--modules` for the plan/module case) right after `php artisan migrate --force`.** This one command replaces three separate manual gotchas that have each bitten this project before (see CLAUDE.md): a school's own database never receives a tenant migration added after it was provisioned; a school that cloned a wildcard role (School Owner/Admin, Principal, Head Teacher) before a new permission existed never receives it; a `module_states` row written once at plan-assignment time silently outlives a later module going from "no screen" to "has one". Run it locally too before every deploy that touches any of these, not just in production.
- **Run `php artisan storage:link` once after every fresh deploy** (a symlink, not copied files, so it does not survive a redeploy that replaces the `public/` directory from scratch). Without it, every uploaded file served through the `public` disk, school branding logos, report card stamps/signatures, chat attachments, silently 404s even though the upload itself succeeded, since `Storage::url()` on the `public` disk always resolves to `APP_URL/storage`, which only exists once this symlink is created. Some shared hosts disable PHP's `symlink()` function for security; if `storage:link` errors or the link does not resolve, `php artisan storage:link --relative` is the next thing to try, and if that also fails the fallback is asking the host to enable `symlink()` or manually pointing `public/storage` at `storage/app/public` through cPanel File Manager's own "create symlink" tool if it offers one.
- No separate queue worker/daemon needs setting up (2026-07-30): announcement and platform broadcast emails are queued (`SendAnnouncementEmailJob`/`SendPlatformBroadcastEmailJob`, `QUEUE_CONNECTION=database`) rather than sent inline in the request, so a large "all" audience send no longer risks timing out the HTTP request that triggered it. The scheduler runs `queue:work --stop-when-empty` every minute (`routes/console.php`) to drain whatever is queued, piggybacking on the same `schedule:run` cron entry below, no second cron line needed. This keeps shared hosting simple (no persistent background process to babysit) at the cost of up to about a minute's delay before a queued email actually goes out, acceptable for announcements/broadcasts. Other bulk operations (a large student import that provisions logins) still run synchronously inline, worth revisiting the same way if volume ever makes that noticeable.
- Redis and Meilisearch are optional locally; wire Redis in production once queue volume outgrows the `database` driver's polling, and Meilisearch for search if/when that's introduced.
- Add `php artisan schedule:run` to cPanel's cron every minute (`* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`), or nothing scheduled (backups, etc) will ever fire. Confirm with `php artisan schedule:list` after setup.

### Self-hosted deployment: one school running its own copy on its own cPanel account

This is a different scenario from the "Backend on cPanel" section above. That section is SKULOS's own production hosting, one Laravel install serving every school as a subdomain tenant. This section is for a single school that pays to run its own dedicated copy of the app on its own cPanel account, its own MySQL, its own domain, with no other school sharing that server. The app enforces this arrangement with a disclosed license check (`App\Services\Licensing\LicenseService`): the school's install calls the main SKULOS platform on a schedule to confirm the subscription is current; if it cannot, a warning banner shows, then after a grace period the install locks itself out until it can verify again. This is not hidden from the school, it is a normal part of what they are told they are getting.

**What to copy.** Only `backend/` needs to go to the school's server, the `site/` marketing pages and repo root docs are not part of a school's install. Either:
- `git clone` the repo on the school's server (if the host gives shell/git access) and work from `backend/`, or
- zip `backend/` locally (exclude `vendor/`, `node_modules/`, `.env`, and `storage/framework/*` cache files) and upload/extract it through cPanel File Manager, then run Composer on the server afterward.

**What the school's cPanel account needs.**
- PHP 8.2 or newer, with the extensions Laravel 12 needs (the usual cPanel PHP Extensions screen: curl, fileinfo, mbstring, openssl, pdo_mysql, tokenizer, xml, ctype, bcmath).
- A MySQL database and a MySQL user with full privileges on it (Laravel needs `CREATE`/`ALTER`, it does not need `CREATE DATABASE` here since this install only ever has the one school, unlike the platform's own multi-tenant hosting).
- SSH/Terminal access or a "Setup Node.js/PHP App"-style Composer runner. If neither is available, `composer install` has to be run somewhere with the same PHP version and the resulting `vendor/` folder uploaded instead.
- Cron access, to run the Laravel scheduler.

**Steps.**
1. Create the MySQL database and user in cPanel, note the database name, username, and password.
2. In cPanel's domain/subdomain settings, point the school's chosen domain (or a subdomain of it) at `backend/public` as the document root, not at `backend/` itself.
3. Copy `backend/.env.example` to `backend/.env` and fill in for that school specifically:
   - `APP_ENV=production`, `APP_URL` set to the school's real domain.
   - `APP_DOMAIN` set to the same domain (this is what `Route::domain('{school}.'.$domain)` matches against, see `routes/web.php`), and the one school this install serves needs a `slug` whose subdomain resolves there, following the same `{slug}.{APP_DOMAIN}` pattern the platform itself uses.
   - `DB_CONNECTION=mysql` and the database name/username/password from step 1.
   - `SKULOS_SELF_HOSTED=true`. Without this, the license check never runs at all and the install behaves as if it were never self-hosted, App\Services\Licensing\LicenseService::checkSelfHosted() no-ops on this flag.
   - `SKULOS_LICENSE_ENDPOINT` left at its default (the real SKULOS platform's `/api/license/verify`) unless told otherwise.
   - `SKULOS_LICENSE_GRACE_DAYS` only if a different grace window than the 14-day default was agreed with the school.
4. Run `php artisan key:generate`, then `composer install --no-dev --optimize-autoloader` (or upload a pre-built `vendor/` if Composer cannot run on the host), then `php artisan migrate --force` and `php artisan db:seed --force` (seeds the RBAC catalog, same as any other install), then `php artisan storage:link` (needed for the school's own branding logo and report card stamp/signature uploads to actually render, see the note in the general cPanel section above for what to do if the host disables PHP's `symlink()`).
5. Create the school itself: sign in to this fresh install's own `/admin` (the same super admin flow any new SKULOS install has) and create the one school through the normal "Schools" screen, exactly as it would be done on the main platform, or run `php artisan school:provision "The School's Name"` from the console. Either way this school now lives entirely inside this install's own database, nothing is copied over from elsewhere.
6. Add `php artisan schedule:run` to cPanel's cron, once a minute, the same line as the general cPanel section above. This is what actually fires `license:check` on its hourly schedule, along with backups and every other scheduled job. Skipping this means the license check silently never runs, which is worse than a strict lockout, it just quietly never verifies.
7. On the **main SKULOS platform** (not this new install), open that school's record under Schools, and use the new "Self-hosted license" card to enable self-hosted licensing for it. This generates a license key.
8. Back on **this new self-hosted install**, sign in to its own `/admin`, go to Settings, Self-Hosted License, and paste in the key generated in step 7. Save.
9. Confirm it is working: `php artisan license:check` run by hand should complete without error, and the settings page should show a real "Last check-in" time and a "Verified" status once the scheduled hourly run has fired at least once.

From this point on, the school's install checks in on its own. If the subscription lapses on the platform side (the same suspend/demand actions already used for platform-hosted billing), this install's next check-in fails, a warning banner appears there with a day count, and if it is not resolved before the grace period runs out the install locks itself out with a plain explanation, until the platform side reinstates it and a check-in succeeds again.

**Updating an existing self-hosted install.** A self-hosted school runs its own separate, standalone copy of this codebase — pushing a change to this repo's `main` branch does **not** reach it automatically, the same way it never reaches any other independently deployed copy. Someone with access to that school's server has to pull and apply the new code themselves.

As of 2026-08-07 there are two ways to get the new code onto the server, **the license-gated release download is the recommended one** — it never requires giving a self-hosted customer (or a developer they hire) raw git/GitHub access to this repo at all, and a revoked/expired license stops future updates the same way it already stops runtime access:

1. On this installation's own `/admin`, go to **Settings → Self-Hosted License**. If an "Update available" banner is showing, click **Download update** — this calls the main platform's license-gated API (`App\Http\Controllers\ReleaseDownloadController`), verifies the downloaded zip's sha256 checksum against what the platform reports, and saves it to `storage/app/updates/{version}.zip` only once that checksum actually matches (a failed/tampered download is discarded, nothing is left half-saved).
2. Extract that zip over the existing `backend/` directory (back up first — see the Backups section of this install's own admin, or a plain `cp -r backend backend.bak`).
3. `composer install --no-dev --optimize-autoloader` if `composer.json`/`composer.lock` changed.
4. `php artisan migrate --force` — always run this after every update, even one that looks purely cosmetic; schema changes on the platform's own tenant migrations apply here identically, since a self-hosted install runs the exact same migration files against its own single database.
5. `php artisan config:clear && php artisan view:clear` if config or Blade views changed (a stale compiled view/config can otherwise mask that the update even happened).
6. **Set `SKULOS_VERSION` in `.env` to the version just applied** (the exact string shown on the license settings screen, e.g. `2026.08.07`) — this is what lets the install correctly stop showing "update available" for a version it already has, and is compared against `School::latest_available_version` (refreshed on every hourly license check-in) purely for that banner. Skipping this step doesn't break anything, it just leaves a stale "update available" notice showing.
7. Confirm the install still checks in cleanly (`php artisan license:check` by hand), since an update should never by itself affect license status.

**Fallback**: if a self-hosted install has direct git/shell access (e.g. this is the platform's own staging/dev environment, not a real third-party customer), the old path still works — `git pull origin main` (or re-upload a fresh zip of `backend/`, same "what to copy" caveat as the initial install above, keep their own `.env` and `storage/` untouched) instead of steps 1-2, then the same steps 3-7. There is still no fully automated apply step by design (see the plan file's own confirmed decision): a downloaded/pulled update is never extracted, migrated, or restarted automatically — an operator always does that deliberately, so a bad update can never take down a live, unattended site.

### Linking a created school to a subdomain in cPanel (planning, 2026-07-10, not yet executed)

The app already assumes each school lives at `{slug}.{APP_DOMAIN}` (`Route::domain('{school}.'.$domain)` in `routes/web.php`, `School::portalUrl()`). Locally this "just works" with no setup because `*.localhost` resolves to the dev machine automatically. In production, a real subdomain needs real DNS and (for HTTPS) a certificate that covers it, neither of which `TenantManager::provision()` touches today (it only creates the tenant database). Two ways to close that gap, in order of preference:

1. **Wildcard subdomain, recommended.** One time, at go live: in cPanel create a single subdomain `*.skulos.app` (or whatever the final domain is, see the open question below) with its document root pointed at the same `backend/public` the main site uses, add a matching wildcard DNS record (`*.skulos.app` → the server's IP, either directly in cPanel's zone editor or at the registrar), and secure it with a wildcard SSL certificate. cPanel AutoSSL supports wildcard certificates only via DNS validation, which not every shared hosting plan exposes, so this needs checking against the actual hosting plan before relying on it; putting Cloudflare in front (free plan, "Full" SSL mode) is a common, simpler way to get wildcard HTTPS covered if AutoSSL alone will not. Once this is set up **once**, every future `TenantManager::provision()` call needs no further action at all, the wildcard already resolves and serves any slug immediately. This matches how the app is already built and requires no code changes.
2. **Per school subdomain via the cPanel API, fallback only.** If the hosting plan cannot do wildcard subdomains or wildcard SSL, `TenantManager::provision()` would need to call cPanel's UAPI (`SubDomain::addsubdomain`) with an API token to create `{slug}.skulos.app` individually, then either trigger AutoSSL for it or wait for the next AutoSSL sweep. This adds an external HTTP call (and its failure modes, latency, and a token to store securely) into school creation, and does not exist in the codebase yet. Only worth building if option 1 turns out to be unavailable on the actual hosting plan.

Open questions blocking either option from being finalized (also tracked in tracker section 11): the actual production domain is still an unconfirmed placeholder (`skulos.app` used throughout docs and code comments, never explicitly confirmed as the real one), and whether the specific cPanel hosting plan supports wildcard subdomains and wildcard AutoSSL is unknown until checked against that plan directly. Nothing here blocks current development, this is a go live day setup task, not a code change, unless option 2 turns out to be necessary.

### Free test deployment before moving to cPanel
`backend/Dockerfile` builds a self-contained container (SQLite, no separate
database service to set up) so you can get a real, clickable URL live for
free before committing to the cPanel host. Render.com's free tier is the
most straightforward: no CLI needed, deploys straight from GitHub.

1. Sign in to [render.com](https://render.com) with GitHub, **New → Web
   Service**, pick this repo.
2. Runtime: **Docker**. Root directory: `backend` (so it finds the
   Dockerfile). Instance type: **Free**.
3. Add one environment variable: `APP_URL` set to whatever `.onrender.com`
   URL Render assigns (shown after the first deploy; add it and redeploy
   once so links/redirects resolve correctly).
4. Deploy. First build takes a few minutes (composer install + migrate).
   Once live, sign in with `platform:make-super-admin` run once via
   Render's shell tab, or seed one in the Dockerfile before handing this
   off for real use.

**Important limitation, not a bug**: Render's free tier has no persistent
disk, and the free instance sleeps after 15 minutes idle. Every redeploy
or wake-from-sleep gets a **fresh SQLite file** — anything created
(schools, users, data) is lost. This is fine for "let me click through
the platform and show someone," not for anything anyone should rely on
holding data. When ready for real use, move to the cPanel target above
(MySQL, persistent disk) — nothing about the app changes, only where it
runs and which database driver `.env` points at.

Fly.io is the alternative if persistent data matters for testing (it has
a genuinely free allowance that includes a small persistent volume,
unlike Render's free tier) — same Dockerfile works there, mount the
volume at `backend/database` and point `DB_DATABASE` at the SQLite file
inside it. Its free tier does require a credit card on file and its
exact terms can change, so check current details on fly.io before
committing.
