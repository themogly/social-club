# SETUP

Local development, testing, and deployment for the CSC platform.

## Requirements

- **PHP 8.5** — the version the project is PROVEN on and the one CI + production run (production **8.5.9**,
  dev **8.5.6**; the `composer.json` floor stays `^8.3`, the minimum a dev may install). Extensions: `gd`
  (WebP/PNG/FreeType), `bcmath`, `intl`, `zip`, `mbstring`, `pdo_sqlite` (dev), `pdo_mysql` (prod/CI). No
  `imagick` needed (QR/PDF/image paths use gd/pure-PHP).
- **Composer 2**, **Node 20+ / npm**, **Redis** (queue + cache, via Horizon), **MySQL 8.4** (production
  **8.4.10**; CI runs the parity suite against `mysql:8.4`; local dev may use any MySQL 8.x/9.x). SQLite is
  used for local dev only. See prompt 141 — CI runs the production PHP + MySQL versions on purpose.
- No headless Chromium required — PDFs render with dompdf (pure-PHP).

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build            # bundles Tailwind + self-hosted Inter (woff2)
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

Then open **http://localhost:8000/** — the Filament admin panel is mounted at `/` (there is no
public site) and redirects to `/login`.

> **Local http:// footgun:** `SESSION_SECURE_COOKIE` MUST be `false` (or unset) for local `http://`
> development. If it is `true`, the session cookie is never sent and login **silently fails** — you
> are bounced back to the form with no error. `.env.example` sets it `true` (the production default);
> the local `.env` leaves it unset. Likewise keep `APP_URL` matching the scheme in use. **Run
> `php artisan config:clear` after any `.env` change.**

### Seeded dev credentials (local only — pinned, same every rebuild)

Created by `DevAdminSeeder`, guarded behind `app()->environment('local')` and never run in production.

| Role    | Email               | Password   | POS PIN* |
|---------|---------------------|------------|----------|
| Owner   | `owner@club.test`   | `password` | 1234     |
| Manager | `manager@club.test` | `password` | 2345     |
| Staff   | `staff@club.test`   | `password` | 3456     |

All seeded email-verified so they pass the panel gate. *POS PINs are attached in prompt 02 once the
staff/role columns exist; the base seeder creates the three login accounts.

### Background services (local)

```bash
php artisan horizon          # Redis queue workers + dashboard at /horizon
```

The `/horizon` dashboard is open in `local`; in other environments it is gated to authenticated
staff (`viewHorizon` gate in `app/Providers/HorizonServiceProvider.php`).

### Mail preview

Local mail uses the `log` mailer. Preview every registered mailable at **`/dev/mail`** (local only —
404s elsewhere). Add each new mailable to `App\Support\DevMail::previews()` so it appears here and in
`MailRenderTest`.

## Testing & quality gate

```bash
composer check                       # Pint (style) -> Larastan L6 -> full test suite. Green before every commit.
php artisan test                     # suite only, SQLite in-memory (fast)
php artisan test -c phpunit.mysql.xml # driver-parity run on MySQL (needs DB `csc_platform_test`)
```

Production is MySQL, so CI runs the suite on MySQL too (SQLite-only testing hides JSON/boolean/
strict-type/string-length bugs). Create the CI DB: `CREATE DATABASE csc_platform_test;` and adjust
credentials in `phpunit.mysql.xml`.

**Visual checks (Playwright MCP):** layout-affecting UI changes are screenshotted at
1440 / 1280 / 1024 / 390 and a short laptop height, light AND dark, motion reduced AND allowed. Add
the MCP at runtime: `claude mcp add playwright npx @playwright/mcp@latest`.

## Environment variables

Everything used in code/config appears in `.env.example`. Highlights:

- **DB:** `DB_CONNECTION=sqlite` locally; production sets the MySQL block (`DB_CONNECTION=mysql`, host,
  db, user, password).
