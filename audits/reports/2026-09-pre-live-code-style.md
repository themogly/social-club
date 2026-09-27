# Code-style audit — pre-live (Laravel idiom & consistency)

> **Status (2026-09-27):** Phase 1 all FIXED — strict typed money, tab after override, strain confirmation (prompt 271); `avaladorFeedback` (prompt 270). Phase 2/3 → prompt 273.


**Commit** `2d98aed` (branch `audit/pre-live`, same as main, after prompt 269) · **date** 2026-09-27 ·
**mode** REPORT ONLY: no production code changed, nothing committed.

**Method.** I followed `audits/code-style-audit.md` step 1 only, against CLAUDE.md, `skills/laravel-craft`, the previous report
(`audits/reports/code-style-audit.md`, 2026-08-07) and `audits/2026-09-post-hiatus.md`. I searched DECISIONS.md for the
decisions each finding touches. Findings marked **probed** were reproduced with throwaway Livewire tests kept in the
session scratchpad, outside the repo. The rest come from reading the code.

**Scope.** Code changed since the last audit: `git diff 098636d..HEAD -- app resources database routes config`, which is
116 files, +4295/−687, prompts 240–269. The heaviest changes are `DispensaryPos` (+560), `CommitDispensation`,
`CreateGenetic`, `IdentifiesOperator`, `SignsUpMembers`, `Permissions`, the new `AllocateFromBatches`, `SpendFromWallet`,
`StockCover`, `AvaladorResolver`, `RolePermissionOverride`, and the role/permission page.

**Gates.** `vendor/bin/pint --test`: **pass**. `vendor/bin/phpstan analyse` (Larastan L6): **0 errors**.

| | count |
|---|---|
| PHASE 1: correctness / rule violations with money, stock or privacy consequences | 4 |
| PHASE 2: simplification, one rule written more than once, hot-screen performance | 7 |
| PHASE 3: polish | 7 |

---

## PHASE 1 — Correctness & convention

- **[app/Livewire/Counter/TillSession.php:950 (`toCents`) · app/Livewire/Counter/Concerns/CollectsMembershipFees.php:368 (`parseFeeCents`)]:**
  Prompt 268 gave the tender field a strict euro parser (`HandlesTender::parseCents`, `HandlesTender.php:120`), under which
  `1.000` is refused and never read as €1. Every other money field a person types into at the counter still uses the old
  permissive reading:
  - `TillSession::toCents()` goes through `Money::fromEuros()`. It feeds the **blind arqueo count** (`:795`), the opening
    float (`:354`), cash movements (`:451`), petty cash (`:516`) and the handover count (`:275`).
  - `parseFeeCents()` is a third hand-written parser (`str_replace(',', '.')` + `is_numeric` + float), used for the
    membership fee at the door, the POS and Socios.

  All six fields are `type="text" inputmode="decimal"`, so a Spanish thousands dot reaches the server as typed.
  **Probed:** `Money::fromEuros("1.250")` gives **125 cents**. A drawer counted as "1.250" (€1,250) is stored as €1.25 on
  an immutable close.
  → Route all of them through the one strict parser: move `parseCents` onto `Money` (for example
  `Money::parseTyped(): ?int`, the twin of `Weight::canonicalGrams`) and call it from `HandlesTender`, `TillSession` and
  `CollectsMembershipFees`.
  → Why: CLAUDE.md requires one money rule and says floats in money paths are bugs. Prompt 257 fixed exactly this defect
  for grams inside `Weight::fromGrams` itself, which protects every caller. Prompt 268 fixed it for one field only. The
  blind count is the figure most likely to be four digits, and it cannot be edited after submission. (DECISIONS.md:14080
  deliberately left `Money::fromEuros` alone "where a human types it"; these fields are also typed by a person at the
  counter. See Discussion.)
  Review: real money consequence on the arqueo, and on fees in the rarer ≥ €1,000 case. A small, well-pinned fix.

