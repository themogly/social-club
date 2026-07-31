# SETUP

Local development, testing, and deployment for the CSC platform.

## Requirements

- **PHP 8.3+** with extensions: `gd` (WebP/PNG/FreeType), `bcmath`, `intl`, `zip`, `mbstring`,
  `pdo_sqlite` (dev), `pdo_mysql` (prod/CI). No `imagick` needed (QR/PDF/image paths use gd/pure-PHP).
- **Composer 2**, **Node 20+ / npm**, **Redis** (queue + cache, via Horizon), **MySQL 8+** (production
  and CI). SQLite is used for local dev only.
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
- **Mail:** local `MAIL_MAILER=log`. Production uses **Resend** — `composer require resend/resend-laravel`,
  set `MAIL_MAILER=resend` and `RESEND_KEY`, and a verified `MAIL_FROM_ADDRESS`.
- **Storage:** `FILESYSTEM_DISK` for general uploads. **ID documents & member photos use the separate
  private `documents` disk** — `DOCUMENTS_DRIVER=local` in dev; production sets `s3` with a dedicated
  private `AWS_DOCUMENTS_BUCKET`. Encrypted at rest, signed-URL access only, access-logged (prompt 04).
- **Sentry:** `SENTRY_LARAVEL_DSN` (inert when empty), `SENTRY_TRACES_SAMPLE_RATE`.
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
