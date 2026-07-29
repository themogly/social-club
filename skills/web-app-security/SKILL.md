---
name: web-app-security
description: >
  Build-time security and privacy principles for web applications, so security
  is written in from the start rather than retrofitted by an audit. Use this
  whenever building or reviewing anything that touches authentication, user
  accounts, authorization, personal or sensitive data (PII, medical, financial),
  payments, webhooks, file uploads, forms, sessions, secrets/config, or
  third-party integrations. Covers access control / IDOR, authn vs authz,
  secrets handling, input/output safety, PII & privacy (GDPR), payments,
  webhooks, security headers, and error monitoring done privately. Principle-led
  and framework-agnostic (examples lean Laravel); a project's CLAUDE.md and a
  dedicated security audit complement this — this shapes code as it's written,
  the audit verifies it afterward.
---

# Web app security — build it in, don't audit it in

Security written in from the start is far cheaper and safer than security caught
later. A dedicated security audit still matters (it verifies), but the goal is
that the audit finds little because the code was built right. The highest-impact
failures in typical web apps are **broken access control** (one user reaching
another's data) and **leaking sensitive data** — focus there first. Adjust to the
project; a project's CLAUDE.md holds concrete rules and wins where it differs.

## Access control (the #1 risk — get this right first)

- **Scope every query to the authenticated user.** Anything tied to a user
  (orders, bookings, messages, files, invoices) must be loaded *through* that
  user / scoped by ownership — never by a raw id from the URL. Changing an id in
  the URL must NOT return someone else's record. This is IDOR and it's the most
  common serious bug.
- **Authorize on the server, every time.** Never rely on a hidden UI element or
  client-side check for protection. Use policies/gates (or equivalent) on the
  action itself. "They can't see the button" is not access control.
- **Authentication ≠ authorization.** Logged in is not the same as allowed. Check
  *this user may do this thing to this resource* on every protected action.
- **Separate roles/guards cleanly.** Admin and regular-user contexts should be
  distinct; a regular user must never reach admin areas. Test the denial, not
  just the allow.
- **Tokens/links:** signed/expiring/single-use where appropriate (magic links,
  password resets, unsubscribe, reply tokens). Make them unguessable (long
  random, compared by hash). Don't put a guessable id where a token belongs.
- **Test the denials.** Write tests that prove cross-user access is refused
  (user A cannot load user B's resource → 404/403), guards are separated, and
  tokens expire / can't be reused. Denial tests are the ones that catch
  regressions.

## Secrets & configuration

- **No secrets in the repo, ever.** `.env` is gitignored and untracked;
  `.env.example` holds placeholders only; no API keys/tokens/passwords committed
  (check history too). Every env var the app uses appears in `.env.example`.
- Production: `APP_DEBUG=false` (no stack traces to users), secure session
  cookies (secure + httponly + samesite), enforce HTTPS.
- Rotate anything that has ever been committed or exposed.

## Input & output safety

- **Validate and authorize all input** server-side; never trust the client.
- **Mass-assignment:** never blanket-fill from request input. Whitelist fillable
  fields; set sensitive fields (role, status, balance, approved, is_admin)
  server-side, never from the request body.
- **Output encoding:** rely on the templating engine's auto-escaping; be
  extremely careful with any "render raw/unescaped" path (XSS). Sanitise rich
  text/HTML input.
- **SQL:** use the query builder/ORM with bindings; never concatenate user input
  into raw SQL.
- **File uploads:** validate type and size, store outside the web root or on a
  proper disk, don't trust the original filename, never execute uploads.
- **CSRF:** protect all state-changing routes (the framework default); exempt
  only specific endpoints that genuinely need it (e.g. a signed webhook), and
  only those.
- **Rate-limit** auth endpoints (login, magic-link, password reset) and public
  forms to resist brute-force and enumeration/abuse.
- **Spam-harden public forms** (contact/enquiry especially) beyond
  rate-limiting: a honeypot field + a minimum time-to-submit check reject the
  bulk of bot submissions cheaply, no CAPTCHA needed. Rate-limiting resists
  brute-force/abuse; these resist automated junk — a different threat. Reserve
  CAPTCHA for forms still abused after that.

## PII & privacy (GDPR / UK-GDPR if handling personal data)

- **Data minimisation:** collect only what's needed; don't store sensitive data
  you don't require.
- **Special-category data** (health/medical, etc.) needs explicit consent and
  tighter handling — restrict access to those who need it, don't surface it in
  lists/customer-facing contexts, and keep it separate where possible.
- **Never put PII in URLs/query strings, logs, or error reports.** URLs and logs
  get stored, shared, and cached.
- **Consent:** record consent + timestamps where you collect personal/marketing
  data (e.g. double-opt-in newsletter); make withdrawal easy.
- **Subject rights:** be able to honour access (export) and erasure requests.
  Erasure usually means *anonymise in place* (strip identifying/sensitive fields)
  while retaining what's legally required (e.g. anonymised financial records) —
  not a blind hard-delete that breaks accounting.
- **Backups contain PII** → store them securely/encrypted; a leaked backup is a
  breach regardless of how locked-down the live app is.
- A privacy policy and (if you set non-essential/analytics cookies) a proper
  consent mechanism are required — necessary-only cookies need just a notice.
  Legal copy is an owner/solicitor task, not something to fabricate.

## Payments

- Use a payment provider's hosted checkout (e.g. Stripe Checkout); **never store
  card/PAN data.** Prefer passwordless or provider-managed auth over rolling your
  own password storage.
- Store money as integer minor units; charges use that value; pin the real
  charged amount with a test. Never let a failed payment silently look successful.

## Webhooks

- **Verify the signature** before doing any work; reject unsigned/invalid
  (`abort(400)`). Treat unverified payloads as hostile.
- **Idempotent:** dedupe on the provider's event id so retries don't double-apply
  (double bookings, double emails, double charges).
- Thin controller that verifies + dispatches to per-event handlers; not a fat
  branch of business logic.

## Hardening & monitoring

- **Security headers:** `X-Content-Type-Options: nosniff`, frame protection
  (`X-Frame-Options`/`frame-ancestors`), a strict `Referrer-Policy`, HSTS in
  production, and a Content-Security-Policy (start report-only so you don't break
  scripts, then tighten). Register these as a **global** response middleware, not
  only on the `web` group — admin panels like Filament register their routes
  OUTSIDE the `web` group, so group-scoped middleware silently skips the admin
  (verify the headers are present on an `/admin` response, not just the public site).
- **Dependencies:** run `composer audit` / `npm audit`; keep dependencies patched;
  treat known vulns as real.
- **Error monitoring (e.g. Sentry) configured for PRIVACY:** disable default PII
  capture and scrub sensitive fields (medical, personal, card/auth tokens) and
  request bodies on payment/account routes — otherwise your monitoring becomes a
  second, unaudited store of the exact data you're protecting. Verify a captured
  event is actually scrubbed.
- **Silent failure is a security/ops risk too:** queue workers and schedulers
  must be running or security-relevant jobs (e.g. notifications, sweeps) die
  quietly.

## Process

- Threat-model lightly as you build: "who could reach this, and should they?"
- A security/PII change should ship with the denial tests that prove it.
- Keep a dedicated security audit as a periodic VERIFY pass — this skill makes the
  code right as it's written; the audit confirms nothing slipped.

## Anti-patterns (the tells)

- Loading a record by a raw URL id without an ownership check (IDOR).
- Relying on hidden UI / client-side checks for authorization.
- `$guarded = []` / trusting request input for role/status/balance fields.
- Secrets committed to the repo; `APP_DEBUG=true` in production.
- PII in URLs, logs, or unscrubbed error reports.
- Storing card data or rolling your own password crypto.
- Webhooks that act before verifying the signature, or that aren't idempotent.
- Hard-deleting a customer on an erasure request and breaking financial records,
  instead of anonymising in place.
- "We'll add security later" — later is the audit, and the audit shouldn't be
  finding broken access control.
