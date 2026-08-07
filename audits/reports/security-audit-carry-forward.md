# Security & privacy audit — Phase C, carry-forward pass

**Starting commit:** `5e66691` (main, clean, `composer check` green — Pint clean, Larastan 0, full suite green).
**Branch:** `security/audit-carry-forward`.

**What this is.** The Phase C security pass (`audits/reports/security-audit.md`, commit `5c681af`) was explicit
that it was not a full sweep and listed seven items it had **not** verified. Four of those were named by the
work order itself. This pass closes that list. It is a continuation, not a re-run: everything the first pass
verified is taken as verified and is not re-litigated here.

**Deviation from the audit brief, on the owner's instruction:** the brief says *"push the branch, do not
merge"*. The owner instructed on 2026-08-07 that Phase C branches are merged to main and the round continues
in one session. Recorded rather than silently followed, as the first pass did.

---

## Summary

The first pass found one real item (a dependency CVE) and reported the codebase as otherwise holding. That
was true of what it checked. **The carried-forward list was where the defects actually were.** Four real
findings, three of them Phase 1, and two are the kind that only surface if you attack the thing rather than
read it:

1. **Handover mode does not constrain the authenticated device session.** An applicant holding a handed-over
   tablet can reach the full Filament admin panel, including the member register. Proven by request.
2. **Filament's own file fields bypass the vault on S3** — a presigned, bucket-direct URL to the ID scan,
   with no policy check, no user binding and no `DocumentAccessLog` row. This is the direct answer to the
   work order's question about the remote disk, and the answer is no.
3. **A member's name survives erasure** in `assembly_attendances.proxy_holder`.
4. **A handover-mode invite that is never taken up retains the applicant's name indefinitely.**

Two carried items were checked and **already hold** (email normalisation, 174's attribution). One is
verified by code path but not against live S3, and says so.

---

## PHASE 1 — Must-fix

- **Handover (173): the handed-over tablet keeps a fully privileged admin session.** `CounterHandover` is
  consulted by exactly six places — the counter layout and the five counter screens. Nothing else in the
  application knows the mode exists. The device `User` remains authenticated with `canAccessPanel() === true`
  throughout, so the applicant holding the tablet need only leave the counter URL. **Measured, not argued:**
  with a handover active, `GET /` returns **200** (the Filament dashboard) and the member list resource
  returns **200 and renders a member's surname in the HTML**. The panel also carries `LibroSocios` (the member
  register, with document numbers), `RegistroDispensacion`, `Seguridad` and `ManageSettings`. → Make handover a
  real server-side boundary: a global middleware that refuses every non-counter route while
  `CounterHandover::active()`, so the mode constrains the session and not merely the five screens that opt in.
  → **Why it matters:** this is the exact threat 173 was built for — an untrusted person alone with an
  authenticated, sede-scoped terminal. The five screens blanking themselves is a picture of a gate; the
  session behind them was never closed. The existing test
  (`test_every_counter_route_refuses_to_show_its_screen_during_handover`) enumerates counter routes only, so
  it passes while the panel stays wide open.

- **Documents on S3: the panel's own file fields mint an unlogged, unbound, bucket-direct URL.**
  `MemberForm` exposes `document_scan_path`, `medical_cert_path` and `photo_path` as Filament `FileUpload`
  fields on the `documents` disk with `->visibility('private')`. Filament's `BaseFileUpload::getUploadedFile()`
  calls `$storage->temporaryUrl(...)` for **any** private-visibility field; `previewable(false)` does not
  prevent this — it only sets an `isPreviewable` flag consumed by the FilePond JS. **Measured** against an
  s3-configured `documents` disk: `temporaryUrl()` returns
  `https://<bucket>.s3.<region>.amazonaws.com/member-id-scans/<key>?X-Amz-Algorithm=...` — a live presigned
  URL valid for 30 minutes rounded up to the hour. It bypasses `MemberDocumentController` and `VaultStream`
  entirely, which means **no `Gate::authorize`, no `u`-parameter user binding, and no `DocumentAccessLog`
  row.** → Serve these fields through the existing signed endpoint instead of letting Filament address the
  disk: keep the upload path, but stop the component minting a URL. → **Why it matters:** the work order
  asked whether every access still writes a `DocumentAccessLog` row now the disk is remote. It does not. The
  three member fields are `DocumentVault`-encrypted, so what a leaked URL yields is ciphertext — that is the
  saving grace and the reason this is not critical. But `invoice_path`, `receipt_path` and `lab_report_path`
  sit on the **same disk with Filament's default writer and no encryption**, so a presigned URL to those
  serves plaintext. And "every view of an Article 9 file is access-logged" is currently false in production.

  *Local-driver note:* on `DOCUMENTS_DRIVER=local` the same code path throws (caught) and falls through to
  `url()`, which returns `/storage/<path>` — a dead link into the public symlink, where the file is not.
  Harmless, but it means the field has never worked in dev, which is likely why nobody noticed the S3 case.

