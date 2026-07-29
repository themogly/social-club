# 17 — Audit log, RGPD tooling & security hardening

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`, section B of NOTES, and the kit's
**`web-app-security`** skill. Requires 01–16 merged.

`git checkout main && git pull` → `git checkout -b feat/audit-rgpd-security`.

> Section B of NOTES documents how a competitor in this exact market exposed roughly a million member
> records and a million ID scans through sequential-id IDOR and unsigned document URLs — and, because
> members carried a medicinal flag, turned it into an Article 9 health-data breach. This prompt is
> the one that makes sure this build is not the sequel.

## Build

**Audit log (append-only)**
- Wire `RecordAuditLog` (prompt 01) across everything consequential: member create/edit/status
  changes, limit overrides, dispensation voids, stock adjustments and merma, price and discount
  changes, wallet adjustments, till closes and variances, permission and role changes, settings
  changes, document generation and **document views**, imports, exports and erasures.
- Store actor, action, subject, before/after, IP, user agent, timestamp. **No update or delete path
  exists** — enforce in the model and prove it in a test.
- An admin viewer (`audit.view`): filter by actor, subject, action, date; searchable; exportable.
- Retention for audit entries is deliberately **longer** than member-data retention, and stated.

**RGPD / LOPDGDD tooling**
- **Consent records** — versioned text, timestamp, IP, lawful basis, per purpose. A member's current
  and historical consents are visible on their record.
- **Subject rights**: access (view), rectification, **portability** (a complete data pack),
  **erasure** (anonymise-not-delete, preserving the financial and consumption ledger in anonymised
  form), objection and restriction. Each is a permissioned, audited action with a recorded requester
  and completion date, so the club can evidence it responded in time.
- **Retention & purge** — a scheduled job that anonymises members past the retention window and
  purges expired documents. Idempotent; dry-run mode; every purge audited.
- **Registro de Actividades de Tratamiento (RAT)** — a generated record of processing activities
  (purpose, categories, recipients, retention, transfers, security measures) that the AEPD can demand
  at any time. Generate it from the actual model, don't hand-write a PDF.
- **Breach log** — record an incident, its scope, and the 72-hour AEPD notification status, with the
  runbook linked. A form and a checklist, not a promise.
- Mark cannabis consumption and therapeutic status explicitly as **Article 9 special-category data**
  in the RAT and in the DPIA note.

**Security hardening**
- **Verify UUID/ULID coverage**: no sequential integer appears in any route, API response, export
  filename, QR payload or PWA URL. Write a test that walks the route list and asserts it.
- **Private-disk discipline**: ID scans, member photos, signed documents, receipts and lab reports
  are encrypted at rest, off the public disk, served only through **short-lived signed URLs** at
  unguessable paths, and **access-logged on every view**.
- **Authorization everywhere**: a policy on every Filament resource, an authorize on every Livewire
  component mount, object-ownership checks on every member-facing route. **Test the denials** —
  user A cannot load user B's resource → 403/404, one test per surface.
- **MFA** on admin accounts; session timeout; forced re-auth for sensitive actions (viewing an ID
  document, running an erasure, closing a till with a large variance).
- Rate limiting on login, PIN entry, magic links, QR scan resolution and exports.
- Security headers, secure cookies, `noindex` globally, `robots.txt` disallow-all.
- **No secrets in any client bundle** — assert it in a build test.
- `composer audit` and `npm audit` in the `composer check` gate or CI.

**Operational monitoring (nothing else in the build owns this, and the failure mode is silence)**
- **Heartbeats for the scheduler and the queue worker.** Membership expiry sweeps, auto-checkout,
  recurring overheads and the retention purge are all scheduler-only; queued audit writes, QR emails
  and push notifications are all worker-only. If either stops, **nothing visibly breaks** — the club
  simply stops expiring memberships and stops emailing cards, and nobody notices for weeks.
  Implement: a scheduled job that writes a heartbeat, an alert (email/Sentry) when the last heartbeat
  is older than a threshold, and a **health panel on the dashboard** showing scheduler, queue,
  last-backup and last-restore-test status.
- **Automated daily backups with a tested restore.** Document the restore procedure in SETUP.md and
  actually perform one before go-live. An untested backup is a rumour.
- **A dead-letter view** for failed jobs, with a retry action — so a failed QR email is visible
  rather than lost.

## Rules

- Anonymisation must leave every financial and consumption total unchanged — the books must still
  balance after an erasure. This is the constraint that makes naive `delete()` wrong.
- Audit writes must never block or break the user action; queue them if needed, but never lose them.
- Access logging applies to *reads* of sensitive records, not just writes — knowing who looked at a
  member's passport scan is the point.

## Tests (required)

- Audit entries cannot be updated or deleted by any route or model call.
- Every sensitive action writes an audit entry with actor, before and after — one test per action class.
- Route-list walk: no route exposes a sequential id.
- Document URLs expire; an expired or altered signature 403s; every view logs an access entry.
- Erasure anonymises the member and leaves contribution, dispensation and till totals identical
  (assert the totals before and after).
- Purge job is idempotent, honours the retention setting, and has a dry-run that writes nothing.
- Denial matrix: for each role, every forbidden surface returns 403/404 — not a hidden button.
- Portability export contains the member's full data set and nothing belonging to anyone else.
- Rate limits engage on login, PIN, magic link and QR resolution.

## Finish

`composer check` green (including `composer audit` / `npm audit`). Record the retention periods, the
anonymisation strategy and the breach runbook location in DECISIONS.md. Push the branch;
**do not merge**.
