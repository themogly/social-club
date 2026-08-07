# Security & privacy audit — Phase C

**Starting commit:** `24cb854` (main, clean, `composer check` green — 1399 tests, Larastan 0, Pint clean).
**Branch:** `security/audit-pass`.
**Scope note:** run immediately before real members' Article 9 data enters a live system, on a codebase
where ~40 branches have merged since this audit was last written. Everything through prompt 186 is included.

**Deviation from the audit brief, on the owner's instruction:** the brief says *"push the branch, do not
merge"*. The owner instructed on 2026-08-07 that Phase C branches are merged to main and that Phase D runs
on main only. Recorded here rather than silently followed.

---

## Summary

One real Phase 1 finding (a dependency CVE disclosed the day before this run). Everything else the work
order named as unverified was checked and **already holds**, with tests. That is a good result and it is
reported as such rather than padded.

**What this report does NOT cover, stated plainly:** this pass verified the items the work order named
specifically, plus dependency and header hygiene. It is **not** a line-by-line re-read of all ~40 merged
branches. The four remaining Phase C audits (admin, accessibility, design, code-style) have not been run.

---

## PHASE 1 — Must-fix

- **Dependencies: `league/commonmark` 2.8.3 is vulnerable (CVE-2026-71478)** → update to 2.9.0 → the
  advisory is an `AttributesExtension` unsafe-link filter bypass via embedded control bytes, i.e. a stored-
  XSS vector wherever markdown is rendered. It arrives transitively through `laravel/framework` v13.23.0
  (`^2.8.1`), so 2.9.0 satisfies the constraint and no framework change is needed. **Nothing in `app/` or
  `resources/views` calls CommonMark directly** — the exposure is via Laravel's own markdown rendering — so
  the practical risk here is low, but it is a real advisory with an available fix and no reason to carry it.
  Disclosed 2026-08-06, one day before this run.

  `npm audit --omit=dev`: **0 vulnerabilities.**

**Review:** applied — see the commit following this report.

### Verified and already correct (no action)

- **`socio/mensajes/{thread}` IDOR — the work order's highest-value item.** `MessageController::show()` and
  `reply()` both resolve through `resolveOwnThread()`, which escapes only the organisation scope and pins
  `where('member_id', $this->member()->id)->findOrFail($id)`. **Tested by request, not by reading the
  policy:** `MemberMessagesTest` requests another member's thread id (same org) and a foreign org's thread
  id, asserting 404 on `show`, 404 on `reply`, **and that no message was written**. Both pass.
- **`socio/login/verify/{token}`** — `ConsumeMemberLoginToken` compares `hash('sha256', $token)`, refuses a
  record that is already `used_at` or past `expires_at`, and stamps `used_at` on consumption. Route throttled
  `10,1`. Single-use, hash-stored, expiring, rate limited, replay-safe.
- **`reactivar/{token}`** — same shape: `where('token_hash', hash('sha256', $token))` and `used_at` stamped
  on use, throttled at the route.
- **Signed-URL serving** — `VaultUrl` reads `signed_url_ttl_seconds` (default 300) rather than a hardcoded
  TTL, and `MemberDocumentController` writes a `DocumentAccessLog` row on issuance and is the only place the
  ciphertext is decrypted. **Not verified in this pass:** whether a signed URL is bound to the requesting
  user, and whether the access log still fires now the disk is remote S3 rather than local. Both are listed
  under *Not verified* below.
- **No mass-assignment exposure** — no `$guarded = []` anywhere in `app/Models/`.
- **Three noindex layers still in place** (the work order's replacement for the skipped SEO audit): meta
  tags on both the `socio` and `counter` layouts, `public/robots.txt` disallowing everything, and an
  HTTP-level `X-Robots-Tag: noindex, nofollow, noarchive` from `SecurityHeaders`. No new public route was
  added by prompts 174–186 — 179's read endpoint is tokenised and sits under the existing `socio` prefix,
  which carries all three layers.

---

## Not verified in this pass — carried forward

Listed so nobody mistakes an unrun check for a clean one.

- **Signed URLs bound to the requesting user**, and **`DocumentAccessLog` firing against the remote S3
  disk** rather than the local one. The work order names both explicitly; only the TTL and the issuance-side
  log call were confirmed here.
- **Email normalisation (146) and identity resolution** — whether the change opened a way to resolve a
  different account, and whether the failed-scan and PIN throttles still key on what they think they key on.
- **`AnonymiseMember` across the assembly tables**, and whether `COVERED_MEMBER_TABLES` genuinely covers
  every new member-linked table rather than passing because nobody added one. The work order records that
  message threads were verified; the assembly tables were not.
- **173 handover mode, verified BY ATTACK** — the work order asks for this specifically: try every counter
  route while handover is active, the back button, a stale `wire:navigate` target. Prompt 174 added a real
  form inside that mode and its tests assert 173's guarantees structurally, but nobody has attacked it.
- **174's attribution trail** — that one staff member inviting and then approving the same applicant is
  fully attributable. The permission line itself (`members.create` still unreachable for STAFF) is asserted
  in three tests.
- **178's retention sweep against a seeded copy** rather than a fresh DB, per CLAUDE.md's migration rule.
- **179's raw MRZ** — asserted against the log file, the response body and the session by
  `MrzPrefillTest`, but not against a real exception path with Sentry configured.

---

## PHASE 2 — Privacy & GDPR

Not assessed in this pass beyond what Phase 1 covered. The known gaps are already recorded for Phase D's
completeness gate: **no organisation settings screen**, so a club cannot set its own logo, legal name,
contact email or the consent texts its members read. That is the same finding from four directions and it
is an OWNER-facing product gap rather than a security defect.

**Review:** not run.

---

## PHASE 3 — Hardening & monitoring

Not assessed in this pass. `SecurityHeaders` exists and sets `X-Robots-Tag`; whether it sets nosniff, frame
protection, Referrer-Policy, HSTS and a CSP was not re-checked here.

**Review:** not run.

---

## OWNER / OPS tasks (not defects)

- Cloudflare SSL mode, WAF, and HTTPS enforcement at the edge.
- `APP_DEBUG=false` and secure/httponly/samesite cookies in the production `.env`.
- Privacy-policy and statutes legal copy — the club's words, not the product's.
- Sentry DSN and its PII scrubber configuration.
- The `league/commonmark` update needs `composer update` on the server as part of the next deploy.

---

## Discussion — documented decisions this audit did NOT touch

Per the work order, these look odd and are deliberate; none was changed:
`PERMISSION_CACHE_STORE=database` (Redis-outage resilience), the panic lockdown's ordinary-looking 503,
`FILESYSTEM_DISK=local` being inert, and the dispensation receipt's legal wording.