**Review:** _pending — fixes follow this commit._

---

## PHASE 2 — Privacy & GDPR

- **Erasure: a member's name survives in `assembly_attendances.proxy_holder`.** `AnonymiseMember` redacts
  `assembly_attendances.name`, but only for rows matched by `where('member_id', $member->id)`, and it never
  touches `proxy_holder`. That column is free text — `RecordAttendance` stores `trim((string) $proxyHolder)`
  from a plain `<input>` on the Asamblea screen, labelled *Representante*. So when the member who **held**
  someone else's proxy is erased, their name stays in plain text on every such row, rendered on the assembly
  screen and carried into the acta. → Redact `proxy_holder` for the erased member as well as `name`. →
  **Why it matters:** it is a straightforward Art. 17 miss, and the guard that is supposed to catch exactly
  this cannot: `test_every_member_linked_table_is_covered_by_erasure` enumerates tables holding a `member_id`
  **column** and asserts the table name appears as a key in `COVERED_MEMBER_TABLES`. It is table-level, not
  column-level, and `proxy_holder` holds an identity with no `member_id` beside it. The table is listed, the
  guard is green, and the name is still there. The work order's suspicion — that the guard might pass
  "because nobody added one" — was the right suspicion aimed one level too high.

- **Retention: a handover-mode invite never taken up keeps the applicant's name forever.**
  `PruneApplications` selects rows that are non-approved, past `application_retention_days`, **and** still
  hold `applicant_email` or `payload`. A `handover`-mode invite sets neither — `ListMemberApplications` passes
  `$email = null` for that mode and the payload only exists once the applicant submits. What it does set is
  `applicant_reference`, labelled *"¿Para quién es la invitación?"* with helper text *"Un nombre o referencia
  (p. ej. el avalador)"* — i.e. a person's name. An invite that is printed and never used therefore never
  matches the sweep, and the name is retained indefinitely. The sweep already nulls `applicant_reference`
  when it runs; it simply never runs for these rows. → Add `applicant_reference` to the "still holds personal
  data" condition. → **Why it matters:** small, but it is personal data with no retention bound, and the fix
  is one clause.

**Review:** _pending._

---

## Verified in this pass — already correct, no action

- **Signed-URL user binding.** Already implemented and already tested. `VaultStream::respond()` opens with
  `abort_unless((string) $request->query('u') === (string) $request->user()?->getKey(), 403)`, and both
  `IssueDocumentUrl` and `VaultUrl` mint `u`. `DocumentSecurityTest::test_a_url_bound_to_one_user_is_refused_for_another_session`
  and the photo equivalent in `PhotoSignatureVaultTest` prove the denial **and** assert no access-log row is
  written on the refusal. The first pass listed this as unverified; it was verified, just not looked at.
- **`DocumentAccessLog` is disk-agnostic *on the paths that use `VaultStream`*.** The write happens in
  `VaultStream` before the response, with no driver branch, so it fires identically on local and S3. The
  remote-disk gap is not here — it is the Filament bypass in Phase 1, which never reaches `VaultStream`.
- **Email normalisation (146) did not open an identity-resolution hole.** `Email::normalise()` is
  lowercase + trim only — no dot-stripping, no plus-tag stripping, no Unicode folding — so it cannot collapse
  two distinct addresses into one account. It is applied through a write cast (`NormalisedEmail`) on both
  `User::email` and `Member::email`, so every writer inherits it. Existing rows were back-filled by
  `2026_08_13_000000_normalise_existing_emails`, which **refuses to run if lowercasing would collide** two
  rows on the unique `users.email` index. That is the right shape.
