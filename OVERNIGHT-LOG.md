# Overnight run log

Timestamped, plain-language record of the unattended run. Newest entries at the bottom of each
section. Read this first in the morning.

## Plan / queue (in order)

Feature/fix prompts, each built → `composer check` green → self-merged to `main` (self-merge is the
authorised overnight exception, scoped to tonight only):

1. **25** — Alerts render in Spanish regardless of locale (IN PROGRESS)
2. **26** — PIN operator switching: audit & complete missing UI
3. **27** — Discounts admin UI (per-member + org-wide templates)
4. **28** — Dispensary POS camera QR scanning (requires prompt 22 merged — VERIFY FIRST)
5. **29** — Application invite links management UI
6. **30** — Verify the membership expiry sweep actually runs
7. **31** — Temporary / short-stay members

Then the overnight finale:
- **Step 1** — merge everything outstanding to `main` (dependency order; `composer check` after each)
- **Step 2** — Phase C quality: accessibility-audit → ui-passes 01–04 → admin-audit →
  design/code-style/security audits (skip seo — no public site). Merge between each.
- **Step 3** — completeness-check across prompts 00 → latest.

Stop conditions: never silently push past red. If a merge/audit can't be made green in a few focused
attempts, stop merging further, leave `main` at last known-good, log exactly what broke + where to
resume. Screenshots: the unattended env has no Playwright MCP (per BUILD-LOG), so visual/screenshot
steps are logged as deferred-to-human rather than fabricated; `composer check` green is the gate.

---

## Log

**2026-07-31 — start.** Prompt 24 (debt/multi-location settings) confirmed already merged & pushed
(main @ 2787ef9, suite 341 green). Beginning prompt 25 on branch `feat/alerts-translation`.

### Prompt 25 — alerts translation (in progress)
- **Audit (done):** root cause = `Dashboard::decorateAlerts()` builds 6 alert sentences with
  `trans_choice()`, and 3 more report/register counts do too — but `LangKeys::usedInCode()` only
  scanned `__(`/`@lang(`, never `trans_choice(`. So all 9 pluralized keys were invisible to prompt
  19's parity test and were never in `lang/*.json`; `trans_choice` echoed the Spanish key → Spanish
  in the English default UI. Independent Explore-agent audit corroborated. Everything else on the
  notification surface (33 `Notification::make()` titles, all counter `flash()` calls, all
  `ResolveMemberEligibility` reasons) already routes through `__()`.
- **Also found:** 6 exception messages that ARE surfaced to users (POS flash + Filament toast bodies
  via `$e->getMessage()`) were hardcoded English → leaked English into the Spanish UI. Translated.
- **Out of scope (documented):** stored ledger `reason`/`motivo` descriptors (`Aportación por
  dispensación`, etc.) — persisted domain DATA in the canonical Spanish vocabulary (CLAUDE.md: terms
  of art), a distinct stored-data-localization concern, not a live-rendered alert. Dev-mail preview
  fixtures + FailedJobs raw queue-exception text also left as-is.
- **Fixes:** added the 9 `trans_choice` keys + 4 new exception keys to `lang/en.json` + `lang/es.json`
  (`lang:sync --check` green); extended `LangKeys::usedInCode()` to scan `trans_choice(`/`trans(`
  (root-cause fix — parity test now covers pluralized keys); new `App\Support\NotificationCopyScanner`
  (tokeniser-based) flags raw literals at notification/alert sinks (title/body/flash), wired into
  `composer check` via a test that also proves it catches a reintroduced regression.

**2026-07-31 — Prompt 25 DONE.** Merged to `main` @ 3378bfe, `composer check` green on main
(353 tests / 2253 assertions), pushed, branch deleted. Moving to prompt 26 (PIN operator switching).

**2026-07-31 — Prompt 26 DONE.** PIN operator switching. Audit: backend + Users PIN control already
built/tested but the UI was never wired (CounterOperator never set → everything attributed to the
device login). Built one shared IdentifiesOperator trait + operator-strip PIN-pad partial across all
4 counter screens + a requireOperator() guard on every commit. 10 new end-to-end tests. Merged to
main, composer check green (363 tests). No migration → MySQL parity N/A. Next: prompt 27 (discounts UI).

