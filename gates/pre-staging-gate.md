# Pre-Staging Readiness Gate (report-only — go/no-go before deploying)

Reusable read-only go/no-go check before deploying to a staging/production server. Read the project's `CLAUDE.md`, `SETUP.md` first.

## Why this exists
Catches the "deployed, THEN found X was broken/missing" loop, which is far more painful to debug on a remote box than locally. It verifies the codebase is clean, complete, and safely configured to LEAVE local — and it verifies state against the actual code/git, NOT against claims or memory ("the audit ran" is not "the fixes ran"; work may sit on an unmerged branch). The bar to leave local is "clean, complete, safely configured" — NOT "everything is proven", because staging is FOR finding environment issues.

## Method
- `git checkout main && git pull`, state the current commit/branch. READ-ONLY — change nothing, fix nothing, install nothing. Base every claim on evidence (commit/file/config/test result).

## Produce `PRE-STAGING-CHECKLIST.md` with these sections

### 1. Repo & branch state
Current branch + latest commit; is `git status` clean? `git branch -a` — list every local/remote branch; for each unmerged one, what it contains and whether it's wanted (nothing important left behind, nothing half-done about to deploy). Any leftover `dd()`/`dump()`/`console.log`/commented-out blocks/skipped tests.

### 2. Build & test health
Run the check gate (formatter + static analysis + full suite) and report the ACTUAL result (pass/fail, test count). Run `composer audit` + `npm audit`. Confirm assets build (`npm run build`) without error.

### 3. What's actually DONE vs PENDING (verify against the code — the key section)
For each major piece of planned/expected work, report DONE / PARTIAL / NOT DONE with the EVIDENCE (commit/file/package). Verify by looking, not by trusting a report. Flag which missing items are GO-LIVE BLOCKERS (e.g. GDPR data erasure, error monitoring, the production DB, payment/webhook handling) vs nice-to-have. (This is where "an audit ran but the fixes didn't" gets caught.)

### 4. Production config readiness (so deploy doesn't fail on a missing/unsafe setting)
- Does `.env.example` document EVERY env var the app actually uses? List any var used in code/config but missing from it.
- Are local-only routes/features (dev login shortcuts, mail-preview, debug routes) correctly gated to `local` so they CANNOT be reached in production? Verify, don't assume.
- Safe production defaults: `APP_DEBUG=false` by default, no hardcoded `local` assumptions, no secrets committed.
- Anything that reads/writes the local filesystem in a way that won't work on a server (paths, storage, an SQLite file).
- Queue/cache/session drivers are production-appropriate (not `sync`/`array`/`file` left over from dev).
- **`php artisan config:cache` succeeds** (deploys usually run it). Run it: if it throws a serialization error ("non-serializable ... Closure::__set_state"), a config file contains a closure (a common one: a package's `before_send`/callback in its published config). It must be removed/relocated to runtime before deploy or the deploy fails. Then `config:clear`.

### 5. Server-side deployment checklist (what the owner must configure)
An ordered list of what must be set up on the server: runtime version, env vars, database, cache/queue store, queue worker running, the scheduler cron (flag: silent-failure if missing), storage symlink, SSL, build step, migrations on deploy (NEVER a destructive fresh/refresh on prod). Pull specifics from SETUP.md; flag anything SETUP.md is missing.

### 6. Can only be verified AFTER deploy (NOT blockers for leaving local)
Clearly separate these as expected-pending, not failures: real payment webhook delivery to the live URL, real email deliverability + DKIM/SPF on the real domain, SSL provisioning, error-monitoring receiving a real (scrubbed) event, real inbound-email round-trip, and the manual money-path verification. Point to the project's pre-launch verification checklist.

## Verdict
End with a clear **GO / NO-GO** for deploying to staging. If NO-GO, give the specific ordered list of what to run/fix first. Note: GDPR/erasure and privacy-scrubbed monitoring should be in before any real personal data is entered, even on staging.

Report-only. Write `PRE-STAGING-CHECKLIST.md`, commit just that file, change nothing else.
