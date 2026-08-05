# Security & Privacy Audit — Phase C round

**Branch:** `security/audit-pass` off `main` @ `270088a` (after prompt 153).
**Scope this round:** the surfaces that merged 136–153 and are about to receive real members' Article 9 data —
member↔club messages (136), the magic-link + lockdown-reactivation token routes, the S3 documents disk serving
path, email normalisation at the auth boundary (146), the assembly tables in anonymisation (137), and the
no-index layers over the three public routes (SEO folded in per the work order).

**Headline:** the codebase is clean. Every surface the work order named is correctly built, and — critically —
the highest-value one (the message IDOR) is already proven by a test that requests another member's id, not by
reading the policy. One genuine defense-in-depth finding (an un-throttled token route). The rest is confirmation.

Method: inventoried `routes/web.php`, the member controllers, `App\Support\VaultStream`/`DocumentVault`, the
token actions, `AnonymiseMember`, `SecurityHeaders`; ran the existing access-control suites (33 pass);
`composer audit` clean.

---

## PHASE 1 — Must-fix (exploitable / data-exposure)

- **Lockdown reactivation route is not rate-limited** → add a `throttle` to `GET /reactivar/{token}` → the token
  is an unguessable sha256 (brute-force is impractical, so this is NOT exploitable today), but it is the one
  unauthenticated token route with no throttle while its sibling `login/verify/{token}` has `throttle:10,1`. An
  un-throttled route that consumes a single-use token and lifts an org-wide lockdown should not be the exception.
  Defense-in-depth, and it removes an inconsistency. **This is the only code finding.**
  **FIXED** — `throttle:10,1` added; `PanicLockdownTest::test_the_reactivation_route_is_rate_limited` proves the
  11th request/min returns 429 (single-use is separately proven by `…is_single_use`).

Review: One finding, fixed with a test. Everything else confirmed secure against existing, passing tests.

### Confirmed secure this round (verified, not assumed — no change needed)

- **Message IDOR (`socio/mensajes/{thread}`)** — `MessageController::resolveOwnThread()` escapes the org scope
  and pins `member_id`, then `findOrFail` → 404. Independently proven: `MemberMessagesTest::
  test_a_member_cannot_open_another_members_thread` **requests another member's thread id** on both `show` and
  `reply`, asserts 404, and asserts no reply row was written; `…cannot_reach_a_thread_in_another_org` covers the
  cross-org case. Both pass. This is exactly the test the work order asked for and it already exists.
- **Token routes** — `ConsumeMemberLoginToken` and `LockdownReactivationController` both: store a `sha256` hash
  (never the raw token), `lockForUpdate` inside a transaction, reject `used_at !== null` or expired, then stamp
  `used_at` — single-use + expiry + hash-compared. `login/send` `throttle:5,1`, `login/verify` `throttle:10,1`.
- **S3 documents serving** — `VaultStream::respond` (shared by the document + photo/signature endpoints): (1)
  `signed` middleware = TTL/expiry; (2) **bound to the issuing user** — `abort_unless(query('u') === user id, 403)`
  so a leaked/replayed URL is refused for another session; (3) `Gate::authorize('view', …)`; (4) exists-check →
  404; (5) `DocumentAccessLog::create(...)` writes **before** streaming, so it logs regardless of whether the
  disk is local or S3. `DocumentSecurityTest` asserts exactly one log row per view (two for two views), a
  cross-org 403 with a valid signed URL, and that a rejected request is not logged as a view. The remote-disk
  change does not touch any of this — the log write and the five gates are disk-agnostic by construction.
- **Email normalisation (146)** — staff `Login` normalises the credential via `Email::normalise` before
  `retrieveByCredentials`; the member magic-link matches `LOWER(email)` against a lowered needle. Both sides
  normalise the SAME way, so 146 did not open a path to resolve a *different* account — a case/whitespace variant
  now resolves to the one canonical row, never a second. The QR-scan and PIN throttles key on operator id +
  terminal, unchanged by 146 (which only touched the email identifier).
- **Anonymisation of new member-linked tables** — `AnonymiseMember::COVERED_MEMBER_TABLES` lists
  `message_threads`, `messages`, `assembly_attendances` and `convocatoria_recipients`; the assembly rows are kept
  as legal evidence of convocation/attendance with the **name snapshot redacted**, mirroring the message
  treatment. `RgpdCompletenessTest` is the guard that fails if a member-linked table is added without a coverage
  entry — so coverage passes because it is complete, not because nobody added a table.
- **No-index over the three public routes (SEO, folded in)** — three independent layers, all present:
  `<meta name="robots" content="noindex, nofollow">` on both the `socio` and `counter` layouts; an HTTP
  `X-Robots-Tag: noindex, nofollow, noarchive` from `SecurityHeaders` on every response; and a disallow-all
  `robots.txt`. The application form, member login link and reactivation page are covered by all three.

---

## PHASE 2 — Privacy & GDPR

- Nothing to fix. Consent is explicit, versioned AND (as of 153) per-locale with the locale recorded; retention
  is configured (`data_retention_days`, message + audit + import-staging sweeps, each with a health heartbeat);
  erasure is anonymise-in-place (`AnonymiseMember`) preserving financial rows; special-category data (medical
  flag, consumption) sits behind the encrypted vault + access log. The RAT enumerates every processing activity.

Review:

## PHASE 3 — Hardening & monitoring

- Nothing to fix in code. `SecurityHeaders` sets nosniff, frame protection, Referrer-Policy, X-Robots-Tag.
  See OWNER/OPS for the deploy-time items (HSTS, `APP_DEBUG=false`, Sentry DSN + PII scrub, cookie flags) — they
  are configuration, not code, and are the pre-staging gate's job (Phase D §4/§5).

Review:

---

## OWNER / OPS tasks (not code defects — do not "fix" by inventing content or config)

- **Sentry is not configured** — there is no published `config/sentry.php`; if the club wants error monitoring,
  set the DSN with `send_default_pii=false` and a body-scrubber. Optional; noted for Phase D.
- **Deploy config** (Phase D §4/§5): `APP_DEBUG=false`, HSTS/secure-cookie/SameSite at the edge, HTTPS (Cloudflare
  Full-strict), R2 bucket private + EU, `TRUSTED_PROXIES` correct (and the QR-scan throttle proven to trip),
  `/dev/mail` 404 in prod, no seeded/test accounts in the production users table.
- **`composer audit` / `npm audit`** — `composer audit`: no advisories. (`npm audit` is a dev-toolchain concern;
  no runtime JS ships to the client beyond the vendored bundle.)

## Discussion (documented decisions — NOT to be "fixed")

- The token routes are intentionally `GET` magic-links in email (prompt 15 pattern). An email client that
  prefetches the link consumes the single-use token — a known trade-off of the magic-link design, accepted for
  the member login and therefore consistent for the reactivation link. Not a defect; noted for awareness.
