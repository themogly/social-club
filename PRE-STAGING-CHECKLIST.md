# Pre-staging readiness gate — Phase D gate 11b

**Ran against:** `main` @ `3e92c67`, working tree clean. **Read-only** — nothing was changed, fixed or
installed to produce this. Every claim below is evidence from the code, the git history or an actual command
run, not from a report or from memory.

## Verdict: **GO for staging**, with one must-fix that is not a blocker

The codebase is clean, complete and safely configured to leave local. One intermittent test failure was
**observed and reproduced once** during this gate and must be characterised before it erodes trust in CI —
it is a test-suite defect, not a product defect, and it does not block staging. Details in §2.

---

## 1. Repo & branch state

- **Branch** `main`, latest commit `3e92c67` *"Merge the code-style audit: one till resolver, one submission
  Action"*. `git status` **clean**.
- **Every branch is merged.** 24 local and 17 remote branches, and `git log main..<branch>` is empty for all
  of them. Nothing is left behind on an unmerged branch and nothing half-done is about to deploy. Notably
  `feat/one-member-lookup` and `admin/audit-pass`, both parked mid-work at the start of this round, are
  finished and merged.
- **Leftover debug artefacts: none.** 0 `dd(`, 0 `dump(`, 0 `ray(`, 0 `TODO`/`FIXME`/`HACK`/`XXX`,
  0 `href="#"`. (Full inventory in `COMPLETENESS-CHECK.md`.)
- **Skipped tests: 3, all self-explaining and correct.** `PanelThemeSourceTest` and `FilamentThemeTest` skip
  when `public/build` is absent (CI builds first); `IntegrityHarnessTest` skips without a seeded dev
  database; `EmailNormalisationTest` skips a case-collision that cannot exist under a case-insensitive
  collation. None hides a failure.

## 2. Build & test health

| check | result |
|---|---|
| `vendor/bin/pint --test` | **pass** |
| `vendor/bin/phpstan` (Larastan level 6) | **pass, 0 errors** |
| `php artisan test` | **pass — 1,497 tests, 12,446 assertions, 3 skipped** |
| `composer audit` | **No security vulnerability advisories found** |
| `npm audit --omit=dev` | **0 vulnerabilities** |
| `npm run build` | **pass** — Vite build clean |
| `php artisan config:cache` | **succeeds** (then cleared) — no closure-in-config serialisation error |

### ⚠ One intermittent test failure, observed here

`Tests\Feature\Members\TemporaryMemberTest::test_enrolling_a_temporary_member_computes_the_expiry_from_the_window`
failed once during this gate:

```
tests/Feature/Members/TemporaryMemberTest.php:73
Failed asserting that false is true.
temporary_expires_at must equal joined_at + 30 days (temporary_window_days).
```

**Reproduction rate: 1 failure in 4 full-suite runs.** Three consecutive full-suite re-runs afterwards were
clean (1,497 passed, 0 failed), as were three isolated runs of the file (12 passed each). So it is real but
infrequent, and it does **not** appear in `composer check` runs, which is why the round's gates all read
green.

**Not diagnosed here, deliberately — this gate is read-only.** Two candidate mechanisms, and I did not
determine which:

1. A **test-timing artefact**. The test calls `freezeTime()`, but the assertion compares the *stored*
   `joined_at` against the *stored* `temporary_expires_at`, so anything that re-stamps `joined_at` after
   `CreateMember::mutateFormDataBeforeCreate()` computes the expiry (`Carbon::parse($data['joined_at'])
   ->addDays($window)`, `app/Filament/Resources/Members/Pages/CreateMember.php:87`) would drift the two by
   under a second and fail an exact `equalTo`.
2. A **real one-second base drift** in the same place, which would be a product defect — a temporary member
   occasionally expiring a second early is harmless, but the same base-mismatch on a different field would
   not be.

**Recommendation:** its own short branch. Pin it by running the file 50× under a randomised order, then
either freeze the base explicitly or assert to the second. Not a staging blocker: the sibling test
(`test_changing_the_temporary_window_changes_the_computed_expiry`) exercises the same code path and has never
failed, and the behaviour asserted is a display/retention timing, not money, stock or a compliance gate.

## 3. What is actually DONE vs PENDING — verified against the code