- **The counter PIN throttle keys on what it claims to.** `operatorThrottleKey()` returns
  `'counter-pin:'.$locationId` — deliberately location-wide, documented as such (a shared counter has many
  devices and a browser session is trivial to rotate). It is not keyed on anything a client controls.
- **174's attribution trail.** Inviting and approving the same applicant is attributable end to end:
  `IssueApplicationInvite` gates on `applications.review` and stamps `invited_by` on the row; `ApproveApplication`
  writes a `application.approved` audit entry naming the actor. Two different mechanisms (a column and an
  audit row) rather than one, but both survive and both name a person.
- **Phase 3 headers.** `SecurityHeaders` sets `X-Robots-Tag`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, a CSP (report-only until
  `security.csp_enforce`), and HSTS in production over HTTPS only, without `preload`. Complete and correctly
  conditioned.
- **The three noindex layers still hold** (the work order's replacement for the skipped SEO audit) — meta tag
  on the `socio` and `counter` layouts, `public/robots.txt`, and the `X-Robots-Tag` above. No route added since
  the first pass escapes them.

---

## PHASE 3 — Hardening & monitoring

- **Sentry would ship raw request bodies, unscrubbed, to a third-party processor.** There is **no
  `config/sentry.php`** — Sentry is wired only by `Integration::handles($exceptions)` in `bootstrap/app.php`,
  so every option is a library default. `sentry/sentry`'s `max_request_body_size` defaults to **`'medium'`**,
  and `RequestIntegration::captureRequestBody()` gates on that size alone — **it is not gated on
  `send_default_pii`.** So the moment `SENTRY_LARAVEL_DSN` is set, any unhandled exception on a POST sends the
  full parsed body. That includes the raw MRZ on `POST /socio/solicitud/{token}/leer` (name, date of birth and
  document number in one string), the member application payload, the counter operator's PIN in Livewire
  update payloads, and the staff password on login — Laravel's `dontFlash` protects the session, not Sentry.
  → Publish `config/sentry.php` with `send_default_pii => false`, `max_request_body_size => 'none'`, and a
  `before_send` scrubber; add a test that a captured event carries no body. → **Why it matters:** this is the
  one finding here that is currently inert and becomes live at exactly the wrong moment — the DSN goes in as
  part of going to production, which is the same deploy that brings real members' Article 9 data. The audit
  brief asks for this explicitly and it had never been run.

**Review:** _pending._

---

## Not verified in this pass — carried forward again, honestly

- **The S3 presigned-URL finding is verified by code path and by a real signed URL generated against an
  s3-configured disk, but not against a live bucket.** The URL was produced with dummy credentials; nobody
  fetched an object with it. The generation is the security-relevant half and it is proven; the fetch is not
  in doubt but is not demonstrated.
- **178's retention sweep against a seeded copy** rather than a fresh DB (CLAUDE.md's migration rule). The
  `applicant_reference` gap above was found by reading the query, not by running the sweep over seeded data.
  Running it that way may find more.
- **`AnonymiseMember` was audited for the assembly tables specifically**, which is what the work order asked.
  It was not re-audited column-by-column across all 17 tables in `COVERED_MEMBER_TABLES`; the column-level
  guard proposed above would be the systematic answer and is not built in this pass.

---

## OWNER / OPS tasks (not defects)

- Cloudflare SSL mode, WAF, HTTPS enforcement at the edge.
- `APP_DEBUG=false` and secure/httponly/samesite cookies in the production `.env`.
- Privacy-policy and statutes legal copy — the club's words, not the product's.
- **Tablet kiosk/guided-access configuration.** The Phase 1 handover fix closes the server side, but a tablet
  handed to a stranger should also be locked to the app at the OS level. That is a device-management task and
  no amount of middleware substitutes for it.
- The `league/commonmark` update from the first pass still needs `composer install` on the server.

---

## Discussion — documented decisions this audit did NOT touch

Per the work order, these look odd and are deliberate; none was changed:
`PERMISSION_CACHE_STORE=database` (Redis-outage resilience), the panic lockdown's ordinary-looking 503,
`FILESYSTEM_DISK=local` being inert, and the dispensation receipt's legal wording.