**2026-07-31 — Prompt 27 DONE.** Discounts admin UI. Audit: org-wide DiscountResource + AssignMemberDiscount
+ ResolvePrice already existed; only the per-member UI was missing. Built a Descuentos relation-manager tab
(gated member.discount.assign) delegating to AssignMemberDiscount + new Update/RemoveMemberDiscount actions
(audited who/from→to+reason). Per-member custom = global; reason→audit (no column, migration-free). Merged
to main (689ce0c), composer check green (369 tests). Next: prompt 28 (POS camera QR) — depends on prompt 22.

**2026-07-31 — Prompt 28 SKIPPED (blocked, logged, not built).** Prompt 28 (dispensary POS camera QR
scan) requires prompt 22 (check-in camera QR scan) merged, and its whole point is to REUSE the
camera-scan component prompt 22 built ("extract and reuse, not rebuild"; "do not fork a second
QR-camera implementation"). Verified prompt 22 was NEVER built: no `getUserMedia` / `BarcodeDetector`
/ camera decode anywhere in the repo; the check-in screen has only the keyboard-wedge scanner +
name-search. Prompt 03 left a `settings.camera_scan_enabled` toggle on the Location form, but nothing
reads it and there's no such key in Settings::DEFAULTS.
- **Decision:** do NOT build the camera scanner from scratch under prompt 28's branch. That would be
  building prompt 22 (a JS-heavy getUserMedia + BarcodeDetector + bundled-fallback decoder + camera
  permission flow), which the user did not queue, contradicts prompt 28's "reuse not rebuild" mandate
  and the one-prompt-one-task rule, and — critically — cannot be verified unattended (no camera, no
  Playwright MCP in this env). Per the overnight rule, camera/interactive UI is exactly the "log it,
  don't guess overnight" case.
- **To resume (human):** build prompt 22 first (a shared camera-scan Livewire component: BarcodeDetector
  with a bundled fallback, gated on `camera_scan_enabled`, graceful degradation to manual entry, feeding
  the existing `ResolveMemberByToken` lookup), then prompt 28 reuses it on the dispensary POS Identify
  step. main is green; nothing was left half-built (no branch created for 28).
Continuing to prompt 29 (application invite links UI).

**2026-07-31 — Prompt 29 DONE.** Invite links management. Audit: invites persisted but the raw link was
hash-only → unrecoverable (reported bug). Built re-copyable link (encrypted token) + Invitations view
(status board, expiry, revoke, Copy/Revoke actions) + invite_expiry_days setting. Kept the security-
critical hash-verification path unchanged (signed-URL refactor deferred for human review). Single-use;
blank New-application create removed; resend-email deferred (no mailable). Migration verified on MySQL.
Merged to main, composer check green (374 tests). Next: prompt 30 (verify the expiry sweep runs).

**2026-07-31 — Prompt 30 DONE.** Verify expiry sweep. Verdict: the sweep was correct + registered +
idempotent all along (NOT broken). Fixed 2 real gaps: generic heartbeat → per-job 'memberships-sweep'
heartbeat + health-panel section (red if the sweep stalls even when the scheduler is green); thin docs
→ SETUP.md Scheduled-jobs section (cron + local dev + how-you-know). Confirmed: lapsed blocked at
counter+door; unpaid≠lapsed (date-driven). Merged to main, composer check green (378 tests). No
migration → MySQL N/A. Next: prompt 31 (temporary members) — the last feature prompt.

**2026-07-31 — Prompt 31 DONE.** Temporary/short-stay members. Added kind + temporary_expires_at
(additive); load-bearing rule proven (compliance resolvers never reference temporary → checked
identically); OFF by default + legally-unsettled note in settings; enrolment toggle + directory
exclusion/filter/badge; auto-removal sweep via the existing AnonymiseMember (ledger intact, idempotent,
dry-run, audited, health-panel heartbeat); convert/extend audited action. Count-toward-cap=true; window
30d. DEFERRED: optional pre-removal EMAIL reminder (setting+sweep exist; needs a dedicated mailable —
documented). Migration verified on MySQL. Merged to main, composer check green (386 tests).

=== ALL FEATURE PROMPTS (25–31, skip 28) DONE. Beginning overnight Step 2 (Phase C quality passes). ===

**2026-07-31 — Step 1 CONFIRMED complete.** `git branch --no-merged main` is empty — every feature
branch (25,26,27,29,30,31) is merged. Prompt 28 skipped (documented above). main @ c855ac9, composer
check green (386 tests). Beginning Step 2 (Phase C). Running the audits (accessibility, admin, design,
code-style, security; skip seo) as read-only passes, then applying SAFE fixes as per-audit commits and
LOGGING anything ambiguous/high-risk (compliance/money/security) for human review per the overnight rules.
Note: "ui-passes 01–04" are visual/screenshot passes — the unattended env has no Playwright MCP, so those
are deferred to a human (consistent with the original run's visual-pass deferral).

**2026-07-31 — Step 2 (Phase C) audits COMPLETE.** Ran accessibility, admin, design, code-style,
security (seo skipped — no public site) as read-only passes. **Full prioritized results in
`AUDIT-FINDINGS.md` — READ THE 🔴 HIGH SECURITY ITEMS FIRST.** Summary:
- **Applied as SAFE-FIX commits (merged green):** a11y accessible-names + aria-hidden; the mandated
  `round_half_up()` at all money/weight edge conversions.
- **🔴 HIGH, logged NOT auto-fixed (per the overnight security/compliance rule):** (S1) member ID
  scans/certs/photos appear stored PLAINTEXT at rest — the Article-9 data the competitor leaked;
  (S2) `MemberDocumentController::show()` has no authorization / per-view access-log / ownership check;
  (A1) finance chart widgets lack `canView()` so a STAFF panel user may mount them and read org-wide
  income/expense. All three need human verification + a careful fix — exactly the "don't guess overnight"
  category. Everything else the audits checked came back CLEAN (ULIDs, resource policies, counter gating,
  scoping, no client secrets, prompts 26/27/29/31 solid).
- **Remaining SAFE-FIX (small, deferred to a quick morning follow-up — listed in AUDIT-FINDINGS.md):**
  a few socio palette/enum/empty-state polishes, `RecordMemberConsent`/`Batch::scopeDispensable` extractions,
  member-relation-manager empty states.
- **ui-passes 01–04:** deferred — visual/screenshot passes, no browser/Playwright in this env.
Beginning Step 3 (completeness-check) as a background verification pass.

---

# ✅ OVERNIGHT RUN COMPLETE — read this first

**Ran cleanly through all three steps.** `main` is green (386 tests / 2448 assertions on SQLite; MySQL
parity verified on every migration) and pushed to origin. No step hit a hard stop.

## What got MERGED (7 feature branches + 3 audit-fix branches, each green before merge)
- **25** dashboard alerts leaked Spanish → fixed the `trans_choice` keys + added a 2nd static-scan i18n gate.
- **26** PIN operator switching → wired the missing unlock UI on all counter screens (backend already existed).
- **27** discounts → per-member discount tab + Update/Remove actions (org templates already existed).
- **28** POS camera QR → **SKIPPED** (depends on prompt 22's camera component, which was never built —
  logged, not guessed; rebuilding it = a JS/camera feature that can't be verified without a browser).
- **29** invite links → re-copyable (encrypted token), status board, expiry, revoke; kept the security-
  critical hash-verification path unchanged (signed-URL refactor deferred for human review).
- **30** expiry sweep → it was already correct + scheduled; added the missing per-job health heartbeat + docs.
- **31** temporary members → kind + auto-removal via the existing anonymise Action; OFF by default; legal
  note in the UI; the compliance resolvers provably don't branch on it.
- **Audit fixes:** a11y accessible-names; the mandated `round_half_up()` everywhere; socio palette/enum-labels.

## What needs a HUMAN (nothing auto-fixed here — all in AUDIT-FINDINGS.md)
- 🔴 **Security (verify + fix by hand):** ID scans/certs appear **plaintext at rest** (S1); the document
  streaming endpoint has **no authz / per-view access-log / ownership check** (S2); finance widgets lack
  `canView()` (A1). Both S1/S2 sit on the Article-9 ID-document path — the single most sensitive surface.
- 🟠 **~7 inert settings** (render in admin, enforced nowhere): `active_member_cap`, `avalador_max_sponsees`,
  `wallet_ring_fence`/`ring_fenced`, `aforo_enforcement`, `limit_override_requires_manager`,
  `fees_to_wallet_allowed`, `currency_locale`. Each needs a wire-or-cut decision (full table in AUDIT-FINDINGS.md).
  **This also corrected a wrong prompt-24 claim in DECISIONS.md** (two of those toggles were said to be enforced; they aren't).
- 🟡 Remaining SAFE-FIX polish (empty states, `RecordMemberConsent`/`scopeDispensable` extractions,
  ForceDelete-UI-vs-policy, confirmations on wallet-adjust/merma), CSP/HSTS, and the deeper a11y/design items.

## Explicitly NOT done (per the overnight brief)
- **ui-passes 01–04** and all screenshots — no browser/Playwright in this env; visual verification is a human task.
- `pre-staging-gate` / the human launch checklist — reserved for the project owner, not an unattended run.

## Where to pick up
1. Read `AUDIT-FINDINGS.md` top-to-bottom (🔴 first). 2. Decide the ~7 inert settings (wire or cut).
3. Do a visual/screenshot pass on the counter screens, dashboard, and the new prompt-25→31 UI.

---

# Follow-up review branches (owner-authorised merges)

The owner queued the audit-finding fixes as prompts 32–38 and explicitly instructed "merge to main once
finished" (overriding each prompt's default "wait for review" — the owner is the reviewer). Status:
- **32 — document security (S1+S2):** ✅ MERGED (7aab69a). Article-9 docs (ID scan/cert/generated PDF)
  encrypted at rest via DocumentVault; streaming endpoint now authorises (permission + org ownership),
  binds the URL to the user, and access-logs every VIEW. Photo/signature/business-uploads encryption +
  receipt per-view logging = tracked follow-ups (DECISIONS.md). 393 green.
- **33 — finance widget authz (A1):** ✅ MERGED (3f93b64). canView() + data-layer guard on the two
  finance widgets; DispensedByGenetic € value zeroed for non-finance. 397 green.
- **34 — inert settings (wire/cut the 8):** in progress next.
- **35 — camera QR (both screens, fresh):** queued — large JS/camera build; visual verification needs a
  browser (deferred), backend/wiring/tests buildable.
- **36 — UI/a11y cleanup:** queued — note the palette/enum SAFE-FIX subset already landed in
  chore/design-audit-fixes (38c0e40); remaining = button component, a11y (h1/aria-live/heatmap/contrast),
  admin empty states.
- **37 — structural cleanup (ForceDelete/EnrolMember/confirmations/dead code/CS5):** queued.
- **38 — low-severity hardening (honeypot + CSP/HSTS):** queued.

**2026-07-31 — session practical limit reached after a very long run.** Merged so far in the follow-up
batch: **32 (doc security S1+S2)**, **33 (finance authz A1)** — the two 🔴 HIGH security fixes — and
**34 item 2 (avalador_max_sponsees cap wired)**. main @ d795f28, 398 tests green, pushed. STILL OPEN
(each has a concrete, decided plan already written in DECISIONS.md / AUDIT-FINDINGS.md — pick up there):
- **34 items 1,3–8:** 5 CUTS (limit_override_requires_manager, fees_to_wallet_allowed, currency_locale,
  blind_count_enforced from DEFAULTS, the aforo_enforcement dropdown) + 2 WIRES (active_member_cap
  dashboard alert; expose per-location `ring_fenced` on LocationForm and cut the org `wallet_ring_fence`).
  All decided in DECISIONS.md "Prompt 34" — mechanical to apply + test.
- **35 camera QR (both screens):** large JS/camera build; needs a browser for visual verification (defer
  the screenshot step). Build the shared component + settings gate + the same-lookup-handler wiring + tests.
- **36 UI/a11y:** the palette/enum/border SAFE-FIX subset already landed (38c0e40); remaining = shared
  <x-button> extraction (~17 sites), a11y (h1 per counter screen, aria-live on flash/offline, heatmap text
  alt, placeholder-contrast token decision), admin empty states, RecordMemberConsent + Batch::scopeDispensable.
- **37 structural:** remove ForceDelete controls (no policy grants them), extract a shared EnrolMember
  action, requiresConfirmation() on wallet-adjust + batch-merma, delete dead SiteContent.php + welcome.blade,
  document the grams_per_unit_cg cast carve-out.
- **38 hardening:** honeypot + min-submit-time on ApplicationController::store; report-only CSP then enforced
  + production-only HSTS in SecurityHeaders.
