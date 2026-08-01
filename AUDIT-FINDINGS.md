# Phase C audit findings — 2026-07-31 overnight run

Five audits ran read-only over `main` @ the overnight head (accessibility, admin, design, code-style,
security; seo skipped — no public site). SAFE, mechanical findings were fixed as their own commits
(noted ✅ below). Everything touching **security, money, compliance or requiring visual/judgement** was
**logged, not auto-fixed**, per the overnight rules. **Read the 🔴 HIGH security/authorization items first.**

---

## 🔴 HIGH — security & authorization (NEEDS-HUMAN — do not auto-fix; verify in a running app)

### S1. ID scans / medical certs / member photos / generated PDFs appear to be stored PLAINTEXT at rest
- **Where:** `config/filesystems.php` `documents` disk (plain `local`/`s3`, `visibility:private` only — not an
  encrypting adapter); written via plain `->put()` in `app/Actions/Documents/GenerateMemberDocument.php:62`
  and the uploads in `app/Filament/Resources/Members/Schemas/MemberForm.php`. Only the ID *number* DB
  column is encrypted (`Member.php`), **not the document images**. The comment at `config/filesystems.php:52`
  ("encrypted at the model layer before write") looks **inaccurate/misleading**.
- **Why it matters:** this is exactly the Article-9 special-category data (ID scans + medical certs) the
  competitor leaked. Private-disk + signed-URL protect the HTTP path but NOT disk/backup/snapshot/S3-object
  theft — which is what "encrypted at rest" (CLAUDE.md §Security) exists to stop.
- **Suggested fix (human):** encrypt bytes at the model layer (`Crypt::encryptString` on write, decrypt in the
  streaming controller) or an encrypting FS adapter; on S3 enforce SSE-KMS; correct the misleading comment;
  add a test asserting a stored file is ciphertext. **Crypto + compliance — not auto-fixed.**

### S2. The signed-URL document streaming endpoint has NO authorization, NO per-view access-log, NO object-ownership check
- **Where:** `app/Http/Controllers/MemberDocumentController.php` `show()` — only the `signed` middleware.
  No `Gate::authorize('view', $document)` (the *receipt* controllers DO call it), no `DocumentAccessLog`
  write per stream, no org/location ownership check, and `MemberDocument` carries no org/location scope.
  Logging happens once at issuance (`IssueDocumentUrl.php`), gated only by the coarse `member.documents.view`.
- **Why it matters:** "every view is access-logged" is really "every *issuance* is logged" — a reloaded /
  prefetched / leaked / replayed short-lived URL logs nothing further; the URL isn't user-bound (a leaked
  URL is replayable); no object-ownership gate means the coarse permission is the only cross-scope barrier
  to ID-scan access. Contradicts CLAUDE.md §Security.
- **Suggested fix (human):** in `show()` add `Gate::authorize('view', $document)` + an org/location ownership
  check + a `DocumentAccessLog` write per stream; bind the signed URL to the user id. Consider extending the
  per-view access-log to the dispensation/bar receipts too (consumption data). **Authz + Article-9 — not auto-fixed.**

### A1. Finance chart widgets have no `canView()` — a STAFF panel user may be able to mount them directly
- **Where:** `app/Filament/Widgets/IncomeVsExpensesChart.php` + `IncomeByPeriodChart.php` (registered by
  `discoverWidgets`); `app/ViewModels/DashboardCharts.php` `incomeByPeriod()`/`incomeVsExpenses()` return
  figures **without consulting the `canSeeFinance` flag they compute**. The only gate is the dashboard
  blade's `@if($canSeeFinance)` — presentation, not an auth boundary. STAFF are panel users (the dashboard
  deliberately shows them a no-finance board), so they could mount the widget via Livewire and read org-wide
  income/expense.
- **Suggested fix (human):** add `public static function canView(): bool` (permission `reports.view*`) to the
  two finance widgets, AND return zeros from those two `DashboardCharts` methods when `! canSeeFinance`.
  **Financial exposure — verify by mounting as STAFF in the running panel; not auto-fixed.**

---

## 🟠 MEDIUM — log for decision (NEEDS-HUMAN)

- **AD2. ForceDelete UI wired but policies grant no `forceDelete`.** `MembersTable` + `EditMember` (and
  systemically Articles/Discounts/Locations/Users/MembershipTiers/Genetics/Events/Batches/Announcements)
  wire `ForceDelete`/`ForceDeleteBulk`, but the policies define none (and `MemberPolicy` documents
  "deliberately no force-delete… attribution/history never destroyed"). Currently inert (no `Gate::before`
  bypass), but a latent hazard: add a `forceDelete()` to a policy one day and members become hard-deletable.
  Also `MemberApplicationsTable` wires `DeleteBulkAction` with no `delete` in its policy. **Fix:** remove the
  ForceDelete actions from soft-delete-only records (Members first) so UI matches policy.
