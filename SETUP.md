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
  - **Sign-in limits off Redis too (prompt 344).** `CACHE_LIMITER=database` (the default) puts Laravel's
    `RateLimiter` — the panel login's throttle, every `throttle:` route, the scan/invite/PIN-test limits — and the
    counter PIN pad's attempt tally and the after-PIN password confirmation on the `cache` table. Before it, a Redis
    outage bounced every panel login back to the form with no message and left the PIN pad unable to count (a correct
    PIN worked, a wrong one never locked out). Same reasoning as the permission cache: survives the outage, shared
    across workers. During an outage the login and PINs keep working; only the queue waits.
- **`APP_KEY` — generate once, never rotate.** It encrypts sessions, cookies and encrypted columns, and (prompt 286)
  it keys every counter PIN's lookup: rotating it invalidates **every PIN**, and each person would need a new one set
  in the panel. After the first busy evening, `php artisan csc:pin-upgrade-status` shows who is still on the old,
  slower PIN hash (they upgrade by entering their PIN once).
- **Mail:** local `MAIL_MAILER=log`. Production uses **Resend** via Laravel's first-party transport — the
  `resend/resend-php` package is already required (do **not** add `resend/resend-laravel`); set
  `MAIL_MAILER=resend` and `RESEND_API_KEY` (Laravel's own convention — `config/services.php` reads it), and a
  verified `MAIL_FROM_ADDRESS`. To prove delivery end to end after a deploy (or when *Salud del sistema* shows Correo
  amber or red), run `php artisan csc:mail-test you@example.com`: it sends one real test email through the configured
  mailer and reports whether it was accepted.
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
- **Owner alerts by Telegram (prompt 311):** `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`.
  All empty = Telegram is off (the option is hidden on the profile); the morning email summary still goes. To switch
  it on:
  1. In Telegram, talk to **@BotFather** → `/newbot` → copy the **token** and the bot's **username**.
  2. Set the three values in `.env` (the secret is any long random string, e.g. `openssl rand -hex 32`), then
     `php artisan config:cache`.
  3. `php artisan telegram:set-webhook` — registers `https://<your domain>/telegram/webhook` with the secret.
  4. Each person: *Perfil → Avisos → Conectar Telegram*, scan the code with the phone, tap *Start*. The bot answers
     "Conectado. Te avisaré aquí."
  5. Check: put a test product at its low-stock threshold and wait up to 15 minutes; ONE message arrives.
  Why not WhatsApp: see DECISIONS (prompt 311) — Meta's business policy prohibits it for this use.

## Deploy sequence (order matters — wrong order causes silent bugs)

1. `git pull`
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build`
4. `php artisan migrate --force`  — **never** `migrate:fresh`/`migrate:refresh` in production (data loss)
5. **`php artisan csc:sync-permissions`** — converge the roles on `App\Support\Permissions` (prompt 214).
   Idempotent, and **required on every release**, not only when you think you changed a permission.
   `RolePermissionSeeder` used to be called only by `csc:install`, which runs once, so a club kept its
   install-day matrix for ever: a permission added to a role never arrived (the reported symptom was an
   OWNER told *"ask a manager"*), and one **removed** from a role was never revoked — the more serious
   direction, because `Permissions::for()` is what everyone reads as the source of truth for who may do what.
   It fails silently either way. Run `csc:sync-permissions --check` to see the drift without writing.
6. `php artisan storage:link`
7. Clear + rebuild caches: `php artisan config:clear && php artisan cache:clear` then
   `config:cache route:cache view:cache` (a stale typed cache silently kills queued mail)
8. **Restart Horizon LAST:** `php artisan horizon:terminate` (workers must pick up the new code)

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
| `alerts:evaluate` | every 15 min | owner alerts: announce what newly crossed the line (Telegram), send due morning emails |

**Locally / during testing** the cron is NOT running, so "nothing happened overnight" is expected and
does not mean the feature is broken. To exercise it during development:

```bash
php artisan schedule:work        # run the scheduler in the foreground (leave it running), OR
php artisan memberships:sweep    # run the expiry sweep once, right now
```

**How you know it actually ran:** `memberships:sweep` stamps its own heartbeat, so **Sistema ▸ Salud
del sistema** shows the sweep's last-run time and turns **red if it has not run in ~26 h — even when
the generic scheduler heartbeat is green.** A silently-broken sweep is therefore visible, not silent.

## Reinicio antes del lanzamiento (pre-launch reset, prompt 304)

A live site that has only ever held **test data** gets a clean start ONCE, before the first real member:

```bash
php artisan csc:reset-for-launch --purge-documents   # interactive: type the association's name, then confirm
# … set the real club up (the command runs csc:install for you unless --no-install) …
php artisan csc:launch                               # the day the first real member is served
```

What it does, stopping at the first failure:
1. Refuses if the club is **launched** (no flag overrides that), if a lockdown is active, or unless you type the
   association's exact name AND answer yes.
2. `down`, then a **gzipped, 0600 dump of the whole database** to `storage/app/backups/pre-launch-reset-….sql.gz`
   (mysqldump on MySQL). No dump, no wipe — the site comes back up. The dump holds all the test data in plain form:
   **delete it once you are sure.**
3. `migrate:fresh`; this app's Redis keys only (by `REDIS_PREFIX` and Horizon's prefix — never FLUSHALL);
   `storage/app/public`, `storage/app/member-imports` and, with `DOCUMENTS_DRIVER=local`, the documents root (the
   directories and their `.gitignore` are kept).
4. Remote documents (`DOCUMENTS_DRIVER=s3`, the R2 bucket): deleted only with `--purge-documents` and their own
   confirmation, never the bucket itself; without the flag it prints how many objects are left.
5. `csc:install` + `csc:sync-permissions`, one `system.reset_for_launch` audit entry (counts only), `up`, and the
   next steps: `config:cache`, `horizon:terminate`, re-register the tablets, set up sedes / store / staff PINs /
   catalogue, import members, `csc:launch`, delete the dump.

It never touches `.env` or `APP_KEY`, anything outside `storage/app`, or the bucket's settings.

**`csc:launch`** is the one-way latch: it stamps the organisation as live, *Salud del sistema* then reads *En marcha
desde…*, and from then on `csc:reset-for-launch` **and** `csc:install --force` refuse — for good.

## Memberships at every sede (prompt 348) — a one-off, deliberate backfill

*Ajustes → Membresía → Alta en todas las sedes* is **on** by default: from now on a new member is enrolled at every active
sede except the store. The fee is charged once, at the sede of the sign-up; the other memberships are linked to it, carry
no fee, and are renewed, expired and cancelled with it.

**Members enrolled before this change are not touched automatically.** Run once, deliberately, after deploying:

```bash
php artisan csc:extend-memberships-to --all-sedes
```

It links each active membership across to every other active sede where that member has none (no fee), audits every row,
and prints the count. **A sede opened later:** `php artisan csc:extend-memberships-to "Nombre de la sede"`.

## The in-browser MRZ reader (prompt 179)

`npm run build` copies the reader's runtime out of `node_modules` into `public/ocr/` (see
`scripts/vendor-ocr.mjs`). It is **not** committed — ~10 MB, and `npm ci && npm run build` is already the
deploy sequence — and `public/ocr` is gitignored exactly as `public/build` is.

There is **no server-side OCR dependency**: no `tesseract` binary, no cloud API. If `public/ocr` is missing,
the application form simply offers no scan control and the applicant types their details, which is the same
path a browser that cannot run WASM takes. So a deploy that skips the build degrades rather than breaks —
but it does silently turn the feature off, which is worth knowing when someone asks why nobody is scanning.