| area | state | evidence |
|---|---|---|
| Member register, applications, avalador, ID capture, RGPD consent | **DONE** | `MemberResource` + 11 relation managers; `SubmitApplication`; `RecordMemberConsent`; versioned consent text + locale captured at submit |
| Memberships, fees, wallet, carencia | **DONE** | `RecordFeePayment`, `CollectsMembershipFees`, `Wallet`, `WaiveCarencia` |
| Consumption limits, enforced not warned | **DONE** | `CommitDispensation` is the transactional boundary; `ResolveMemberLimits`; override permissioned + reasoned + logged |
| Genetics, batches, stock in centigrams, FEFO | **DONE** | one writer `RecordStockMovement`; `SelectBatch` |
| Pricing + discounts | **DONE** | one resolver `ResolvePrice`, frozen into the line snapshot at commit |
| Check-in / aforo | **DONE** | `CheckInScreen`, `CheckInMember`, `ResolveMemberEligibility` |
| Till sessions, blind arqueo, Z-report | **DONE** | `OpenTill`/`CloseTill`/`RecordCashMovement`; expected cash derived from the ledger, never stored |
| Dispensary POS + bar POS + combined settle | **DONE** | `DispensaryPos`, `BarPos`, `CommitCombinedSettle`; voids reverse stock + wallet |
| Expenses, purchases, suppliers | **DONE** | `RecordTillExpense` / `RecordOverhead` / `ApproveExpense` / `RecordPurchase` |
| Dashboard + 9 reports + exports | **DONE** | `App\ViewModels\Reports\*`; CSV + PDF |
| Member PWA (second guard, magic link, offline QR) | **DONE** | `member` guard, `IssueMemberLoginLink`, `public/sw.js` |
| Legal books — libro de socios, actas, convocatorias | **DONE** | `libro-socios`, `Minute` (immutable once signed), `Convocatoria` |
| Audit log (append-only), RGPD tooling, breach log, RoPA | **DONE** | `RecordAuditLog`; `DataRequestResource` → `AnonymiseMember`; `/rat`; `/breach-logs` |
| **GDPR erasure** — go-live blocker if absent | **DONE** | `AnonymiseMember` behind `data.erase`, and since the admin audit it is **reachable from the member record** (`requestErasure`), not only from another screen |
| **Error monitoring** — go-live blocker if absent | **DONE, needs a DSN** | Sentry wired with request bodies deliberately scrubbed; `SENTRY_LARAVEL_DSN` empty in `.env.example` by design |
| **Production DB** — go-live blocker if absent | **DOCUMENTED, needs provisioning** | MySQL block present in `.env.example`; CI runs `phpunit.mysql.xml` |
| Multi-organisation SaaS | **NOT DONE, deliberately** | NOTES §D — schema is org-keyed; "allow > 1 org", not a refactor |
| Cultivation / seed-to-sale, card payments, hardware | **NOT DONE, deliberately** | NOTES §D; the wallet ledger is built so a payment layer sits on top |

**No case of "the audit ran but the fixes didn't".** All four audits this round are merged with their fixes:
accessibility (`f682835`), admin (`9cd260b`), design (`6a7759f`), code-style (`3e92c67`). Two items are
open **by decision, with the reasoning recorded** — the counter overlay focus trap (a11y Phase 3) and naming
the drawer for a cash fee at the door/Socios (code-style). Neither is a stub; both have a written home.

## 4. Production config readiness

- **`.env.example` documents everything this app actually needs.** A scan found 154 `env()` keys in code and
  config against 60 documented, but the difference is Laravel's and the packages' own config defaults —
  Beanstalkd, SQS, DynamoDB, Memcached, Papertrail, Slack, Postmark and similar, none of which this
  deployment uses and all of which have working fallbacks. Every var this app genuinely needs is present
  **including the commented MySQL block** (`DB_HOST`/`PORT`/`DATABASE`/`USERNAME`/`PASSWORD`), Redis, Resend,
  both S3 disks, VAPID, Sentry and the documents-disk encryption settings.
- **Local-only routes are gated and asserted.** `routes/dev.php` is wrapped in `EnsureLocalEnvironment`;
  `DevRoutesTest` asserts `/dev/mail/*` 404s outside `local`. There is no dev login shortcut and no debug
  route.
- **Safe production defaults.** `config/app.php` → `'debug' => (bool) env('APP_DEBUG', false)`, and
  `.env.example` ships `APP_DEBUG=false`. No secrets are committed (`APP_KEY=`, `RESEND_API_KEY=`,
  `VAPID_*=`, `SENTRY_LARAVEL_DSN=` are all empty templates).
- **Drivers are production-appropriate in the template**: `SESSION_DRIVER=database`,
  `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`. Nothing is left on `sync`/`array`.
  `SESSION_SECURE_COOKIE=true` is correct for HTTPS and is called out in `SETUP.md` as needing to be false
  for local `http://`.