- **CS1. Two parallel member-onboarding paths compute the same enrolment defaults.**
  `ApproveApplication.php` and `CreateMember.php` each independently set `carencia_ends_at = now()+carencia_days`,
  `status=ACTIVE`, `member_no`, `joined_at`. If the carencia rule changes, they drift. **Fix:** extract a shared
  `EnrolMember` action both call. Structural + both paths tested — prove identical before/after.
- **AD4. `wallet adjust` (can subtract balance) + `batch merma` (stock loss) have no `requiresConfirmation()`.**
  Both gated + reason-required, but a mistyped negative mutates money/compliance-stock with no final
  confirm. **Fix:** add `->requiresConfirmation()`. (Money/stock — flagged rather than blind-changed.)
  **CORRECTION (prompt 48):** this line originally said "gated + audited" — they were gated and reasoned in
  their own domain ledgers but NOT written to `audit_logs`. Prompt 48 wired `RecordAuditLog` into
  `RecordWalletTransaction` (ADJUSTMENT → `wallet.adjusted`) and `RecordStockMovement`
  (MERMA/ADJUSTMENT → `stock.merma`/`stock.adjusted`), so they are now genuinely audited.
- **CS5. `Genetic::grams_per_unit_cg` is a `*_cg` column cast as plain `integer`, not `WeightCast`** — the one
  suffix-convention carve-out. Defensible (definitional constant, all arithmetic is `(int)`), but decide:
  document the carve-out in CLAUDE.md, or move it onto `WeightCast` (ripples through call sites).

---

## 🟡 LOW — hardening / judgement (NEEDS-HUMAN)

- **SEC3.** The one public write (`ApplicationController::store`) has only `throttle:10,1` — no honeypot /
  min-submit-time. Low (gated by an unguessable hash-looked-up invite token), but the kit's method lists both.
- **SEC4.** `SecurityHeaders` sets noindex/nosniff/X-Frame-Options/Referrer-Policy but **no CSP, no HSTS**
  (deferred to "prompt 17", not shipped). Add a report-only CSP first (a blind CSP can break Alpine/Livewire/
  Motion One), then enforce; HSTS in production.
- **A11y (NEEDS-HUMAN):** placeholder contrast (`text-ink-muted/60` ≈ 3:1, fails AA; near-invisible in dark) —
  touches a brand token; dynamic flash/offline banners lack `role`/`aria-live`; counter screens have no `<h1>`
  (headings start at h2); footfall heatmap has no text alternative. (Icon-button/search names + aria-hidden
  ring ✅ fixed.)
- **Design (NEEDS-HUMAN):** no shared `<x-button>` component — the primary/secondary CTA is a hand-repeated
  near-duplicate ~17× with size drift across counter/socio screens; extract shared variants (root cause of the
  smaller drift). (Palette/enum/empty-state items ✅ fixed or pending-safe below.)

---

## ✅ SAFE-FIX — applied this run (own commits, merged green)

- **a11y** (`chore/a11y-audit-fixes`): accessible names on counter icon-buttons (flash-dismiss, bar qty
  steppers, basket remove) + genetics/article search inputs; `aria-hidden` on the decorative aforo ring.
- **code-style** (`chore/code-style-round-half-up`): swapped native `round()` → the mandated `round_half_up()`
  at all ~13 cents/cg/bp edge conversions (behaviour-identical; money E2E tests confirm).

## ✅ SAFE-FIX — remaining (small, safe; not yet applied — quick morning follow-ups)

- **design:** socio `history.blade.php` empty states → designed dashed-card; off-palette `bg-red-50`/`bg-amber-50`
  in socio `login`/`application`/`events` → `bg-error/10`/`bg-warning/10`; raw enum values `->status->value` /
  `->type->value` in socio `home`/`history` → `->label()`; `failed-jobs.blade.php` off-palette/non-dark table
  borders → `#e2e8f0` + themed; till arqueo badge `border-slate-300` → `border-line`.
- **code-style:** `ApproveApplication` inlines consent creation → use the existing `RecordMemberConsent` action;
  add `Batch::scopeDispensable()` and route `SelectBatch` + `DispensaryPos`'s 3 inline batch predicates through it.
- **admin:** add tailored empty states to the 4 sibling member relation managers (parity with the new Discounts one).

## ✅ Verified CLEAN by the audits (no action)
ULIDs on every user-addressable model (no sequential-id exposure); all 22 Filament resources have real
permission policies; report pages gate on permission + per-location; counter Livewire components gated in
`mount()` with re-checks on sensitive actions; org/location scoping sound (every `withoutGlobalScopes()`
re-applies explicit scoping); no secrets in the client bundle (VAPID stays server-side); prompts 26 (PIN:
bcrypt + rate-limited + never logged), 27 (discount action defense-in-depth), 29 (invite_token encrypted for
display, hash for verification), 31 (temporary convert/extend gated + audited) all solid. Dark-mode + palette
tokenisation essentially complete on custom surfaces; socio PWA + dashboard/report markup a11y-clean.

