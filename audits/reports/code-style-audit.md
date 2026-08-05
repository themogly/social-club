# Code-Style / Craft Audit — Phase C round (audit #5)

**Branch:** `code-style/audit-pass` off `main` @ `a98480e` (after 156/157/159 merged).
**Scope:** craft the linters CANNOT see — house-rule adherence (CLAUDE.md architecture rules + named reference
implementations), logic-in-the-wrong-layer, dead code / YAGNI / over-abstraction, DRY, hardcoded thresholds,
transactional-data caching, vocabulary, swallowed exceptions, stubs. **Pint (formatting) and Larastan L6 (types)
already pass and are NOT re-litigated here** — nothing a linter catches is reported. Method: read the full domain
core (every named reference Action, the `DispensaryPos` counter stack + `HandlesTender`/`CollectsMembershipFees`
concerns, `Money`/`Weight`/`Settings`/`Wallet`, all model casts/enums) + swept `app/` (561 files) for the specific
smells. Every finding was re-verified against the code before landing here; one candidate was dismissed on
verification (below).

**Headline:** the codebase is exceptionally disciplined — single-writers, the compliance boundary, money=cents /
weight=cg, live-query-never-cache, Settings-with-defaults, translated enum `label()`s and the vocabulary rule all
hold with unusual consistency. Five candidates surfaced; **four are real and fixed here, one was a false positive**.
Nothing undoes a `DECISIONS.md` choice.

---

## PHASE 1 — Correctness (fixed)

- **`app/Actions/Wallet/RecordWalletTransaction.php:50,55,56` — debt/low-balance settings read AMBIENT, not for
  the movement's `$location`.** The balance is computed per-location (`Wallet::balance($member->id, $location->id)`,
  `:44`) but `low_balance_threshold_cents` / `wallet_debt_allowed` / `wallet_debt_limit_cents` were read with no
  location arg, so once any of these is set per-location they resolve against whatever sede is ambient — and this
  writer is reachable cross-location (the wallet relation-manager's adjust/refund offers an all-sedes picker; an
  org-wide owner sits on the org default). The canonical sibling `RefundDispensation.php:90` reads
  `Settings::get('refund_window_days', 30, $locked->location_id)`. Latent today (these keys are written org-level),
  but a genuine correctness hazard and a clear inconsistency. → **Fixed:** pass `$location->id` as the 3rd arg to
  all three reads. *(Contrast: `ResolveMemberEligibility::debtWithinThreshold` reads the SAME key ambient and is
  CORRECT, because its whole body runs inside `ActiveScope::forLocation($location->id, …)` — see Investigated.)*

- **`app/Livewire/Counter/DispensaryPos.php:618` — a non-numeric price override becomes a €0 (free) dispensation.**
  The euros were hand-parsed `(int) round_half_up((float) str_replace(',', '.', …) * 100)`; a non-numeric entry
  casts to `0.0` → `0` cents → `max(0, min(0, …))` → total 0 → a FREE contribution, behind only the
  `dispensation.price.override` permission + a reason. The component already carries a validating
  `HandlesTender::parseCents()` that returns `null` on garbage. → **Fixed:** parse via `parseCents()`; a non-empty
  field that does not parse is rejected with an error instead of silently pricing at zero.

## PHASE 2 — House rules (fixed)

- **`app/Filament/Resources/DocumentTemplates/DocumentTemplateResource.php:73` — domain orchestration on a Filament
  Resource instead of an Action.** `persistVersion()` runs a multi-step domain write (next version incl. trashed →
  deactivate the prior active → create, in a `DB::transaction`) as a static method on the Resource, called from the
  Create/Edit pages. It is the one domain write that breaks the otherwise-universal "every domain write is an
  `App\Actions` class; controllers/resources are thin" rule. → **Fixed:** extracted to
  `App\Actions\Documents\SaveDocumentTemplateVersion` (the `App\Actions\Documents` namespace already existed); the
  two pages call the Action, and `DocumentTemplateResource::persistVersion` now delegates to it (one line) so no
  call site broke.

- **`app/Models/Organisation.php:23,29,47` — a `'settings' => 'array'` cast SHADOWS the `settings(): HasMany<Setting>`
  relation; both are dead.** Accessing `$org->settings` returns the (unused) JSON-column blob, never the Setting
  rows — a silent naming trap — and neither the `organisations.settings` column nor the relation is read anywhere
  (real config lives in the `Setting` table via `Support\Settings`, which queries it directly). The parallel
  `locations.settings` column was already retired (`2026_08_01_000000_retire_location_settings_json_column`,
  prompt 59); the org side was simply missed. → **Fixed:** removed the dead cast, fillable entry and relation from
  the model, plus the `OrganisationFactory`'s vestigial `'settings' => []` write (its only writer; the column is
  `nullable` and read by nothing, so this de-shadows + deletes dead code with zero schema risk). **Recommended follow-up (owner):** drop the empty
  `organisations.settings` column with a migration copied from the locations precedent — deferred out of the audit
  branch only because it is a schema/data migration that wants its own seeded-copy test, not because it is optional.

---

## Investigated and DISMISSED (not a defect)

- **`app/Actions/Attendance/ResolveMemberEligibility.php:83` reading `wallet_debt_limit_cents` without a location
  arg is CORRECT, not a bug.** It looks identical to the RecordWalletTransaction defect, but the entire rule-building
  body runs inside `app(ActiveScope::class)->forLocation($location->id, function () { … })` (`:27`), so the ambient
  Settings location IS `$location`. Left unchanged.

## OWNER / JUDGMENT (reported, not changed)

- **`app/Filament/Pages/RegistroDispensacion.php:146` — `controlRows()` builds a raw multi-join `DB::table(...)`
  inline in a Filament page** rather than a `ViewModel`. It is correct, live and location-scoped (COMPLETED-only,
  snapshot columns), so this is placement taste against the "page data assembly → `ViewModels`" rule, not a bug.
- **Edge €→cents conversion style is split** — ~17 files hand-roll `round_half_up((float)$eur * 100)` vs `Money::fromEuros()`.
  `DECISIONS.md` explicitly BLESSES the raw edge form, so this is **not a defect** — an optional one-style tidy-up only.
  (Same family, already recorded in DECISIONS as intended consolidation: `CollectsMembershipFees::parseFeeCents`
  duplicates `HandlesTender::parseCents`.)

## CONFIRMATION (verified solid)

- **Transactional data is never cached.** Balances, stock, limits, takings, occupancy, till/arqueo are all
  live-queried; the only `Cache::` uses are a health probe + integer PIN-throttle counters. No Eloquent object cached.
- **Single-writers + compliance boundary are exemplary.** Each locks the row, runs one transaction, is idempotent
  under retry, audits inside the txn; void/refund authorise through the location-bound policy, not a bare permission.
- **Money = integer cents, weight = integer centigrams, end-to-end.** Every amount `*_cents` / weight-of-goods `*_cg`
  column uses the casts; rate/limit/definitional columns are plain-int per the documented carve-outs. No float feeds
  a stored or compared money/weight value.
- **The rest of the rulebook holds.** Domain thresholds are `Settings::get(key, default)`; every backed enum has a
  translated `label()`; vocabulary is clean (no *cliente/venta/precio de venta/beneficio* in the cannabis domain);
  no `$guarded = []` (`User` is `totallyGuarded`); no TODO/FIXME/stub features.
