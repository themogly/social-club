# Pre-staging gate — Phase D gate 12 (GO / NO-GO)

**Ran against:** `main` @ `7769d47`. **Re-run this gate** after any infra change and immediately before the
first real-data deploy — the app-layer rows are code-verifiable now; the infra rows can only be confirmed on the
target host.

## Verdict: **CONDITIONAL GO**

The application is staging-ready. Nothing in the code blocks a deploy. **Two items must be arranged before a
REAL-DATA launch** (not before staging with synthetic data): automated **backups**, and running the suite on the
**production PHP/Node versions**. Everything else is a confirm-at-staging checklist.

## GREEN — verified in code (GO)

- [x] **No public/indexable surface.** `public/robots.txt` disallow-all + `X-Robots-Tag: noindex` on every response
  (`App\Http\Middleware\SecurityHeaders`); everything behind auth. (3 layers, confirmed in the security audit.)
- [x] **Migrations run clean** on a fresh database (`migrate:fresh` OK).
- [x] **Scheduler wired** (`routes/console.php`, 12 scheduled entries) — incl. recurring expenses, staging sweeps.
- [x] **Queue = Redis + Horizon** installed (`laravel/horizon ^5.48`); `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`.
- [x] **Secrets are server-only.** VAPID private key via env (`config/webpush.php`, never shipped); no secret in any
  client bundle (security audit).
- [x] **CI exercises BOTH engines.** `ci.yml` runs `composer check` (Pint·Larastan·PHPUnit SQLite) AND a `mysql`
  job (`php artisan test -c phpunit.mysql.xml`) — the prompt-141 "SQLite-only CI" concern is CLOSED.
- [x] **S3 endpoint env present.** `.env.example` carries `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT` (the
  work-order gap is CLOSED); `FILESYSTEM_DISK=local` in the example (prod overrides to s3).
- [x] **Full suite green on MySQL** (1096 passed, 4 skipped, 0 failed) at this SHA.

## CONFIRM AT STAGING — infra, cannot verify from the repo (owner)

- [ ] `APP_URL=https://…`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set; **TrustProxies** configured so
  HTTPS is honoured behind the load balancer (no `forceScheme` in code by design — the proxy terminates TLS).
- [ ] `MAIL_MAILER=resend` (example is `log`) + `RESEND_KEY` set; send a live test of one member mailable.
- [ ] **Private `documents` disk** points at the S3 bucket with credentials, encrypted-at-rest confirmed, and the
  signed-URL TTL sane — this holds the Article-9 ID scans + photos.
- [ ] Redis reachable; **Horizon running** as a supervised process; **the scheduler cron** (`* * * * * … schedule:run`)
  actually installed on the host.
- [ ] `SENTRY_LARAVEL_DSN` set (the package is installed; there is no `config/sentry.php`, which is fine — it
  auto-configures from the DSN). Confirm errors arrive.
- [ ] VAPID keys generated + set (`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`); web-push delivers on the PWA.
- [ ] Config + route + event caches built on deploy, and **all caches busted on deploy** (settings degrade
  gracefully but stale reference data should not linger — CLAUDE.md rule).

## NO-GO until arranged — before REAL member data (owner)

- [ ] **Automated, tested backups.** No `spatie/laravel-backup` or backup command exists in the repo — the DB AND
  the encrypted `documents` disk must have scheduled backups WITH a rehearsed restore. For a system holding Article-9
  special-category health data, an untested backup is a NO-GO. (Infra-level; not a code defect, but it gates launch.)
- [ ] **Run the suite on the production runtime.** CI is PHP **8.3** / Node **20**; prod target is PHP **8.5** /
  Node **24**. CI does not exercise the prod runtime, so a version-specific break could ship green. Either bump CI to
  8.5/24 or run `composer check` + `php artisan test -c phpunit.mysql.xml` once on 8.5 before go-live.

## How to re-run
`git checkout main && git pull`, then re-verify the GREEN rows (`php artisan migrate:fresh` on a scratch DB;
`php artisan test -c phpunit.mysql.xml`), and tick the infra rows against the actual staging host.
