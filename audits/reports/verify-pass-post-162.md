# Verify pass — security + admin/design/a11y re-audit & gate re-run (post-162)

**Ran against:** `main` @ `d54e55b` (after prompts 154–162 merged). This is a periodic verify pass: the Phase-C
audit reports predate the 156→162 feature batch, so the new surface (157 photo capture, 159 organisation
identity + consent versioning, 161 column drop, 162 storage) had not been audited. Method: a security sweep of
the new surface (endpoints, policies, Article-9 handling, storage) verifying each finding against the code; an
admin/design/a11y read of the new UI; and a re-run of the three gates. Report-first in spirit; the single real
finding had an unambiguous fix (mirror an existing retention command) and was fixed in this branch.

**Headline:** the new surface is cleanly built — strong object-level authz with denial tests, correct
encryption + private-disk placement, owner-gating, no biometric matching. **One real defect** (an Article-9
retention gap on prompt 157's application photo) — fixed. Everything else is confirmation.

## Security

### 🟠 FIXED — abandoned/rejected application photos + payloads retained indefinitely
`app/Http/Controllers/ApplicationController.php` stored an applicant's optional ID photo on the private disk and
its comment claimed prompt 142's sweep cleaned abandoned/rejected ones — but `imports:prune-staging` only prunes
member-import CSVs. Nothing pruned `MemberApplication` rows or their `payload['photo_path']`, so every rejected
or walked-away prospect's photo + plaintext payload (name, DOB, email, document number) sat forever — a GDPR
data-minimisation gap on Article-9-adjacent data. → **Fixed:** new scheduled `applications:prune-retention`
(anonymises + deletes the photo past `application_retention_days`, default 180; NEVER approved applications,
whose photo the member shares); the false comment corrected. Full detail + the APPROVED-carve-out reasoning in
`DECISIONS.md` (Verify pass entry). Tested on MySQL.

### CONFIRMATION (verified correct on the new surface)
- **Photo-capture authz + IDOR closed both ways** — `MemberPhotoController` `Gate::authorize('capturePhoto', …)`
  (`checkin.manage|pos.use` + org match); the `{member}` binding 404s a foreign org before the policy runs.
  Denial tests: cross-org 404, no-permission 403.
- **Photos encrypted at rest on the PRIVATE disk, never public** — counter, public form and admin all write via
  `DocumentVault`; served only through `VaultStream` (signed, `u`-bound, `Gate`, access-logged, decrypt-here).
- **Unauthenticated application upload is safe** — `nullable|image|mimes:…|max:8192` (no SVG), route
  `throttle:10,1`, spam-guard drops bots before the write, lands encrypted under a ULID filename.
- **No biometric/face matching** — the Alpine capture only POSTs a canvas JPEG; a human eyeballs it.
- **Org-identity owner-gated** (159) — `settings.manage`/`settings.consent` in both `mount()` and `save()`; logo
  is non-personal + size/type-limited on the public disk by decision; Reply-To only on member mail.
- **Storage (162) + migration (161) + standing controls hold** — S3 keys carry no absolute path; the column
  drop is guarded + reversible; `X-Robots-Tag: noindex` + robots.txt disallow-all still in place.

## Admin / design / accessibility (new UI: 156/157/159)
No real defects. The capture UI reuses the shared `x-button` (which carries `focus:ring-2` — visible focus), is a
true progressive enhancement (camera gated on `supported`, upload fallback always present with
`focus-within:ring`), and its overlay has `role="dialog"`/`aria-modal`/escape-to-close (matching the prompt-35
camera-scan precedent). The organisation-identity page constrains the logo to image types / 1 MB / 512 px
(`contain`, so no distortion), gates owner-only, and has helper text; every field is wired (0 orphans). The 156
socio forms have `for`/`id` labels, a visible focus ring and AA-token contrast. Minor, not fixed: the capture
overlay has no focus TRAP (consistent with the camera-scan precedent) and the preview `<img alt="">` is
decorative — both defensible.

## Gates (re-run @ `d54e55b`)
- **Completeness (11a):** GO — 0 TODO/stubs, `UnreachableCodeGuardTest` green, no empty resources/views/routes.
- **CMS-field (11b):** GO — 59 settings keys, 0 orphans; the `organisations.settings` column it flagged is now
  DROPPED (161).
- **Pre-staging (12):** CONDITIONAL GO — 141 closed the PHP/MySQL version-skew blocker (CI now 8.5 + `mysql:8.4`),
  162 closed the S3-key leak; **automated backups remain the one real NO-GO** before real member data, plus the
  server's own PHP flip to 8.5 (the coordinated action 141 enabled) and Node 20→24 as a minor follow-up.
