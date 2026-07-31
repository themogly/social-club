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