- **Redis:** `REDIS_CLIENT=predis` (pure-PHP, no extension). `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
  - **Redis resilience (prompt 124).** `PERMISSION_CACHE_STORE=database` keeps the authorization cache OFF
    Redis, so a Redis blip does NOT 500 every authenticated screen — the counter keeps trading and the register
    keeps recording (sessions are already `database`). During an outage: authenticated pages render; the queue
    (Horizon) stops until Redis returns and nothing dispatched is lost; the login form and any explicit
    cache/queue call show a stated "infrastructure degraded" message instead of a blank bounce; **Salud del
    sistema** renders and reports the cache as *No accesible*. Recovery is automatic — Redis returning restores
    everything with no restart. `database` (not `file`/`array`) so a role edit + `php artisan
    permission:cache-reset` still propagates across workers; use `file` only on a single-server box.
- **Mail:** local `MAIL_MAILER=log`. Production uses **Resend** via Laravel's first-party transport — the
  `resend/resend-php` package is already required (do **not** add `resend/resend-laravel`); set
  `MAIL_MAILER=resend` and `RESEND_API_KEY` (Laravel's own convention — `config/services.php` reads it), and a
  verified `MAIL_FROM_ADDRESS`.
- **Storage:** `FILESYSTEM_DISK` for general uploads. **ID documents & member photos use the separate
  private `documents` disk** — `DOCUMENTS_DRIVER=local` in dev; production sets `s3` with a dedicated
  private `AWS_DOCUMENTS_BUCKET`. Encrypted at rest, signed-URL access only, access-logged (prompt 04).
- **Sentry:** `SENTRY_LARAVEL_DSN` (inert when empty), `SENTRY_TRACES_SAMPLE_RATE`. `config/sentry.php`
  sets the privacy options **deliberately** — `max_request_body_size => 'none'`, `send_default_pii => false`,
  no SQL bindings in breadcrumbs, and a `before_send` scrubber. Do not remove that file: the library
  defaults capture the whole POST body (the raw MRZ, the member application payload, the counter PIN, the
  staff password), and body capture is **not** gated on `send_default_pii`. The scrubber is registered as a
  callable array rather than a closure so `config:cache` still works — a closure there makes step 6 of the
  deploy fail, or silently drops the protection.
- **Security:** `APP_DEBUG=false` by default (flip on for local dev), `SESSION_SECURE_COOKIE=true` in prod.

## Deploy sequence (order matters — wrong order causes silent bugs)

1. `git pull`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`  — **never** `migrate:fresh`/`migrate:refresh` in production (data loss)
5. `php artisan storage:link`
6. Clear + rebuild caches: `php artisan config:clear && php artisan cache:clear` then
   `config:cache route:cache view:cache` (a stale typed cache silently kills queued mail)
7. **Restart Horizon LAST:** `php artisan horizon:terminate` (workers must pick up the new code)

Also required in production: automated **daily DB backups with a tested restore**; a monitored
`schedule:run` cron once anything is scheduled; Horizon and the cron as monitored must-be-running
services; Sentry wired. See `verification/CHECKLIST.md` (gates launch) and `gates/pre-staging-gate.md`.

### Scheduled jobs — the nightly expiry sweep depends on this

The membership expiry sweep (and every other scheduled job) runs **only if `schedule:run` fires every
minute**. On the server, add ONE cron entry (the code being perfect does not matter if this is missing):

```cron
* * * * * cd /var/www/csc-platform && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled commands (`routes/console.php`):

| Command | When | What |
|---|---|---|
| `memberships:sweep` | daily 05:00 | flip lapsed / expiring-soon memberships; send renewal reminders |
| `members:purge` | daily 04:00 | anonymise members past the retention window |
| `checkins:auto-checkout` | daily 06:00 | close forgotten check-ins |
| `expenses:materialise-recurring` | daily 05:30 | post recurring overheads |
| `system:heartbeat` | every 5 min | stamp the scheduler-liveness the health panel reads |

**Locally / during testing** the cron is NOT running, so "nothing happened overnight" is expected and
does not mean the feature is broken. To exercise it during development:

```bash
php artisan schedule:work        # run the scheduler in the foreground (leave it running), OR
php artisan memberships:sweep    # run the expiry sweep once, right now
```

**How you know it actually ran:** `memberships:sweep` stamps its own heartbeat, so **Sistema ▸ Salud
del sistema** shows the sweep's last-run time and turns **red if it has not run in ~26 h — even when
the generic scheduler heartbeat is green.** A silently-broken sweep is therefore visible, not silent.

## The in-browser MRZ reader (prompt 179)

`npm run build` copies the reader's runtime out of `node_modules` into `public/ocr/` (see
`scripts/vendor-ocr.mjs`). It is **not** committed — ~10 MB, and `npm ci && npm run build` is already the
deploy sequence — and `public/ocr` is gitignored exactly as `public/build` is.

There is **no server-side OCR dependency**: no `tesseract` binary, no cloud API. If `public/ocr` is missing,
the application form simply offers no scan control and the applicant types their details, which is the same
path a browser that cannot run WASM takes. So a deploy that skips the build degrades rather than breaks —
but it does silently turn the feature off, which is worth knowing when someone asks why nobody is scanning.