- **[app/Livewire/Counter/DispensaryPos.php:1734 vs app/Livewire/Counter/Concerns/HandlesTender.php:112]: "Añadir a la
  cuenta" ignores a price override and puts too much on the member's tab.**
  - The contract in `HandlesTender` says `tenderableTotalCents()` is *"already price-override-aware"*.
  - DispensaryPos's implementation says *"(pre price-override)"*, and it is.
  - `commitOnTab()` (`:733`) passes that total to `tabState()` (`:824`), which sets `walletInput` to
    *pre-override total − cash handed*. `attemptCommit` then charges the overridden total, and `tenderSplit` lets the
    wallet absorb all of it.

  **Probed:** €8.37 overridden to €5.00, member hands €3, "Añadir a la cuenta" → **wallet 500, cash 0, balance −€5.00**,
  and the €3 is shown as change. The member meant to owe €2.
  "Justo" has the same root cause (it fills €8,37 against a €5,00 charge). There the ledger still reconciles through the
  change shown, but the screen and the charge disagree.
  → Make `tenderableTotalCents()` apply the same clamped override `attemptCommit` applies. Extract a
  `chargeableTotalCents()` used by `attemptCommit` (`:978–1005`), `tenderableTotalCents` and `render`, so the header,
  "Justo", the tab and the commit all read one figure.
  → Why: one figure computed two ways, in a money path, and the difference lands in a member's debt.
  Review: this is an edge case, since it needs a manager override plus a tab plus partial cash. But the wrong amount is
  written to the wallet ledger, which is append-only.

- **[app/Livewire/Counter/Concerns/SignsUpMembers.php:758 (`avaladorFeedback`) + app/Support/AvaladorResolver.php:32]: a
  public, operator-less Livewire method returns a whole `Member` row to the browser.**
  - Prompt 260's rule: methods the view calls are `protected`, and reads with nobody at the PIN return nothing. It was
    applied to `lookupResults`, `theirUsual`, `membershipsElsewhere`, `altaApplication`, `worklist` and
    `pendingAltaApplications`.
  - `avaladorFeedback()` predates 260 and was missed. It is `public`, has no `hasOperator()` guard, and
    `AvaladorResolver::resolve()` returns the full model on a member-number match (`->first()`, every column).

  **Probed:** on `MembershipCounter` with `CounterOperator` cleared, `set('altaForm.avalador_ref', 'M-00042')` then
  `call('avaladorFeedback')` returns in `effects.returns` the member's DNI, date of birth, address, phone and
  **`is_therapeutic`**, which is Article 9 health data.
  → Make it `protected`, return `[]`/empty with no operator, and have the resolver select only
  `id, first_name, last_name, member_no` (what `label()` needs) on both branches.
  → Why: the 260 convention exists to stop exactly this. It is also the security requirement "authorization on every
  endpoint", and a Livewire public method is an endpoint.
  Review: this is really a security finding. Hand it to the security audit too. The fix is three lines.

- **[app/Filament/Resources/Genetics/Pages/CreateGenetic.php:206]: the "strain added" confirmation misstates the stock.**
  `rtrim(rtrim((string) $data['grams'], '0'), '.')` is meant to strip trailing decimal zeros, but it also strips integer
  zeros. `250` becomes **"25 g"**, `100` becomes **"1 g"**, `1000` becomes "1 g". The value stored through `IntakeBatch`
  is correct. Only the success notification is wrong. No test asserts the notification body (`AddAStrainFlowTest` posts
  250 and 100).
  → `Weight::fromGrams($data['grams'])->formatted()`, which is the one formatter.
  → Why: it reinvents a helper that exists, and the result is a wrong stock figure shown to the person who has just
  counted the stock in.
  Review: display only, but it is the confirmation screen for a stock intake. One line, and it wants a test.

---

## PHASE 2 — Simplification, one rule in one place, hot screens

