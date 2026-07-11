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
- **Every deploy that adds or changes a file under `database/migrations/tenant/`, also run `php artisan tenants:migrate` right after `php artisan migrate --force`.** The platform migration only updates the shared platform database; each school's own database only gets tenant migrations applied at the moment it is provisioned, an existing school never receives a later one on its own (this caused a real live 500 once, see CLAUDE.md). Run it locally too before every deploy that touches tenant migrations, not just in production.
- Redis and Meilisearch are optional locally; wire them in production for queues, cache, and search.
- Add `php artisan schedule:run` to cPanel's cron every minute (`* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1`), or nothing scheduled (backups, etc) will ever fire. Confirm with `php artisan schedule:list` after setup.

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
