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

The backend is API/console only right now (no product UI yet), so "previewing" it means running its tests and console commands.

```bash
cd backend
# use the provisioned PHP 8.3 + Composer (see CLAUDE.md for exact paths/shims)
php artisan migrate            # platform database (SQLite locally)
php artisan db:seed            # RBAC catalog: modules, permissions, role templates
php artisan school:provision "Demo Academy"   # create a school + its tenant database
php artisan test               # full test suite
php artisan serve              # serves the API at http://127.0.0.1:8000
```

Local dev uses SQLite as a stand in for MySQL. Production uses MySQL per the confirmed stack; keep schema and queries driver neutral (no SQLite only or MySQL only SQL).

### Backend on cPanel (production sketch, for later)
- PHP 8.2+ with the usual Laravel extensions, plus a MySQL user allowed to `CREATE DATABASE` (needed for per school tenant databases).
- Point the domain document root at `backend/public`.
- Set `.env` for production (APP_ENV=production, MySQL credentials, `TENANCY_DRIVER=mysql`), run `composer install --no-dev`, `php artisan migrate --force`, `php artisan db:seed --force`.
- Redis and Meilisearch are optional locally; wire them in production for queues, cache, and search.