- **[app/Livewire/Counter/DispensaryPos.php:1966–2040 (`geneticRows`)]: the dispensary grid is N+1 on the screen that
  renders most.**
  The comment at `:1983` says per-card queries were removed. Only trailing consumption and first-sale dates were bulked
  (prompt 216). Each genetic still costs about seven queries:
  - `ResolvePrice::forGenetic` re-reads the member's tier and discounts for every card;
  - `remainingCg` / `remainingUnits` (`:2145`);
  - `StockCover::verdict` → `explicitLowStockThresholdCg`, because `prices` is not eager-loaded here (prompt 269
    eager-loads it for `lowCountAt` only);
  - `SelectBatch::fefo` for `has_batch`.

  **Probed:** one genetic gives 49 queries per `$refresh`; **11 genetics give 119**. Every weight-pad key press is a
  round trip.
  → Bulk the stock with `StockCover::onHandCgFor()` (already written for 269), `->with(['prices' => …])` as `lowCountAt`
  does, derive `has_batch` from on-hand > 0, and resolve the member's tier and discounts once per render.
  → Why: CLAUDE.md says hot screens must not scale queries with the catalogue. This is the 79 and 216 lesson, only half
  applied.
  Review: the top refinement. It is not a correctness issue, but it is the most-used screen in the product.

- **[app/Support/CounterOperator.php:27–31]: `current()` runs `User::find()` on every call.**
  Since prompt 255, every `userCan()` / `counterActor()` goes through it. A fresh model also reloads its Spatie roles and
  permissions. **Probed:** 5 × (users + roles + permissions) = **15 of the 49 queries** in one POS render. The same
  pattern is on every counter screen and in `top-bar.blade.php`.
  → Memoise the resolved user per request in a static keyed by id, cleared in `set()` and `clear()`.
  → Why: authorisation is correct, and this is pure repetition. It is the cheapest large saving on the counter.
  Review: small change. Pin it with a query-count test and a set/clear test.