---

# Step 3 — completeness-check findings (2026-07-31)

Ran `gates/completeness-check.md` across every surface. **The compliance-critical core is REAL and
wired** — all hard blocks (age, carencia, active-membership, sanction, daily/monthly gram caps, debt
threshold, aforo capacity, override permissions) genuinely enforce and read their settings; POS, till/
arqueo (blind close + Z-report), expenses/purchases, the 8 report ViewModels, the member PWA (push IS
wired end-to-end for announcements), actas/legal docs, and the audit/RGPD surfaces are all real, not
stubs. Marker grep clean (no dd/dump/TODO/"coming soon" in live code).

## 🟠 The one real theme — INERT SETTINGS (render in the admin UI, read by no enforcement code)
Each verified by grepping the literal key across `app/ config/ database/` — appears only in
`Settings::DEFAULTS` and/or a Filament form, never in enforcement/model code. These need a per-setting
decision: **wire the one-line read at its enforcement point, or cut the control** (a setting that does
nothing is worse than no setting — the admin thinks it's in effect).

| # | Setting | Reality | Severity |
|---|---------|---------|----------|
| 1 | `active_member_cap` (+ `temporary_count_toward_cap`) | club active-member soft cap is never enforced OR warned (`membersOverLimit()` is per-member GRAMS, not head-count) → the count-toward-cap toggle is doubly inert | MEDIUM |
| 2 | `avalador_max_sponsees` | never checked — an avalador can back UNLIMITED members (the aval *requirement* is enforced; the *max* is not) | MEDIUM |
| 3 | `wallet_ring_fence` (org) vs `ring_fenced` (per-location) | the org toggle is read nowhere; the real logic (`AutoSettleDebt::isRingFenced`) reads `location.settings.ring_fenced` (default false) which NO form exposes → cross-location auto-settle is always on, the admin toggle is a no-op | MEDIUM |
| 4 | `aforo_enforcement` / `aforo_default` | a live dropdown on `LocationForm`, but aforo mode is fixed BLOCK via the enforcement matrix; the flat key is read nowhere; `aforo_default` has no fallback use → a misleading "lying" admin control | MED-LOW |
| 5 | `limit_override_requires_manager` | read nowhere; overrides ARE gated by the fixed `limits.override` permission (safe) but the manager-vs-not toggle is inert | LOW |
| 6 | `fees_to_wallet_allowed` | read nowhere; `RecordFeePayment` posts a wallet fee unconditionally | LOW |
| 7 | `currency_locale` | read nowhere; `Money::formatted()` uses `app()->getLocale()`, so the setting can't lock the es €1.234,56 format | LOW (cosmetic) |
| 8 | `blind_count_enforced` | read nowhere; `CloseTill` is always blind (safe default, dead constant) | INFO |

**Recommended:** build the two small checks the settings already invite — `active_member_cap`
enforcement/alert (#1) and `avalador_max_sponsees` (#2); resolve the ring-fence wiring (#3) and the
`aforo_enforcement` control (#4) so the admin UI stops lying; wire-or-cut #5–#7.

## Meta-finding (important — corrects my own DECISIONS.md)
The settings-completeness test (`DebtAndLocationSettingsTest`) asserts every DEFAULT is *on the form or
excluded* — it proves a field RENDERS, not that anything READS it. That produced false confidence in the
**prompt-24 DECISIONS.md claim that `wallet_ring_fence` and `limit_override_requires_manager` were
"already consumed by enforcement." They are NOT** — both are inert (#3, #5 above). DECISIONS.md corrected.

## Dead code (INFO): `app/Support/SiteContent.php` (referenced nowhere, superseded by `Settings`);
`resources/views/welcome.blade.php` (Laravel default, `/` is the Filament dashboard — unrouted).

## Correctly deferred (documented — not re-flagged)
Push triggers for low-balance/expiring/event-reminder (only NewAnnouncement wired); temporary-member
email reminder; camera scan (prompt 22/28 skipped); forecast_options_g + per-article low_stock fallback;
signed-URL invite refactor; seed placeholders; backup placeholders; and the HIGH security items above.

> **UPDATE (prompt 32, merged):** S1 + S2 RESOLVED — Article-9 docs (ID scan/cert/generated PDF) encrypted at rest via DocumentVault; the streaming endpoint now authorises (permission + org ownership), binds the URL to the user, and access-logs every view. Photo/signature/business-uploads encryption + receipt per-view logging remain tracked follow-ups.
>
> **UPDATE (prompt 113, merged):** the member PHOTO and POS SIGNATURE follow-up is CLOSED — both now write through DocumentVault (encrypted at rest) and stream only through the authorised, access-logged endpoint (VaultStream, shared with the document controller). Non-member business uploads (invoices/receipts/batch-article docs) are private but NOT Article-9 and stay unencrypted by decision (see DECISIONS.md). Receipt per-view logging remains the one open S2 follow-up.
