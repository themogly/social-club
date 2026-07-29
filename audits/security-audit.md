# Security & Privacy Audit (report-first, then fix in phases)

Reusable security + privacy audit. Treat security as first-class for any app handling user accounts, personal/sensitive data, or payments. Read the project's `CLAUDE.md`, `DECISIONS.md`, `SETUP.md`, and the `web-app-security` skill first.

## Method
- `git checkout main && git pull`, branch `security/audit-pass`. State the starting commit.
- Inventory `app/`, `routes/`, `config/`, `database/`, the admin panel, the account area, and dependencies before proposing fixes. Identify what sensitive data the app collects/stores (PII, special-category/medical, financial).
- Do not weaken any documented security decision; route conflicts to a `## Discussion` section.

## Step 1 — Report FIRST
Write `audits/reports/security-audit.md`, `- [area]: [finding] → [fix] → [why it matters]`, PHASE 1 / 2 / 3, each ending `Review:`. Distinguish real findings from OWNER/OPS tasks (SSL, WAF, legal copy) in a separate section. Commit before fixing.

### PHASE 1 — Must-fix (exploitable / data-exposure)
Authorization scoping / IDOR (every user-scoped route+query loads only the owner's data; changing an id in the URL must 404/deny — add tests proving cross-user denial); token security (signed/expiring/single-use, unguessable, hash-compared); guard separation (users can't reach admin — tested); secrets hygiene (`.env` untracked, `.env.example` placeholders only, no committed keys, every used var documented); dependency vulns (`composer audit` + `npm audit`); webhooks verify signatures + are idempotent; rate limiting on auth + public forms (and public forms spam-hardened — honeypot + min-submit-time, not just throttled); CSRF on state-changing routes; no mass-assignment of sensitive fields (no `$guarded=[]`); no prohibited storage (no card/PAN, no rolled-own password crypto); PII never in URLs/logs/over-exposed responses.

### PHASE 2 — Privacy & GDPR
Privacy policy page (legal copy is an owner task); consent + timestamps where personal/marketing data is collected; documented data-retention + a subject-access EXPORT and ERASURE path (erasure = anonymise-in-place preserving legally-required financial records, not blind hard-delete); special-category/medical data access limited and not over-surfaced.

### PHASE 3 — Hardening & monitoring
Security headers (nosniff, frame protection, Referrer-Policy, HSTS in prod, a CSP — start report-only so it doesn't break scripts); HTTPS enforced + secure/httponly/samesite cookies + `APP_DEBUG=false` in prod; error monitoring (e.g. Sentry) configured for PRIVACY — `send_default_pii=false` + a scrubber stripping sensitive fields and payment/account request bodies, verified that a captured event is actually scrubbed; note queue worker + scheduler as monitored must-be-running services.

## Step 2 — Fix in phase order
Phase 1 first (gate between phases). Add tests for access-control fixes especially (cross-user denial, guard separation, token expiry/single-use). One commit per item, check gate green, never commit red. Don't change payment/booking behaviour except as security fixes proven by tests.

## Finish
Update the report. Confirm `composer audit`/`npm audit` status, any monitoring captures a scrubbed event, access-control tests pass. Full suite green, DECISIONS.md + SETUP.md updated, push the branch, do not merge. Summarise owner/ops tasks (SSL, secure-cookie, debug-off, privacy-policy legal copy, monitoring DSN, WAF).