- **The permission cache is deliberately off Redis** (`PERMISSION_CACHE_STORE=database`) so a Redis outage
  cannot 500 every authenticated screen, the counter included. Documented decision, not drift.
- **Filesystem.** `DB_CONNECTION=sqlite` is the local default and must be `mysql` on the server — the file
  says so in capitals. `FILESYSTEM_DISK=local` is inert here; the two disks that matter are `public`
  (general uploads) and `documents` (private, encrypted, Article 9), which is `DOCUMENTS_DRIVER`.
- **`php artisan config:cache` succeeds.** No closure survives into a config file, so the deploy step will
  not fail on serialisation.

## 5. Server-side deployment checklist (what the owner must configure)

Ordered. Specifics from `SETUP.md`; anything it does not state is flagged.

1. **Runtime** — PHP 8.4+ (the app runs 8.5 locally), Node for the build step only.
2. **Database** — provision MySQL, set the `DB_*` block, and confirm a **utf8mb4** database/collation.
3. **Redis** — for cache and queue; `REDIS_CLIENT=predis` needs no C extension.
4. **Env** — `APP_KEY` generated, `APP_URL` on the real domain, `APP_ENV=production`, `APP_DEBUG=false`,
   `SESSION_SECURE_COOKIE=true`, `APP_LOCALE` (ships `es`; the system default is `en` — decide deliberately).
5. **Mail** — `MAIL_MAILER=resend` + `RESEND_API_KEY`, and a `MAIL_FROM_ADDRESS` on the club's own domain.
6. **Object storage** — the general `public` disk AND the separate private `documents` disk. **The documents
   bucket must not be public-read**, and `AWS_DOCUMENTS_SSE` should be set (KMS for Article 9 material).
7. **Web Push** — `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`; the private key is server-only and must never
   reach a client bundle.
8. **Sentry** — `SENTRY_LARAVEL_DSN`. Request bodies are already scrubbed in config; do not undo that.
9. **Queue worker** — Horizon must be running, supervised. Mail, push and receipts are queued: without it
   they fail silently.
10. **Scheduler cron** — `* * * * * php artisan schedule:run`. ⚠ **Silent failure if missing**: recurring
    expenses, retention pruning, membership expiry, auto-checkout and the lockdown auto-reactivation all
    stop with no error anywhere. `/salud-del-sistema` shows the scheduler heartbeat age precisely for this.
11. **`php artisan storage:link`**, then `npm ci && npm run build`, then `php artisan migrate --force`.
    **Never `migrate:fresh` or `migrate:refresh` on production.**
12. **Cache warm** — `config:cache`, `route:cache`, `view:cache`, and `permission:cache-reset` after any role
    change. Bust all caches on every deploy (a stale Settings value must degrade, never throw — it does).
13. **SSL** on the real domain; `HSTS_MAX_AGE` defaults to one year in `config/security.php`.
14. **Do NOT run `DemoDataSeeder` in production.** Seed only `RolePermissionSeeder` and the real
    organisation/locations.

## 6. Can only be verified AFTER deploy — expected pending, not failures

- Real email deliverability through Resend on the club's domain, with DKIM/SPF.
- SSL provisioning and the HSTS header actually present on responses.
- Sentry receiving a real, scrubbed event.
- Web Push actually delivering to an installed PWA on a real phone (needs HTTPS).
- Horizon processing a real queued job, and the scheduler heartbeat going green on `/salud-del-sistema`.
- The S3 `documents` disk signing short-lived URLs — local disks cannot sign, so this path only proves out
  on a real bucket.
- MySQL-specific behaviour (JSON columns, strict mode, string lengths, booleans) — CI covers it with
  `phpunit.mysql.xml`, but the first real migration on the server is the proof.
- **The manual money-path walk** in `prompts/SETUP-step-by-step.md` §5: an application through to a QR in a
  real inbox, a 3.50 g dispensation at €10/g reading €35,00 with stock down 350 cg, a blind close with the
  variance revealed only after submitting, a void returning stock to the originating batch.

---

## If anything is fixed before staging, do it in this order

1. Characterise `TemporaryMemberTest`'s intermittent failure (§2) — the only thing on this page that is
   neither done nor deliberately deferred.
2. Provision MySQL and confirm the first `migrate --force` against a real utf8mb4 database.
3. Set `SENTRY_LARAVEL_DSN` before any real personal data is entered, on staging included — this product
   holds Article 9 material and an unmonitored error is an unnoticed one.