- **The "dispensable, oldest first" rule is written four times.** They agree today:
  - `SelectBatch::fefo()` (`app/Actions/Stock/SelectBatch.php:18–27`);
  - `AllocateFromBatches` (`app/Actions/Stock/AllocateFromBatches.php:40–47`), whose docblock says it copies fefo's ordering;
  - `StockCover::onHandCgFor()` (`app/Support/StockCover.php:135`), which re-types the open/in-date filter instead of
    using `Batch::scopeDispensable` (`app/Models/Batch.php:151`);
  - `SelectBatch::isDispensable()`, the PHP twin.

  → Add a `Batch::scopeFefo()` (dispensable + the two `orderBy`s) used by both selectors, and use the scope in
  `onHandCgFor`.
  → Why: in manual mode the lote offered comes from `fefo`, and in automatic mode the lote drawn comes from the
  allocator. If those two orderings drift, "oldest first" means two things.
  Review: the previous audit found the till resolver in exactly this shape (four copies that agreed until they didn't).

- **[app/Livewire/Counter/DispensaryPos.php:1189 (`settleWithBar`)]: a dead public wire action.**
  Since 263 it only forwards to `attemptCommit`. No view calls it. The only callers are three test files
  (`CartSectionsGateOnTheirOwnLinesTest`, `CommitCombinedSettleTest`, `OneCatalogueTwoSourcesTest`), and a stale Blade
  comment still describes it gating the signature (`dispensary-pos.blade.php:927`).
  → Point those tests at `commitDispensation`, delete the method, and fix the comment.
  → Why: YAGNI and no dead code (CLAUDE.md). A public method is also browser-callable surface.
  Review: a mechanical change.

- **[app/Support/AvaladorResolver.php]: domain query rules live in `App\Support`.**
  `pool()` and `candidates()` are Member query scopes, and `resolve()` is a domain lookup. CLAUDE.md reserves Support for
  "genuine framework helpers". `resolve()` also loads **every active member of the organisation** into PHP for the name
  match, on each 400 ms-debounced keystroke of the wizard's sponsor field (`alta-staff-form.blade.php:155`).
  → `Member::scopeEligibleAvalador()` / `scopeMatchingNameOrNumber()`, with the resolver as a small Action or a Member
  static. Narrow the name match in SQL (`first_name LIKE first-token%`) before the portable PHP comparison.
  → Why: layer rule, plus a query that scales with the membership on a live input.
  Review: medium. Do it together with the Phase 1 column narrowing.

- **[app/Livewire/Counter/DispensaryPos.php:824 (`tabState`) and :1486–1488]: tab arithmetic re-derived in the
  component.**
  `fits = remainder − credit ≤ headroom` is `Wallet::maxDebitCents()` (`app/Support/Wallet.php`) written out by hand.
  Each render with a held member also runs `Wallet::totalDebtCents` three times and a balance sum three times (owes,
  headroom, owed, credit).
  → `Wallet::tabFits(Member, locationId, remainder)` (or `maxDebitCents()` directly), and compute the wallet figures once
  per render.
  → Why: the wallet writer, `CommitCombinedSettle` and the screen should ask the same function. 259 carefully made the
  writers agree, and the screen is the fourth copy.
  Review: small change. It pairs naturally with the Phase 1 tab fix.

- **[app/Support/Permissions.php (`groups()` / `label()`)]: the owner's roles page lists permissions from a hand-kept
  array, and no test ties it to `Permissions::ALL`.**
  It is complete today (checked: no permission is missing from `groups()` and none is unlabelled). A permission added to
  the catalogue later would be invisible on Sistema ▸ Roles y permisos, so the owner could neither grant nor revoke it.
  → Add a test: `array_merge(...groups()) == ALL` and `label($p) !== $p` for every `$p`.
  → Why: the same completeness-guard pattern as `SettingsCoverageAuditTest` and `FormCompletenessTest`.
  Review: test-only.

---

## PHASE 3 — Polish

- **[app/Livewire/Counter/TillSession.php:80]:** an orphaned docblock. *"Petty-cash (gasto de caja) form…"* now sits
  directly above the prompt-265 docblock for `$closedSessionId`. It describes `$expenseAmount` two properties down.
  → Move it back. → A comment attached to the wrong member is worse than none. Review: trivial.
- **[app/Support/PermissionDrift.php:103–104]:** the raw role value (`MANAGER` / `STAFF`) is interpolated into
  System-health lines (`system-health.blade.php:251`), so the English UI shows the Spanish enum key.
  → `Role::from($o->role)->label()`, or an enum cast on `RolePermissionOverride::role`.
  → CLAUDE.md: never render a raw enum value. Review: trivial.
- **[app/Support/StockCover.php:221]:** `func_num_args() < 5` tells "not passed" apart from "passed null". That is
  implicit and fragile.
  → An explicit sentinel or a separate `verdictFor()` that fetches. → Prefer explicit over magic. Review: small.
- **[app/Livewire/Counter/DispensaryPos.php:1294]:** the bar-only settle catches `DebtLimitExceededException` with a fixed
  message. The dispensation (`:1109`) and combined (`:1218`) paths show `$e->getMessage()`, so 259's specific wording
  ("solo se puede añadir a la cuenta…") is lost on a bar-only visit.
  → Use `$e->getMessage()`. → One behaviour for three paths. Review: trivial.
- **[app/Actions/Stock/AllocateFromBatches.php:70 vs DispensaryPos.php:1115]:** the allocator carefully builds a translated message that names the genetic and the sede's
  total, but the POS catches every `RuntimeException` and shows the generic *"No se pudo registrar…"*. The same happens
  to prompt 256's *"El lote :batch no corresponde…"*.
  → A typed `InsufficientStockException` (or surface the message for the translated ones). → The docblock promises the
  operator something they never see. Review: small.
- **Float edge conversions next to the helper that exists:**
  - `CreateGenetic.php:164`: `cost_per_gram_cents` via `(float) × 100`, one line above `Money::fromEuros` for the price;
  - `MemberResource.php:294`: `debt_limit_cents / 100` via `number_format`;
  - `resources/views/socio/application.blade.php:46`: `(float) cg / 100` in a view;
  - `DispensaryPos.php:1895`: weight presets via `(float) str_replace` + `round_half_up`, instead of
    `Weight::fromGrams($g)`.

  → Use the `Money` / `Weight` helpers. → CLAUDE.md: one conversion rule, floats only at the edge. Each of these already
  rounds with `round_half_up`, so none is wrong today. Review: consistency only.
- **[app/Livewire/Counter/DispensaryPos.php:810]:** a new stored reason `'Pago de deuda en el mostrador'` is displayed raw
  by `WalletTransactionsRelationManager.php:54`. It follows the existing pattern (`'Compra en barra'`,
  `'Aportación por dispensación'`), so this is not new drift, but the English panel shows Spanish reasons. See
  Discussion. Review: leave until decided.

---

## Verified OK

Recorded so the next pass does not re-derive them.

- **The writers stayed single.** Prompt 250's automatic lotes still move stock only through `RecordStockMovement`, one
  signed movement per part, and the allocator is a planner, not a writer. Prompt 259's tab goes through
  `SpendFromWallet` → `RecordWalletTransaction`. The old `allow_debt => true` bypass is gone from all three sale writers,
  and `CommitCombinedSettle` now uses the same `Wallet::maxDebitCents` as the wallet writer. Pricing is still only
  `ResolvePrice`, priced once per operator line and split with the remainder on the last part (`CommitDispensation`
  pass 2), so the stored parts sum exactly to the line total.
- **Writer-side guards (256/257).** Non-positive grams and units, a lote from another genetic or sede, and a
  non-positive misc bar line are refused inside the writers, not only in the component. `Weight::fromGrams` carries the
  strict string contract itself, and `GramAmount` / `canonicalGrams` reuse it.
- **Prompt 255's actor split is consistent.** Mount gates use `deviceCan()`, actions use `userCan()` / `counterActor()`,
  and there is no leftover `currentUser()` / `Auth::user()->can()` in the counter components. `WhosInside` keeps its own
  two small helpers, deliberately (it has no PIN pad).
- **Framework idioms are current.** `casts()` methods, `bootstrap/app.php`, and generics on the new relations
  (`Expense::recorder`, `RolePermissionOverride::setter`). Larastan L6 is clean.
- **No new caching of transactional data**: no `Cache::` or `remember` was added in the window. No commented-out code,
  no `dd`/`dump`/TODO, and no broad `catch (Throwable)` was added. The three new `catch` clauses are typed.
- **i18n**: an independent pass over every added Blade and PHP line found all UI copy through `__()` / `trans_choice()`,
  apart from the items above (the "FEFO" badge at `dispensary-pos.blade.php:264` is an acronym and is left as is).
  Enums are rendered through `label()` everywhere except PermissionDrift.
- **Seeders.** The only seed change (`DemoDataSeeder` threshold → null) is a column value, not a hand-built shape.
  `RolePermissionSeeder` applies overrides through `Permissions::for()`.
- **Size alone is not flagged.** `DispensaryPos` is 2,438 lines, and most of it is thin guard-then-Action code. The
  drift worth fixing is named above (the grid N+1 and the tab arithmetic). Every write goes through an Action.

## Discussion (needs owner decision)

1. **Strict money parsing: in `Money::fromEuros` itself, or a separate typed-input parser?** DECISIONS.md:14080 (prompt 268)
   deliberately kept `Money::fromEuros` permissive and made only the tender strict. Phase 1 #1 shows that the till
   and fee fields are typed by a person too. Prompt 257 put the grams contract inside `Weight::fromGrams`, so every caller
   was protected. The same move for `Money` would close all counter fields at once. Filament numeric inputs send
   dot-decimals with no grouping, so they are unaffected either way. This finding enforces the "one money rule"; the
   choice of mechanism is yours.
2. **`role_permission_overrides` has no `organisation_id`.** This is consistent with the install-wide Spatie roles it
   overrides, so it is not a finding for a single-organisation build. But CLAUDE.md asks for "keyed for future
   multi-organisation SaaS", and this is the one new table in the window that is not keyed. Decide whether to add the
   key now while the table is empty in production.
3. **Stored reason strings** (`wallet_transactions.reason` and similar) are Spanish literals displayed raw. You could
   translate on display (`__($state)` in the relation manager, using the existing keys), or store a reason code. This is
   a pattern choice, not drift.
