# DECISIONS.md

Running log of judgment calls. Newest section at the bottom of each area. Grep prefixes:
`OVERNIGHT-DEFAULT — CONFIRM:` (a checkpoint auto-resolved during the unattended run) and
`OVERNIGHT-PLACEHOLDER — CONFIRM:` (a client-only fact stubbed with a placeholder).

---

## Overnight autonomous run — operating exception (2026-07-29/30)

This project was bootstrapped and built through prompts 01–17 in a **single unattended overnight
run**, one prompt at a time, in numeric order. Two deviations from the starter kit's normal rules
were explicitly authorised by the owner for this run only:

1. **Self-merge authorised.** The kit's rule is "push the branch, never self-merge — a human
   reviews and merges." For this overnight run there was no human to review, so each feature branch,
   once `composer check` passed, was committed, pushed, and **merged into `main` by the agent**
   (`git merge --no-ff`), then the local feature branch deleted. Normal review-before-merge resumes
   after this run. Every merged branch is still a discrete, reviewable unit in history.
2. **Checkpoints auto-resolved, not paused.** Where a prompt has an architecture checkpoint or a
   "stop and wait for human approval" instruction, the agent did **not** stop. It chose the option
   the prompt marks as recommended (or the client-facing default in
   `prompts/NOTES-decisions-and-compliance.md` §C) and logged the choice here with the prefix
   `OVERNIGHT-DEFAULT — CONFIRM:` so the owner can grep and confirm each one.

Client-only facts that the agent could not know (CIF/NIF, legal name, premises addresses, real
opening hours, real limits) were stubbed with obvious placeholders (e.g. `TBD-CIF-NIF`) and logged
with the prefix `OVERNIGHT-PLACEHOLDER — CONFIRM:` here and in `BUILD-LOG.md`.

The single condition that would halt the whole run early: `composer check` failing after genuine
fix attempts (a `STATUS.md` would be written at the repo root if so).

---

## Architecture decisions (from CLAUDE.md — the initial working agreement)

- **Profile: Full app** (real transactional domain: memberships, wallet ledger, dispensations,
  till/cash, reports). Full architecture: Actions + ViewModels + fat models + thin controllers.
- **No repository pattern; no service layer by reflex.** Business logic in fat models or
  single-purpose `App\Actions`. Page/dashboard assembly in `App\ViewModels`. Helpers in `App\Support`.
- **No payment provider (no Stripe).** Money is cash + a member wallet ledger, integer **cents**.
  A payment layer can sit on top of the ledger later without touching it. The kit's Stripe/Cashier
  install and webhook scaffolding are omitted.
- **No public / marketing site.** Legal constraint (Spanish CSCs may not advertise), not a
  preference: no landing page, no public menu, no SEO surface. `X-Robots-Tag: noindex` globally +
  `robots.txt` disallow-all. Filament panel mounted at `/`. The kit's public-site + SEO scaffolding
  is omitted.
- **Money = integer cents (EUR); weight = integer centigrams (0.01 g).** Euros/grams only at the
  input/display edge via casts (`App\Casts\MoneyCast`, `App\Casts\WeightCast`). One shared
  `round_half_up` helper. A float in either is a bug.
- **ULID primary keys on every user-addressable model.** No sequential integers in any route, API
  response, filename or QR payload — a hard security requirement (a competitor leaked ~1M records
  via sequential-id IDOR; see NOTES §B). ULID over UUID: time-ordered, index-friendly.
- **Scope = organisation_id + active location.** Custom location switcher + global scope, NOT
  Filament's built-in tenancy (owner rollup and org-wide member search must cross locations). One
  seeded org now; schema is org-keyed for future multi-org SaaS.
- **Multilingual: Spanish default, English second.** `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`.
  All UI strings via `__()` from day one.
- **Compliance blocks, it does not just document.** Gram/age/carencia/membership checks enforced in
  the same DB transaction as the stock movement. Overrides are permissioned (manager+), reasoned,
  logged.
- **Never cache transactional data** (takings, stock, balances, occupancy, limits — always live).
- **Every threshold is a Setting**, never a constant.
- **Test DB:** SQLite in-memory locally (fast); a MySQL profile (`phpunit.mysql.xml`) for CI because
  production is MySQL and SQLite-only testing hides driver-difference bugs.

## Bootstrap library choices (rationale — the brief left several open with "e.g.")

The build machine has **PHP 8.5, gd (with WebP/PNG/FreeType), no imagick, Redis running, MySQL
running (root, passwordless), Chrome.app present but no CLI chromium/puppeteer**. Libraries were
chosen for robustness in an *unattended* run (no binary that could silently be missing):

- **PDF → `barryvdh/laravel-dompdf` (dompdf), not spatie/laravel-pdf + Browsershot.** dompdf is
  pure-PHP with no external binary; Browsershot needs a headless Chromium that could be missing/
  broken mid-run (the brief itself flags this as the day-one risk). The legal documents (libro de
  socios, actas, Z-reports, entry–exit sheets) are tabular/text — well within dompdf's CSS 2.1
  support. If pixel-perfect HTML/CSS fidelity is later required, Browsershot can be swapped behind
  the same document Actions.
- **QR → `chillerlan/php-qrcode`, not simplesoftwareio/simple-qrcode.** simple-qrcode's PNG output
  needs the **imagick** extension, which is not installed; chillerlan renders PNG via **gd**
  (`QRGdImage`), which is present. Pure-PHP otherwise.
- **Spreadsheet → `openspout/openspout`.** Low-dependency, memory-efficient streaming reader/writer
  for XLSX + CSV — good for large report exports and the member CSV import. A thin
  `App\Support\Spreadsheet` wrapper gives an ergonomic API.
- **Image → `intervention/image` v4 (+ `intervention/image-laravel`) on the gd driver.** Handles
  crop-at-upload, WebP encode, and metadata stripping (re-encode drops EXIF) with gd; no imagick or
  media-library table imposed (ID docs/photos are custom models on a private encrypted disk). Note
  v4 API: `ImageManager::decodePath()` (not v3's `read()`).
- **Web Push → `laravel-notification-channels/webpush` ^11.** Required a `-W` install so composer
  could downgrade `brick/math` 0.18 → 0.17.2 (the webpush → minishlink/web-push →
  web-token/jwt-library chain caps brick/math at ≤0.17; laravel/framework and ramsey/uuid both allow
  0.17, so this is safe).
- **Redis client → `predis/predis` (pure-PHP), `REDIS_CLIENT=predis`.** The **phpredis** C
  extension is not installed; predis needs no extension. Horizon works on predis.
- **Error monitoring → `sentry/sentry-laravel`.**
- **Static analysis → `larastan/larastan` at level 6** (raise later), `--memory-limit=2G` in the
  check script so it won't OOM as Filament resources accumulate.
- **Tests: PHPUnit** (the skeleton ships PHPUnit, not Pest).

---

## Prompt 01 — schema, identifiers, scope, money & weight (architecture checkpoint)

The prompt-01 checkpoint was auto-resolved for the overnight run. Each answer below:

1. **Scope (recommended — adopted).** `organisation_id` on every domain table (one seeded org).
   **Location is the operational scope** via a custom session switcher + a `LocationScope` global
   scope — NOT Filament tenancy, because the owner's "All locations" rollup and org-wide member
   search must cross the boundary. Trade-off: we hand-apply the scope + write denial tests instead
   of getting it free from tenancy.
2. **Identifiers (recommended — adopted).** ULID primary keys on every user-addressable model.
   Internal-only pivots (`location_user`, discount-location enablement) stay auto-increment.
3. **Money & weight (recommended — adopted).** Integer `_cents` + `MoneyCast`; integer `_cg`
   (centigrams, 0.01 g) + `WeightCast`. Percentages stored as integer basis points (`_bp`).
4. `OVERNIGHT-DEFAULT — CONFIRM:` **Wallet shape = per-location balances** (WalletTransaction carries
   `location_id`), carrying forward v1's model: an org-wide debt limit + ring-fencing, with
   cross-location credit transfers recorded as paired TRANSFER_OUT/TRANSFER_IN rows
   (`transfer_pair_id`). Alternative (pooled org-wide balance) was NOT chosen. Confirm this is still
   what the club wants (NOTES §C item 5).
5. **Pricing shape (recommended — adopted).** Price is **per gram, per genetic, per location**, with
   optional **per-tier** prices (`GeneticPrice.tier_id` null = base price). Discounts resolve on top.
6. **Per-location vs org-wide (recommended — adopted).** Org-wide: member *people*, genetic
   *definitions*, membership tiers, standard discounts (templates). Per-location: prices, batches,
   stock, tills, transactions, expenses, staff assignment.
7. `OVERNIGHT-DEFAULT — CONFIRM:` **Business-day cutoff = 06:00**, **timezone = Europe/Madrid** as the
   seeded default per location. The daily gram cap, monthly reset, auto-checkout, entry–exit sheet
   and every Z-report resolve "today" through `BusinessDay` against these. Confirm the real cutoff.

### Seeded threshold defaults (NOTES §A reference table — all editable via Settings in prompt 03)

Seeded as `Setting` defaults, not hardcoded: min age 18, carencia 15 days, daily cap 350 cg (3.5 g),
monthly ceiling 10000 cg (100 g), declared-forecast options 30/50/60/90 g, active-member soft cap
750, premises stock ceiling = active members × daily cap × 5 days (computed live).
`OVERNIGHT-DEFAULT — CONFIRM:` a limit breach **hard-blocks** at the counter, with a **logged
manager override** permitted (NOTES §C item 3 recommended). `OVERNIGHT-DEFAULT — CONFIRM:` currency
display **€1.234,56** (Spanish convention, NOTES §C item 10). `OVERNIGHT-DEFAULT — CONFIRM:` member
data retention default **1825 days (5 years)** after `left_at` before anonymisation (NOTES §C item 13).

### Client-only facts stubbed (grep `OVERNIGHT-PLACEHOLDER`)

- `OVERNIGHT-PLACEHOLDER — CONFIRM:` Organisation legal_name `TBD-LEGAL-NAME`, tax_id (CIF/NIF)
  `TBD-CIF-NIF`, registered address `TBD-ADDRESS`, board/owners — seeded as placeholders.
- `OVERNIGHT-PLACEHOLDER — CONFIRM:` Two premises seeded ("Sede Centro", "Sede Norte") with
  placeholder addresses, aforo (capacity) **50**, opening 12:00 / closing 00:00 — all placeholders.

### Larastan level-6 + relation generics (house style)

PHPStan 2.x (2.2.7 here) **removed** the `checkGenericClassInNonGenericObjectType` toggle, and
`laravel/pao` swallows the resulting config error (0 output, exit 1 — a silent gate failure). So the
generics requirement cannot be turned off cleanly. Rather than suppress it (no baseline / no
ignore comments — kit rule), the project ADDS the generics: relation methods carry
`@return <Relation><Related, $this>` and scopes carry `@param/@return
\Illuminate\Database\Eloquent\Builder<Model>` PHPDoc. This is real type information (better IDE +
inference) and keeps level 6 fully strict. It is the house style for every model from prompt 01 on.

### Opening-balance import path (required note)

The schema supports a real go-live with no free-typed balances: opening stock enters as INTAKE
`StockMovement` rows, opening wallet balances as ADJUSTMENT `WalletTransaction` rows (with a reason),
and an opening till float on `TillSession`. The dev seeder uses these same paths.

---

## Prompt 02 — auth, roles, permissions, PINs, MFA

- **RBAC via spatie/laravel-permission** (guard `web`), roles global for the single org (spatie
  "teams" is the future multi-org switch, off now). Full permission catalogue + the role→permission
  matrix live in `app/Support/Permissions.php`; seeded structurally by `RolePermissionSeeder` in
  every environment. Roles: **OWNER** = all permissions; **MANAGER** = broad per-location operations
  incl. `till.close`, `limits.override`, `dispensation.void`, `membership.fee.override`,
  `settings.manage.location` — but NOT org-wide compliance/privacy (`reports.view.all`,
  `member.limits.set`, `member.discount.assign`, `member.documents.view`, `expenses.overheads`,
  `expenses.categories`, `settings.manage`, `locations.manage`, `staff.manage`, `audit.view`,
  `minutes.manage`, `data.*`); **STAFF** = `pos.use`, `pos.bar`, `checkin.manage`, `members.view`,
  `members.create`, `expenses.record`, `till.open` only.
- **canAccessPanel = active AND has a role.** Refuses no-role and deactivated users with a 403 (a
  distinct failure from bad credentials). Email verification is not required for panel access.
- **MFA = Filament v5 native TOTP** (`AppAuthentication`, optional/`isRequired: false`), recoverable.
  Secret + recovery codes stored encrypted on `users` (`mfa_secret`, `mfa_recovery_codes`).
- **Counter PIN + operator identity:** hashed `users.pin`; `App\Actions\UnlockOperator` verifies a
  PIN against a location's active staff (rate-limited 5/60s), and `App\Support\CounterOperator` holds
  the unlocked operator in the session. Transactions record the unlocked operator, not the device
  user. Counter UIs consume this in prompts 09/11/12.
- **Location switcher:** `App\Support\LocationSwitcher` (logic, tested) + `App\Livewire\LocationSwitcher`
  (topbar). OWNER gets "All locations" (null → LocationScope adds nothing); others only assigned
  locations. Not Filament tenancy.
- **Member-guard seam:** members authenticate on a SEPARATE guard built in prompt 15 (a `Member`
  authenticatable, its own `member` guard + provider). `User` = staff/admin only. Nothing in this
  RBAC touches members, so adding the member guard is additive — no changes to the `web` guard,
  the panel, or these roles. The Filament panel and all policies are staff-only by construction.
- **First Filament resource: `UserResource`** (staff admin) — establishes the v5 resource pattern
  (form in `Schemas/`, table in `Tables/`, gated by `UserPolicy` on `staff.manage`).

---

## Prompt 03 — organisation & location settings

- **Full settings catalogue** in `App\Support\Settings::DEFAULTS` (identity/display, compliance,
  consumption gauge, avalador, wallet/debt, membership, stock, discounts, till, data retention,
  per-location defaults). Everything read through `Settings::get()` (safe default, never throws).
- **Enforcement matrix** (`enforcement` setting, JSON): each check is independently BLOCK/WARN/OVERRIDE
  at the **door** and the **counter** (`Settings::enforcement($surface, $rule)`, fail-safe BLOCK).
  This supersedes the prompt-01 `limit_breach_hard_block` boolean (removed); the manager-override gate
  remains `limit_override_requires_manager`. Consumed by prompts 06/09/11/12.
- **Org settings surface:** `App\Filament\Pages\ManageSettings` (owner-only, `settings.manage`) —
  sectioned form with help text, loads on mount, validates, persists via `Settings::set`, writes an
  `audit_logs` `settings.updated` entry, and notifies. Grams shown at the edge, stored as centigrams.
- **Per-location settings:** `LocationResource` (`settings.manage.location`) with aforo/timezone/cutoff/
  hours/accent + module toggles in the `settings` JSON (bar, signature-on-dispensation, restrict-POS-
  to-checked-in, camera scan) + `aforo_enforcement`. **Expense categories:** `ExpenseCategoryResource`
  (`expenses.categories`).
- **Locale switching:** `SetLocale` middleware applies the session locale (validated against
  `enabled_locales`) on web + panel; `App\Livewire\LocaleSwitcher` in the topbar. `lang/en.json`
  holds the English overrides (Spanish strings are the source keys).
- **Not retroactive:** changing a threshold affects future checks only (Settings is read live at
  check time; already-committed rows/documents are untouched by construction).

---

## Prompt 04 — members, onboarding, avalador, ID & RGPD

- **Consent model:** explicit, versioned, per-purpose. `ConsentRecord` rows captured at approval
  (`ApproveApplication`) with `consent_text_version` (from the `consent_text_version` setting),
  `granted_at` and IP. Tacit consent is not valid; one row per consent per version (history is never
  a scalar). Withdrawal sets `withdrawn_at` on a new row.
- **Article 9 special-category data:** cannabis consumption + medicinal/therapeutic flag are treated
  as health data. `document_number` is encrypted at rest; a deterministic `document_hash` blind index
  enables dedup/uniqueness without exposing the value. ID scans/photos live on the private `documents`
  disk, served only via short-lived signed URLs (`IssueDocumentUrl`, TTL = `signed_url_ttl_seconds`),
  and **every access attempt (allowed or denied) writes a `DocumentAccessLog`**. Only
  `member.documents.view` may open one.
- **Erasure strategy: anonymise-not-delete** (`AnonymiseMember`). Scrubs personal fields + deletes
  ID/photo files, sets `anonymised_at`, revokes tokens — but KEEPS dispensations, wallet and till
  ledger intact and attributed to the anonymised record, so the books stay whole and balanced.
- **Retention:** `data_retention_days` (default 1825 = 5 years after `left_at`). Scheduled
  `members:purge` command (nightly 04:00) anonymises members past retention. Audit-log retention is
  deliberately longer (`audit_retention_days`, 3650).
- **QR card:** `MemberToken` — a random 48-char token (NOT derived from the id), only the SHA-256
  hash stored; `IssueMemberToken` revokes the prior card on regenerate; `ResolveMemberByToken` is the
  scan lookup. Emailed via `MemberCardMail` with the QR embedded inline (CID/data-URI), in the
  render test + `/dev/mail`.
- **Member numbers:** `MemberNumber::next()` — prefix+padding from settings, unique per org, never
  reused (counts soft-deleted). Distinct from the ULID and the QR token.
- **CSV import** (`ImportMembers`): dry-run preview (validate + dedup, no writes) and idempotent real
  import (duplicate guard skips existing), both audited.
- **Seeder:** removed `WithoutModelEvents` from `DatabaseSeeder` so model saving hooks (document_hash
  blind index, tender-split invariant, scope auto-fill) fire during seeding.

---

## Prompt 05 — memberships, fees, carencia & the wallet

- **Wallet model: append-only ledger, derived balance.** `WalletTransaction` is the truth;
  `App\Support\Wallet::balance($member,$location)` sums the ledger (per-location, per prompt 01).
  `balance_after_cents` is computed by the single writer `App\Actions\Wallet\RecordWalletTransaction`,
  never free-typed. `Wallet::totalFloat($org)` is the reported liability figure (prompt 14).
- **Debt policy:** off by default (`wallet_debt_allowed`), capped by `wallet_debt_limit_cents`,
  enforced in the writer — a movement past the cap throws `DebtLimitExceededException` (surfaced as a
  validation/denial, never a hidden button). Explicit permissioned adjustments/transfers may pass
  `allow_debt`.
- **Ring-fencing (carried forward from v1):** `App\Actions\Wallet\TransferCredit` records paired
  TRANSFER_OUT/TRANSFER_IN (linked by `transfer_pair_id`). `AutoSettleDebt` uses new credit at an
  UNFENCED location to clear debt at other UNFENCED locations; a ring-fenced location
  (`location.settings.ring_fenced`) is excluded and settles only by explicit manual transfer.
- **Fee-payment rule:** `MembershipFeePayment` is first-class income, reported separately from
  consumption contributions. A CASH fee attaches to the till session (prompt 10 reconciliation); a
  WALLET fee also posts a FEE ledger movement. Fee override needs `membership.fee.override` and
  records `fee_override_by` + reason (audit).
- **Carencia:** set at approval (`joined_at + carencia_days`); `MemberEligibility::carenciaPassed`
  gates dispensing (not check-in). `WaiveCarencia` is `carencia.waive`-only and logged.
- **Renewals/expiry:** `RenewMembership` extends from the later of today/current expiry.
  `memberships:sweep` (nightly 05:00) flips LAPSED/EXPIRING_SOON and sends renewal reminders,
  idempotent per member/period via the `reminder_sent_for` marker.

---

## Prompt 06 — consumption model, limits & enforcement

- **One resolver:** `App\Actions\Dispensing\ResolveMemberLimits` returns a `LimitSnapshot`
  (daily/monthly limit + used + remaining). Precedence: per-member override → active-membership tier
  (`membership_tiers.daily/monthly_limit_cg`, added this prompt) → location → org (Settings). "Today"/
  "this month" from `BusinessDay`. Used is LIVE from the ledger (COMPLETED only → voids release grams).
  POS/check-in/PWA/dashboard all read this — no duplicated limit arithmetic.
- **Monthly window:** calendar month by default; `monthly_window=rolling30` optional (both tested).
- **Enforcement:** `App\Actions\Dispensing\CommitDispensation` checks membership + carencia + daily +
  monthly INSIDE one DB transaction with the member row `lockForUpdate` (so concurrent tills can't
  jointly breach — exactly one commits). Per-rule mode from the counter enforcement matrix
  (BLOCK/WARN/OVERRIDE). Limit breach hard-blocks; a `limits.override` holder may force it with a
  reason → audited (`dispensation.limit.override`), a first-class report figure (prompt 14).
- **Prices:** CommitDispensation uses the base per-gram GeneticPrice for now; prompt 08 layers
  tier/discount `ResolvePrice` on top.
- **Aggregate-ceiling honesty:** the 100 g/month ceiling is an aggregate across ALL associations a
  member belongs to, which no single club can verify. We record the member's self-declaration
  (consent-style), enforce THIS club's ceiling, and the UI states the aggregate is self-declared —
  we do not pretend to verify what cannot be verified.
- **Concurrency-test note:** the joint-breach test is sequential (proves the live-ledger check);
  true parallel-transaction testing needs integration tooling, but the `lockForUpdate` serialises in
  production.

---

## Prompt 07 — genetics, batches, weight-based stock, merma & the bar catalogue

- **Modelling:** Genetic (org-wide strain definition, priced per gram) → Batch (per-location lot,
  stock in integer centigrams, cost/harvest/expiry/lab) → StockMovement ledger. Articles (bar/food/
  merch) are plain-unit items with a SEPARATE ledger — never collapsed into one products table.
- **One stock writer:** `App\Actions\Stock\RecordStockMovement` — locks the batch/article row FOR
  UPDATE, applies a signed delta, refuses to go negative, appends one movement. `IntakeBatch`
  (grams→cg, opening stock as an INTAKE movement), `CommitStockTake` (variances → ADJUSTMENT
  movements, reconciling to the count). CommitDispensation (prompt 06) now routes its DISPENSE
  through this writer. Nothing else mutates stock columns.
- **Merma** is its own movement type, permissioned (`stock.merma`) with a reason — never hidden
  inside ADJUSTMENT. Stock-take variances are recorded as ADJUSTMENT (the take itself is permissioned/
  audited).
- **FEFO batch selection** (`SelectBatch::fefo`): oldest open, non-expired, in-stock; expired batches
  refused from dispensing. CommitDispensation asserts `isDispensable` per line.
- **Premises stock ceiling** (`StockCeiling::forLocation`): `active_members × daily_limit_cg ×
  stock_ceiling_days`; returns the arithmetic (not a bare number) since day-count sources vary
  (NOTES §A). A compliance signal for the dashboard (prompt 14).
- Stock is per-location; cross-location is refused by the LocationScope (a transfer is an explicit
  permissioned movement pair). Genetics org-wide; batches/stock/prices per-location.

---

## Prompt 08 — pricing, tiers & discounts

- **One resolver:** `App\Actions\Pricing\ResolvePrice` → `App\Support\PriceResult`. Resolution order:
  **tier price (per genetic/location) → best single applicable discount → per-member custom (if it
  saves more)**. Discounts do **not** stack unless `discounts_stack` is on (then percentage discounts
  combine, capped at 100%). Therapeutic members get the therapeutic discount automatically (no
  assignment). The result carries a human reason ("Terapéutico −20%") for the counter/receipt.
- **Frozen snapshot:** CommitDispensation resolves price+discount once and freezes
  `price_per_gram_cents`/`discount_cents`/`line_total_cents` into the `dispensation_lines` row — a
  later price change never rewrites history (tested).
- **Per-member discounts:** `AssignMemberDiscount` (owner-only `member.discount.assign`, audited) —
  a linked standard Discount or an inline custom value with optional expiry. `MemberDiscount.value_cents`
  is a plain int; `Discount.value_cents` uses MoneyCast.
- **UI:** `DiscountResource` (org-wide templates, gated `discounts.manage`, %/€ virtual fields ↔
  bp/cents). Bar-article discounts remain a separate simpler path (prompt 12).
- Rounding: line subtotal = `round_half_up(rate × grams_cg / 100)`; percentage discount =
  `round_half_up(subtotal × value_bp / 10000)` — pinned (€7,49/g × 1.33 g − 17.5% → 996 / 174 / 822).

---

## Prompt 09 — check-in, attendance, aforo & door checks

- **One eligibility resolver:** `App\Actions\Attendance\ResolveMemberEligibility` returns an
  `EligibilityVerdict` (per-rule: membership, age, sanction, carencia, debt, unpaid_fee, +aforo at
  the door), each rule carrying its enforcement mode from the matrix. The door (this prompt) and the
  counter (prompt 11) both call it — no second copy.
- **Door vs counter genuinely differ** (matrix defaults, editable): `door.carencia=WARN` (may enter,
  flagged) but `counter.carencia=BLOCK` (may not be dispensed); `door.debt=WARN` (come in, sit down)
  but `counter.debt=BLOCK` (no product). `door.aforo=BLOCK`. Override at the door needs
  `checkin.override` (distinct from the counter's `limits.override`); always logged.
- **Check-in:** `CheckInMember` runs the door verdict inside a transaction, prevents a double
  concurrent check-in (locks open sessions → returns the existing one), records the PIN-identified
  operator. `CheckOutMember` never lets check-out precede check-in. Occupancy is always live.
- **Auto-checkout:** `checkins:auto-checkout` (nightly 06:00) closes open sessions with
  `auto_checked_out=true`; idempotent. Entry–exit sheet (`EntryExitSheet`) + footfall-by-hour×weekday
  (`Footfall`) for the dashboard/heatmap (prompt 14).
- **Counter-app pattern:** the check-in screen is a Livewire full-page component on `/counter/checkin`
  (web+auth, outside the Filament panel) with a reusable `layouts.counter` tablet layout — the shape
  the dispensary/bar POS reuse (prompts 11/12).
- `OVERNIGHT-DEFAULT — CONFIRM:` **Visual screenshots of the counter screens were NOT captured** — the
  unattended run has no Playwright MCP connected. The UI is built to the tablet-first constraints and
  reuses the shared layout/components; a human should run the Playwright screenshot pass
  (1440/1280/1024/390, light+dark, motion reduced+allowed) before go-live, per the kit's UI rule.

---

## Prompt 10 — till sessions, cash, arqueo & cierre de turno

- **Expected drawer cash is derived, never stored** (`App\Support\TillSummary`): float + cash
  contributions (dispensation.cash_cents COMPLETED) + bar cash (order.cash_cents) + cash top-ups +
  cash fee payments + cash movements (signed) − refunds. **Wallet contributions/payments are shown
  but EXCLUDED** — only cash counts toward the drawer. Voided transactions are excluded, so a void
  adjusts expected automatically. (Pinned: €200 float + €150 cash + €30 bar − €25 petty − €100 banked
  = €255, excluding €40 wallet.)
- **One open session per terminal per location** (`OpenTill`, locked); a second open is refused. Cash
  movements stored SIGNED (IN +, OUT/BANKED/PETTY −). `CommitDispensation` refuses to attach to a
  missing/closed session.
- **Blind arqueo** (`CloseTill`, `till.close` manager+): the counted figure is submitted BEFORE the
  expected is computed/revealed; the UI keeps `expected` out of the payload until the count is
  submitted (tested). `OVERNIGHT-DEFAULT — CONFIRM:` **arqueo variance tolerance = €5.00**
  (`arqueo_variance_tolerance_cents = 500`); a variance beyond it requires a note. A closed session is
  immutable (no reopen; corrections are new linked entries).
- **Z-report** (`App\Support\ZReport`): full breakdown + counted/variance/tx-count/voids/operator;
  feeds the dashboard + financial reports (prompt 14). Oversight via a read-only `TillSessionResource`.
- `OVERNIGHT-DEFAULT — CONFIRM:` counter-screen visual screenshots not captured (no Playwright MCP) —
  human screenshot pass before go-live.

---

## Overnight bugfix — business-day window timezone (surfaced during the prompt-10 merge)

- The full suite went red on `main` right after the prompt-10 merge — on a **pre-existing** bug, not
  prompt 10. `BusinessDay::window()` built the day boundaries in the LOCATION timezone (Europe/Madrid)
  while `dispensed_at` / `checked_in_at` are stored in the APP timezone (UTC). A `whereBetween`
  string-compares wall-clock times WITHOUT converting, so for the ~2h after the cutoff's UTC instant
  (06:00 Madrid = 04:00 UTC) the day's own rows fell outside "today": the **daily/monthly gram cap
  silently stopped enforcing** and the entry–exit sheet came up empty. Masked until now because it
  only manifests in that wall-clock window — the clock rolled into it overnight, which is exactly why
  the time-sensitive tests caught it.
- **Fix:** `BusinessDay::window()` and `ResolveMemberLimits::monthWindow()` now return their bounds in
  the app (storage) timezone (instant-preserving `setTimezone`), so `whereBetween` compares
  like-for-like. Pinned with a frozen-clock regression test (`04:30 UTC`) proving the cap still bites
  in the danger window; `BusinessDayTest` updated to assert the storage-tz contract (and that the
  instant is still the 06:00 LOCAL cutoff).
- Compliance-critical (the cap is a legal defence). Merged to main under the overnight self-merge
  authorisation. Worth a human sanity-check that `APP_TIMEZONE=UTC` stays the deployment assumption.

---

## Prompt 11 — dispensary POS

- **Thin shell over the domain Actions.** `App\Livewire\Counter\DispensaryPos` (route `/counter/pos`,
  gated on `pos.use`) resolves a socio, builds a basket and calls `CommitDispensation` — THE
  compliance boundary. It never touches stock/money/limits/pricing itself; prices resolve live
  through `ResolvePrice`, batches through `SelectBatch` (FEFO, overridable), eligibility through the
  SAME `ResolveMemberEligibility` the door uses (surface `counter`), the gauge through
  `ResolveMemberLimits`. Every figure is live-queried on render (transactional data is never cached).
- **No cannabis line without a socio.** The commit method returns before any write unless a member is
  held and not hard-blocked; the button is disabled until then. Asserted directly in the screen tests.
- **Weight vs calculator.** Weight is entered in grams (2 dp) on a numeric pad and stored as integer
  centigrams. Calculator mode takes euros and back-solves grams from the per-gram rate, **floored to
  0.01 g via integer division** (`intdiv(cents × 100, rate_cents)`), then re-prices the line from
  those grams — grams stay authoritative and the typed euros are never stored as the total.
- **Override.** A daily/monthly limit breach is surfaced by `CommitDispensation` (caught) and offered
  as a `limits.override`, reasoned, audited override; OVERRIDE-mode eligibility rules are offered the
  same way. BLOCK-mode failures hard-stop with no override. Default matrix is BLOCK for every counter
  rule, so in practice the override path is the limit breach.
- **Idempotency.** One `Str::ulid()` key per basket, passed to `CommitDispensation` (which no-ops a
  repeat) and reset only after success/clear; the commit button disables on submit — a double-tap
  cannot double-commit.
- **Dispensary POS fails closed offline** — limits/stock/balances are live-query by mandate, so an
  offline commit cannot be trusted; basket preserved, commit blocked until reconnected.
- **Receipt = a CONTRIBUTION.** `resources/views/receipts/receipt.blade.php` (route
  `counter.pos.receipt`, ULID-keyed, `DispensationReceiptController`) is worded as an *aportación /
  contribución de costes compartidos* — never *venta / cliente / precio de venta*. Authorization runs
  through `DispensationPolicy@view` (permission + same org + own-location or org-wide reports); the
  ULID lookup lifts global scopes so a just-committed ticket resolves across a location switch, but the
  policy is the real gate. `dispensation.void` from the screen calls `VoidDispensation` (manager+).
- `OVERNIGHT-DEFAULT — CONFIRM:` two new POS settings, now added to `Settings::DEFAULTS` with a safe
  default of **off**: `pos_require_checked_in` (restrict dispensing to socios currently checked in) and
  `pos_signature_required` (capture an on-screen signature to the private `documents` disk and pass
  `signature_path`). Confirm the real club policy for each.
- `OVERNIGHT-DEFAULT — CONFIRM:` counter-screen visual screenshots not captured (no Playwright MCP) —
  human screenshot pass (1440/1280/1024/390, light+dark, motion reduced+allowed) before go-live.

---

## Prompt 12 — bar / merch POS (separate catalogue, separate ledger)

- **Separate ledger, one drawer.** Bar/merch sales are `App\Models\Order` rows (own `items` snapshot,
  own status), committed by `App\Actions\Bar\CommitOrder` — never `Dispensation`. They share ONE open
  `TillSession` with the dispensary so the arqueo covers the whole drawer, but bar cash lands in
  `TillSummary`'s `bar_cash` and never in `cash_contributions` (tested). Stock moves as `SALE` on
  `Article` units; cannabis moves as `DISPENSE` on batch centigrams — the two never mix.
- **A genetic can never appear on a bar order.** A catalogue line must resolve to an `Article` at the
  location; a genetic id does not, so `CommitOrder` refuses it (tested at the model boundary). The bar
  grid only ever lists articles.
- **Member optional.** Cash orders may be unattributed (guest/rollout) with an optional free-text
  reference; a wallet payment REQUIRES an attached member (enforced). Miscellaneous/quick-amount lines
  require a reference and move no stock.
- **Wallet spend type.** A bar wallet payment records a new `WalletTransactionType::PURCHASE` — never
  `CONTRIBUTION` (reserved for the cannabis aportación) — so wallet-ledger reporting keeps bar spend
  distinct from contributions.
- `OVERNIGHT-DEFAULT — CONFIRM:` **bar articles use a single flat `price_cents`** — no per-membership-
  tier bar pricing is modelled (the prompt floats "tier pricing" for members, but `Article` carries one
  price and there is no article-tier table). If the club wants tiered bar prices, that is a later schema
  addition; flat pricing is the default.
- **Void** = `App\Actions\Bar\VoidOrder` (order.void, manager+): returns units, refunds the wallet
  (off-till — a wallet credit is not a drawer movement), cash releases via the COMPLETED-only till
  arithmetic. Never a silent edit; a correction is a void plus a fresh linked order.
- Bar receipt is worded as a normal **venta / ticket** — deliberately distinct vocabulary from the
  cannabis **aportación / contribución** receipt. UI gated by `App\Policies\OrderPolicy` (pos.bar to use,
  order.void to void); receipt at a ULID route.

---

## Prompt 13 — expenses, purchases & suppliers

- **The classic conflation, kept apart.** Till **petty cash** (`RecordTillExpense`, expenses.record)
  posts a `PETTY_CASH` cash movement so it hits the drawer reconciliation (TillSummary / Z-report) —
  otherwise the drawer looks over. **Overheads** (`RecordOverhead`, expenses.overheads — owner/
  treasurer only) NEVER set a `till_session_id` and NEVER move cash, but still count as period
  outgoings. Both facts are explicitly tested (the overhead-touches-the-till case is the one that
  gets wired wrong).
- **Approval** is a recorded action (`ApproveExpense`, expenses.approve) with approver + timestamp,
  never a silent flip. `Expense::requiresApproval()` reads `expense_approval_threshold_cents`
  (default €100) via the Settings accessor: above it an unapproved expense still needs approval,
  below it none is required.
- **Recurring overheads** are `Expense` rows with a `recurrence` array (a TEMPLATE, excluded from
  outgoings by `scopeConcrete`). `expenses:materialise-recurring` (scheduled daily 05:30) creates the
  concrete expense for the current period and is **idempotent** via the unique
  `recurring_expense_runs(template, period_key)` marker — a double-fire never double-charges.
- **Purchases → stock valuation.** `RecordPurchase` links a cannabis purchase to its batch intake and
  writes `cost_per_gram_cents = round_half_up(amount_cents × 100 ÷ grams_cg)` onto the batch, so the
  purchase-vs-withdrawal reconciliation (prompt 14) uses a real cost, not a guess. Supplier balance
  owing = Σ(amount − paid) is a reported figure.
- **Schema:** added nullable `note` (the petty-cash/overhead reason) and `supplier_id` (overheads may
  name a supplier) to `expenses` — additive migration. **MySQL parity RESOLVED:** the full suite
  (207 tests) was run green on MySQL (`phpunit.mysql.xml`) this prompt — the first full MySQL run of
  the build, so every prior migration (prompts 00–12) is now confirmed on the production driver too,
  not just SQLite. Supersedes the earlier "MySQL parity deferred" note.
- **Receipts/invoices on the PRIVATE `documents` disk**, never the public one (security requirement).
- **Staff-payment expenses** get their own category and UI help text stating that *recording is not
  discharging* the real PAYE/governance obligation.
- `OVERNIGHT-DEFAULT — CONFIRM:` seeded default expense categories — Stock, Consumables (TILL), Staff
  payment, Repairs & maintenance, Rent, Utilities, Other (OVERHEAD hints). Confirm the club's real
  category list. Recurrence frequencies offered: MONTHLY / QUARTERLY / YEARLY.

---

## Prompt 14 — dashboard, navigation & reports

- **Aggregation is a tested ViewModel, not widget code.** `App\ViewModels\Dashboard` (+ `DashboardCharts`)
  computes every figure as a LIVE, org+location+period-scoped SQL aggregate (transactional data is never
  cached); the Filament widgets are a thin render. Each stat figure has a control-query test — a
  plausible-but-wrong dashboard number is the worst bug. `App\Support\Period` (today/week/month/custom +
  previous-equivalent for deltas) is expressed in the app timezone, same normalisation as the
  BusinessDay fix, so whereBetween matches stored timestamps.
- **Dashboard composition:** one period toggle drives all widgets; a wide main column (8 delta+sparkline
  stat cards → charts → top/recent tables) + a right rail (grouped Finanzas/Socios/Dispensario readouts
  + a severity-coded alerts panel). Charts: income-by-type (Aportaciones/Barra/Cuotas never merged),
  income-vs-expenses with a superávit line, dispensed-by-genetic, consumption distribution, stock levels,
  and a CSS footfall heatmap (Chart.js has no native heatmap).
- **Role-view differences:** OWNER = everything + org rollup (no active location) + per-location
  comparison; MANAGER = their location only, no rollup; STAFF = operational only (who's inside, aforo
  ring, grams dispensed, tx count, active members) with NO finance figures — euro columns and finance
  cards are withheld (`canSeeFinance` gate + the required "staff sees no finance" test), and the open
  till is surfaced via the alerts rail rather than a takings figure.
- **Navigation:** grouped, permission-filtered (Resumen · Socios · Dispensario · Barra · Caja · Informes
  · Sistema). Items register ONLY when their resource/page exists — Documentos (16) and Audit log (17)
  are omitted for now (a shorter sidebar beats dead links); the member-PWA routing seam (15) is a
  commented hook, not faked.
- **Exports:** CSV via `league/csv`, PDF via `barryvdh/laravel-dompdf`; "Excel" is a CSV that opens in
  Excel unless PhpSpreadsheet is added. Exported totals share the report's own total method (never
  re-derived) so they equal the on-screen totals to the cent.
- `OVERNIGHT-DEFAULT — CONFIRM:` the delta+sparkline-per-card design costs ~85 bounded (non-N+1) queries
  per dashboard load; acceptable and tested, but a later optimisation could batch the per-card
  current/previous/trend aggregates into fewer round-trips if the page feels heavy in production.
- `OVERNIGHT-DEFAULT — CONFIRM:` dashboard + report visual pass (Playwright screenshots at
  1440/1280/1024/390 + short height, light+dark, motion reduced+allowed) NOT captured (no Playwright
  MCP) — a human must look before go-live; this is the prompt that most needs it.

---

## Prompt 15 — member PWA & club communications

- **Second guard.** A separate `member` guard + `members` provider (config/auth.php); `Member` is now
  `Authenticatable`. Staff (`web`) and socios (`member`) NEVER share a session or a panel — a guest on
  `/socio*` redirects to the member login, everyone else to the Filament panel (`redirectGuestsTo` in
  bootstrap/app.php). This realises the clean seam left in prompts 02–03.
- `OVERNIGHT-DEFAULT — CONFIRM:` **auth method = passwordless magic link by email** (not OTP). A random
  64-char token, only its SHA-256 hash stored, single-use (`used_at`, consumed in a locked transaction),
  short-lived (`member_login_link_ttl_minutes`, default 15), and the request endpoint is rate-limited
  (`throttle:5,1`). The response never reveals whether an email is a member. Sessions are long-lived on a
  trusted device via remember-me (added `remember_token` to `members`). Chosen over OTP for lower
  friction and no shared-secret entry; confirm if the club prefers a numeric code.
- **Everything is scoped to the authenticated socio** — there is NO member id in any member URL, so one
  member can never reach another's card, allowance, wallet, history or export (a structural guarantee,
  denial-tested). Limits/prices/balances come from the SAME resolvers the counter uses (no second
  arithmetic). The QR card reuses the prompt-04 `MemberToken` (issuing rotates + revokes the previous
  card, so the emailed and PWA cards are one active token).
- Read-mostly this phase: a member views and identifies; reservations/top-ups are prompt 18.
- `MemberLoginLinkMail` joined the mail inventory; `MailRenderTest` now renders under a production
  `app.url` so a legitimate absolute app link (the magic link) passes while a hard-coded dev host still
  trips the guard.
- `OVERNIGHT-DEFAULT — CONFIRM:` PWA visual pass (390 + 1024, light + dark) and real installability on
  iOS/Android not captured (no Playwright) — human check before go-live.

- **Communications (both sides) + Web Push** shipped: Filament `Comunicaciones` (Announcement/Event +
  RSVP, `comms.manage` owner/manager), member Avisos/Eventos feeds, and queued webpush notifications
  with a member-controlled **per-channel opt-out** (`push_opt_outs` JSON on members; channels
  low_balance / membership_expiring / new_announcement / event_reminder). VAPID private key stays
  server-side (config/webpush.php); only the public key reaches the client (asserted).
- `OVERNIGHT-DEFAULT — CONFIRM:` three push notifications (low_balance, membership_expiring,
  event_reminder) are built, tested and opt-out-gated but NOT yet wired to their triggers (the wallet
  writer, `memberships:sweep`, an event-reminder scheduler) — one dispatch line each; `new_announcement`
  IS wired. Revisit alongside the prompt-17 operational-monitoring work ("the failure mode is silence").
- `OVERNIGHT-PLACEHOLDER — CONFIRM:` real VAPID keys must be generated (`php artisan webpush:vapid`) and
  set as VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT before push can send.

---

## Prompt 16 — legal documents: libro de socios, actas & generated forms

- **The books generate themselves from the data.** `App\Support\MembersRegister::asAt($orgId, $date)`
  is the statutory libro de socios for any point in time — it KEEPS departed members (with leave
  dates) and excludes later joiners (filter `joined_at <= date`), because a register that drops
  leavers is not a register.
- **Actas** (`App\Actions\Documents\{CreateMinute,SignMinute}`): numbering is SEQUENTIAL per
  (organisation, book) with no gaps — the next number is taken under a row lock and the
  unique(organisation, book, number) index is the concurrency backstop (a losing racer retries).
  **Quorum is computed against members active AT the meeting date** (`minute_quorum_fraction_bp`,
  default 5000 = 50%), never today's roll. A signed acta is IMMUTABLE (`Minute::booted` refuses
  update/delete once `signed_at` is set); a correction is a NEW minute linked by `supersedes_id`.
  Granted MANAGER `minutes.manage` so actas are owner/manager per the prompt.
- **Generated documents** (`GenerateMemberDocument`): the rendered PDF is written to the PRIVATE
  `documents` disk and the row carries a FROZEN `snapshot` (name, doc number, consent version in
  force, template version) — later edits to the member or the template never change an issued
  document; regeneration produces a NEW version. Added a nullable `snapshot` JSON to
  `member_documents`.
- **Accounting export** (`App\Support\Spreadsheet\AccountingExport`) is DERIVED from the same
  `FinancialReport` shown on screen, so its totals reconcile to the cent by construction (the
  *libros contables* obligation is met by the club's accountant, not reinvented in-app).
- Documents are served ONLY via short-lived signed URLs and every view is access-logged
  (`IssueDocumentUrl` → `DocumentAccessLog`). Nothing here is legal advice — a plain note says so.
- `OVERNIGHT-DEFAULT — CONFIRM:` quorum fraction 50%; document letterhead/wording uses the default
  template text until the club supplies its own (editable per-org versioned templates).

---

## Prompt 17 — audit log, RGPD tooling & security hardening

- **Retention periods:** member data `data_retention_days` = 1825 (5 years after leaving); audit entries
  `audit_retention_days` = 3650 (10 years) — deliberately LONGER than member data, so the evidence of
  what was done outlives the personal data itself.
- **Anonymisation strategy (Art. 17 erasure):** anonymise-not-delete (`App\Actions\Members\AnonymiseMember`).
  Scrubs the personal fields (name/email/phone/address/DOB/document number), deletes the ID scan + photo
  from the private disk, revokes QR-card tokens, stamps `anonymised_at`, and audits — but KEEPS the
  financial + consumption ledger (dispensations/wallet/till) attributed to the anonymised record, so the
  books balance to the cent after an erasure (proven: totals identical before/after). Cannabis
  consumption + therapeutic status are Article 9 special-category data (flagged in the RAT + DPIA note).
- **Retention purge:** `members:purge` (scheduled) anonymises members whose `left_at` is past the window;
  has a `--dry-run` that writes nothing and is idempotent across runs (proven).
- **IDOR guard:** every user-addressable model is ULID-keyed (never auto-increment) and no registered
  route exposes a bare numeric id segment — both asserted by a route-list/model walk. This is the exact
  failure a market competitor made (≈1M records + ID scans via sequential-id IDOR, NOTES §B).
- **Audit log is append-only** — the model throws on update/delete (proven); wired through
  `RecordAuditLog` across consequential actions in every prompt.
- `OVERNIGHT-PLACEHOLDER — CONFIRM:` breach runbook location = `docs/BREACH-RUNBOOK.md` (TBD — the
  BreachLog links to it; the club must author the real 72-hour AEPD notification procedure).
- `OVERNIGHT-DEFAULT — CONFIRM:` `composer audit` is reported by CI but NOT added to the blocking
  `composer check` gate (an upstream advisory must not block unrelated commits); operational monitoring
  (scheduler/queue heartbeats, health panel, dead-letter view) added — real backups + a tested restore
  are an ops task for go-live (documented in SETUP.md).

---

## Prompt 19 — localization: English default, per-user override

- **Architecture (chosen deliberately, records the trade-off):** the ~1,900 `__()` call sites across
  this build use the **Spanish source string as the key** with English overrides in `lang/en.json`.
  Rather than rewrite every call site to English keys (huge, high error-surface), the default is
  flipped to `en` by **completing `lang/en.json`** (every used key → English) and adding an identity
  **`lang/es.json`** (every used key → itself, Spanish) so the two files have full key parity. A key
  missing from `en.json` would leak Spanish into the English UI — forbidden and caught by the
  completeness test. Spanish is never reduced; it stays first-class.
- **Default flipped to `en`** (`APP_LOCALE` / `APP_FALLBACK_LOCALE` in config + .env + .env.example).
  A fresh install with no org override and no user preference renders English.
- **Per-user preference:** nullable `users.locale` (null = follow org). A topbar `LocaleSwitcher`
  persists it to the user row AND mirrors to the session, so the change shows on the next request with
  no re-login.
- **One resolver:** `App\Actions\ResolveLocale` — **per-user preference → organisation default
  (`default_locale`, new Setting, = `en`) → system default `en`** — applied in `SetLocale` middleware.
  Only an enabled locale is honoured; a stale value degrades to the next level, never throws.
- **Automated coverage gate:** `App\Support\LangKeys` scans the code for `__()`/`@lang()` keys;
  `php artisan lang:sync` regenerates `es.json`; `tests/Feature/Localization/LocalizationTest` (in the
  `composer check` suite) asserts en/es key parity, that every used key exists in both, the resolution
  order, and that **every backed enum exposes a translated `label()`** (never a raw value). Drift now
  fails the gate automatically.

### Canonical EN↔ES glossary (one source of truth — same concept, same translation everywhere)

| Español | English | NOT (commercial framing) |
|---|---|---|
| Socio / Socios | Member / Members | ~~Customer / Client~~ |
| Aportación / Contribución | Contribution | ~~Sale / Payment~~ |
| Dispensación | Dispensing / Dispensation | ~~Purchase~~ |
| Superávit | Surplus | ~~Profit~~ |
| Carencia | Waiting period | — |
| Aforo | Capacity | — |
| Arqueo | Cash count | — |
| Cierre de turno | Till close / Shift close | — |
| Cuota | Membership fee | — |
| Merma | Wastage / Shrinkage | — |
| Aval / Avalador | Sponsorship / Sponsor | — |
| Caja | Till / Cash drawer | — |
| Flor | Flower | — |
| Extracto / Hachís | Concentrate / Hash | — |
| Comestible | Edible | — |
| Preliado (porro) | Preroll | — |

Bar/merch wording is the deliberate exception: *venta/ticket* → *Sale/Receipt* (the bar genuinely
sells refreshments). Everything cannabis-side stays contribution-framed in both languages.

- `OVERNIGHT-DEFAULT — CONFIRM:` **statutory documents currently follow the UI locale.** Flipping the
  default to English means an English-preferring owner would generate the libro de socios, actas,
  accounting export and RAT in English. These are Spanish legal filings (handed to a lawyer, the
  assembly, the AEPD or a court) and should almost certainly render in a **fixed document locale
  (Spanish), independent of the staff member's UI language** — a `document_locale` setting (default
  `es`) wrapping the PDF/CSV renderers. NOT done here (it spans the prompt-16/17 legal module and is a
  product decision); flagged for a focused follow-up. The localization tests pin `es` where they assert
  Spanish-specific output, so this is documented, not hidden.
- Five pre-existing tests were adjusted for the es→en flip (locale-aware `Money`/`Weight::formatted()`
  — es comma vs en dot — plus Spanish document copy and the middleware default): `es` pinned where the
  test asserts Spanish output, and the SetLocale default assertion updated es→en. No production logic
  changed by those edits.

---

## Prompt 20 — admin form completeness (member form + systematic audit)

The member create/edit form was missing five things; all fixed on BOTH create and edit, grouped where
the owner expects them (never bolted on the end). Every new label/helper/validation string shipped in
`lang/en.json` + `lang/es.json` together (`lang:sync --check` green, key order identical).

1. **ID document scan** (`document_scan_path`) — a `FileUpload` in the **Identificación** section on the
   PRIVATE `documents` disk (`->disk('documents')->visibility('private')->directory('member-id-scans')`,
   pdf/image, `->previewable(false)` so the private-disk file never emits an un-logged temporary URL). It
   is NOT viewable like the portrait: a `->hintAction('Ver documento')` routes through `IssueDocumentUrl`
   (short-lived signed URL + `DocumentAccessLog`, 403 without `member.documents.view`). On save the page
   mirrors the path into a `MemberDocument` (type ID) via `SyncMemberScanDocuments`, so the existing
   signed-URL machinery serves it.
2. **Monthly forecast in grams** — `declared_monthly_cg` now labelled "Previsión mensual (g)", entered in
   grams (2 dp) and converted at the edge (`formatStateUsing` = `state/100`; `dehydrateStateUsing` =
   `Weight::fromGrams()->centigrams`). `50.00 g → 5000 cg`, round-trips to `50.00`. Infolist display also
   switched to grams.
3. **RGPD consent** — a required (`->accepted()`) consent checkbox referencing `consent_text_version`, in a
   new **Declaraciones** section by the submit buttons; on create the page writes a real `ConsentRecord`
   (`App\Actions\Members\RecordMemberConsent`, purpose `membership`) — not a flag. The invite path
   (`ApproveApplication`) already writes one; not duplicated.
4. **Therapeutic toggle is `->live()`** — ON reveals a **medical certificate** `FileUpload` (private disk,
   same signed-URL + access-log treatment; new nullable `medical_cert_path` column, mirrored to a
   `MemberDocument` type MEDICAL) and makes the avalador optional (`avalador_therapeutic_exempt`). OFF
   enforces the avalador per `avalador_policy`: `required` → required; `waivable` → required unless the
   actor holds `carencia.waive`; `not_required` → optional. Reactivity via `Get`.
5. **Sole-association declaration** — a checkbox in Declaraciones stamping `sole_association_declared_at`
   (keeps the ORIGINAL date on re-save, stamps `now()` the first time). Added to the admin RGPD export
   (`ExportMemberData`) and the member PWA export (`PwaController@export`).

**Create flow was actually broken before this prompt** and is now fixed: the admin CreateMember page never
generated `member_no` (NOT NULL) — members could only be created via the application/import paths.
`CreateMember::mutateFormDataBeforeCreate` now system-sets `member_no` (`MemberNumber::next`), `status`
(ACTIVE — a staff-created walk-in is active, mirrors `ApproveApplication`), `joined_at`, and
`carencia_ends_at`. These stay system-managed, never form fields.

**Exclusion list confirmed** — `member_no`, `carencia_ends_at`, `status`, `document_hash`, `anonymised_at`,
`joined_at`/`left_at` are NOT editable on the form (verified by a denial test + the completeness allowlist).

### Raw `_cg` / `_cents` entered-directly audit (fixed everywhere, not just the member form)

Only two surfaces entered a raw minor unit into an input (tables/infolists that merely DISPLAY casts are
fine):
- **`MemberForm.declared_monthly_cg`** — fixed to grams (item 2).
- **`ManageSettings`** three cents settings (`wallet_debt_limit_cents`, `arqueo_variance_tolerance_cents`,
  `expense_approval_threshold_cents`) were typed directly in *céntimos*. Fixed to euros-at-the-edge
  (`*_eur` virtual fields + convert in `save()`/`currentValues()`), mirroring the grams pattern the page
  already used for `daily/monthly_limit`.

Also closed a genuine gap surfaced by the audit: **`MembershipTier` per-tier `daily_limit_cg`/
`monthly_limit_cg`** had no form field anywhere — added as grams-at-edge (`daily_limit_g`/`monthly_limit_g`,
nullable overrides) with page conversion.

### Systematic form-completeness audit + repeatable gate

`tests/Feature/Forms/FormCompletenessTest.php` diffs every Filament resource's create/edit form fields
against the model's `$fillable` (a Livewire stub container + recursive field walk — no per-resource page
mount). Every fillable field must be PRESENT in the form OR in a documented per-resource allowlist with a
reason; a future column that forgets its form field fails CI. A second test keeps the allowlist honest (no
stale/typo entries, none actually present in the form, every entry has a reason). `organisation_id` is
excluded globally (scope-filled). Zero unexplained gaps.

Per-resource checklist (fillable fields absent from the form → why; everything else is IN the form):
- **Member** — added: document_scan_path, medical_cert_path, declared_monthly_cg (g), consent, sole-assoc.
  Excluded: member_no/status/joined_at/left_at/carencia_ends_at/document_hash/anonymised_at (system/
  lifecycle), daily/monthly_limit_cg (per-member override via `member.limits.set`), push_opt_outs (PWA
  self-service).
- **MembershipTier** — added daily/monthly_limit_g. Excluded default_fee_cents (→ default_fee_eur),
  daily/monthly_limit_cg (→ _g).
- **Announcement** — author_id (system: authenticated author).
- **Article** — location_id (scope); price_cents (→ price_eur).
- **Batch** — location_id (scope); batch_no (generated); initial_cg (→ grams via IntakeBatch); remaining_cg
  (ledger-computed); cost_per_gram_cents (→ cost_per_gram_eur); status (lifecycle).
- **BreachLog / Event / ExpenseCategory / Supplier** — nothing beyond `organisation_id`.
- **DataRequest** — completed_at, handled_by (set on fulfilment).
- **Discount** — value_bp (→ value_pct); value_cents (→ value_eur).
- **DocumentTemplate** — version (auto-incremented per version).
- **Expense** — amount_cents (→ amount_eur); kind (derived); till_session_id (petty-cash till);
  recurrence (from recurrence_frequency); recorded_by/approved_by/approved_at (actions).
- **Genetic** — thc_bp/cbd_bp (→ thc_pct/cbd_pct).
- **Location** — `settings` JSON is edited via its expanded `settings.*` keys (covered by the nested-key
  matcher, not the allowlist).
- **MemberApplication** — invite_token_hash/payload/reviewed_by/reviewed_at/resulting_member_id (invite +
  review actions).
- **Minute** — number (sequential under lock); quorum_present/quorum_required (computed); signed_at
  (SignMinute).
- **Purchase** — amount_cents/paid_cents (→ _eur); batch_id (linked by RecordPurchase).
- **User** — locale (per-user self-service preference; null follows the org).
- **AuditLog / MemberDocument / TillSession** — read-only oversight resources, no create/edit form by
  design (documented in the test's FORMLESS set; the test asserts they still have no create page).

### Judgment calls

- **Admin-created members default to ACTIVE** (not APPLICANT) — a staff/counter walk-in registration is a
  real member, mirroring `ApproveApplication`. If the club wants admin-created members to enter the
  approval queue instead, flip the `status` default in `CreateMember`.
- **Medical certificate is revealed but not hard-required** when therapeutic — a legitimate cert may arrive
  after onboarding; requiring it would block valid creates. Tighten to `->required()` if the club mandates
  it up front.
- **Sole-association re-save keeps the original date** (only stamps `now()` when previously null), so the
  legal declaration date isn't overwritten by an unrelated later edit.
- **`MembershipTier` limit fields added** rather than documented-as-excluded, because there was no other UI
  to set the per-tier overrides the pricing/limits resolver already reads (a real gap, not owner-managed
  elsewhere).

---

## Prompt 21 — cannabis product types (concentrates, edibles, prerolls beyond flower)

- **`product_type` → derived, stored `unit_type`; everything downstream branches on `unit_type`, never
  `product_type`** (two paths, not four). FLOWER + CONCENTRATE → WEIGHT (grams/centigrams, unchanged);
  PREROLL + EDIBLE → UNIT (whole units). `unit_type` is set ONLY by `App\Observers\GeneticObserver`
  (`#[ObservedBy]`), never `$fillable`, never user-entered — mirrors the qty_cg/qty_units precedent.
  Concentrates are ONE top-level type with an optional descriptive `concentrate_subtype`
  (hash/rosin/shatter/wax/live resin), NOT four separate types.
- **`grams_cg` stays THE figure every limit / ceiling / dashboard / report reads.** For a UNIT line it is a
  COMPUTED, STORED value (`units_dispensed × genetic.grams_per_unit_cg`) written at commit. Only what FEEDS
  grams_cg changed: `ResolveMemberLimits`, `StockCeiling`, the daily/monthly ceiling and every report needed
  ZERO arithmetic change. A dedicated test asserts `ResolveMemberLimits` references none of
  units/UnitType/product_type/grams_per_unit — the load-bearing compatibility guarantee, proven not just
  claimed.
- **CORRECTED StockMovement quantity rule (supersedes the prompt-07 convention).** The old rule — "a Batch
  writes `qty_cg`, an Article writes `qty_units`" — is now WRONG and a trap for later copiers. The quantity
  column keys off the **stockable's `unit_type`**, not batch-vs-article: a WEIGHT-type Batch decrements
  `remaining_cg` and writes `qty_cg`; a **UNIT-type Batch (preroll/edible) decrements `remaining_units` and
  writes `qty_units`**; an Article stays units. So a UNIT Batch and an Article share the units path; a WEIGHT
  Batch is the only cg path. `RecordStockMovement` (THE single writer) carries this corrected rule in its
  docblock; a batch-linked movement writing `qty_units` is pinned by test.
- **One-of-two, enforced at the model layer, not by convention.** Exactly one of each column pair is
  populated per row — `price_per_gram/unit_cents`, `initial_cg/units`, `remaining_cg/units`, `qty_cg/units` —
  validated by a `saving` guard that throws (GeneticPrice, Batch, StockMovement). To let a UNIT row leave the
  cg/per-gram side null, the additive migration **relaxes `genetic_prices.price_per_gram_cents`,
  `batches.initial_cg/remaining_cg` and `dispensation_lines.price_per_gram_cents` to nullable** (no existing
  value altered; every existing FLOWER row keeps its non-null cg/per-gram). NOTE the guards read the genetic's
  `unit_type` via `->first(['unit_type'])->unit_type` (an Eloquent `value()` returns the CAST enum, so compare
  to `UnitType::UNIT`, never the string).
- **Additive, never a rewrite.** New migration only (`2026_07_31_000001_add_product_types_to_catalogue`);
  prompt-01 migrations untouched. `product_type` defaults to FLOWER and `unit_type` to WEIGHT, so existing
  rows backfill to the flower/weight shape by the column default (proven: a row inserted without the new
  columns reads FLOWER/WEIGHT; existing weight batches/prices/lines keep their exact values and null unit
  columns). Full Feature suite green on **MySQL** too (production driver) since a migration was added.
- **One resolver, one boundary, one writer — reused, not duplicated.** `ResolvePrice` branches on `unit_type`
  to read `price_per_gram_cents` OR `price_per_unit_cents` (same tier logic, different column) and returns
  `PriceResult` with a `perUnit` flag + `lineForUnits()`. `CommitDispensation` normalises every line to a
  stored `grams_cg` up front (computed for UNIT), then freezes `units_dispensed` + `price_per_unit_cents` and
  routes stock through `RecordStockMovement`. WEIGHT paths are byte-for-byte unchanged.
- **POS unit stepper.** The dispensary POS shows the grams pad for a WEIGHT genetic and a +/− unit stepper for
  a UNIT genetic (mode driven by the selection). A shared `activeEntryGramsCg()` computes the gram-equivalent
  identically for both (weighed grams, or units × grams_per_unit_cg), so the compliance gauge gives the same
  real-time ceiling feedback — pinned by a test asserting 3 prerolls (×0.70 g) and a 2.10 g flower entry read
  the same 210 cg.

### Judgment calls

- **No Filament GeneticPrice surface exists in the app** (prices are seed / opening-import managed per the
  prompt-01 opening-balance path — prompt 20's form audit did not add one). So there was nothing to make
  type-aware; the model one-of-two guard + `ResolvePrice` branch deliver the behaviour. `GeneticPrice::factory
  ()->perUnit()` covers UNIT pricing in tests. If a prices UI is added later it must show only the field
  matching the genetic's `unit_type`.
- **`batches.cost_per_gram_cents` is reused as "cost per gram-equivalent" for UNIT batches** (no
  `cost_per_unit` column added). Valuation stays uniform: `onHandCg() × cost_per_gram_cents / 100` works for
  both kinds. UNIT intake leaves cost optional (default 0); refine per-unit costing later if the club needs it.
- **Reports are presentation-only** (ledger/limits/ceiling arithmetic unchanged): `product_type` added as a
  breakdown column on the stock + consumption reports, UNIT rows show a unit count alongside the
  gram-equivalent, and stock movements gained a `Uds` column (unit movements carry `qty_units`, not `qty_cg`).
  The merma **weight** summary still sums `qty_cg` only; unit merma shows in the movements `Uds` column.
- **`concentrate_subtype`, `grams_per_unit_g`, `thc_mg_per_unit`** are conditional form fields (subtype for
  CONCENTRATE; grams-per-unit required+shown for PREROLL/EDIBLE, entered as grams → stored centigrams like the
  thc_pct precedent; THC/mg for EDIBLE). `grams_per_unit_cg` is allowlisted in FormCompletenessTest (entered
  via the virtual grams field); `initial_units`/`remaining_units` allowlisted like their cg counterparts.

---

## Prompt 23 — counter screens: a way back to the dashboard

- **One shared header component** `resources/views/components/counter/top-bar.blade.php` (`<x-counter.top-bar>`),
  rendered by the counter layout so ALL four counter screens (check-in, till, dispensary POS, bar POS)
  — and any future fifth — get it for free. It carries brand + screen title, a back-to-dashboard link,
  and Log out (a POST to `filament.admin.auth.logout` — the counter operator can always end their
  session). Replaces the old ambiguous single "Salir → /" link.
- **The back-to-dashboard link reuses the EXACT sidebar gate:** `User::canAccessPanel()` (active + has a
  role). A locked-down counter-only login (e.g. an inactive/role-less till account) sees the shared
  header but NO dashboard link — the intended lockdown for a fixed till tablet, not a bug. Denial-tested.
- **Confirm before leaving unsaved work:** a shared Alpine store `counter.dirty` (registered once in the
  counter layout) is set by the stateful screens via a `@script` `$wire.$watch` — POS/bar flag a
  non-empty `basket`, the till flags an in-progress blind count / cash entry. The header's Panel link
  and Log out both confirm when it's true, so navigating away never silently drops a basket or count.
- The till open/close flow already had a working `cancelClose()` + "Cancelar" control at the blind-count
  step (verified + tested), so no new control was needed there.
- Kiosk feel preserved: one small, consistently-placed affordance sized for the existing tablet-1024 /
  one-handed-390 rules — not the Filament sidebar, not breadcrumbs.

---

## Prompt 24 — admin panel gaps: debt/credit settings & multi-location staff assignment

**Settings-completeness audit** (repeatable gate: `DebtAndLocationSettingsTest::test_the_settings_page_covers_every_org_configurable_setting`). Compared every `Settings::DEFAULTS` key against the org form:
- **Added (confirmed gaps — enforcement already read them, no form to set them):**
  - `wallet_door_debt_threshold_cents` — the DOOR debt threshold (euros at the edge). Distinct field
    from the hard limit; `ResolveMemberEligibility` reads it for the `door` surface, `RecordWalletTransaction`
    reads `wallet_debt_limit_cents` for the counter. **Never merged — two fields, two enforcement points**
    (tested: door reacts at the threshold, counter blocks at the limit, changing one doesn't move the other).
  - `wallet_ring_fence` (toggle) — per-location wallet ring-fencing.
  - `limit_override_requires_manager` (toggle) — found in the same pass; `CommitDispensation` reads it.
  - `avalador_therapeutic_exempt` (toggle) — found in the same pass; the avalador logic reads it.
- **Deliberately excluded (documented):** the `enforcement` matrix (its own door/counter editor); per-location
  settings (`aforo_default`, `aforo_enforcement`); locale settings (own switcher); system/compliance constants
  (`data_retention_days`, `audit_retention_days`, `signed_url_ttl_seconds`, `consent_text_version`, retention/
  heartbeat); `blind_count_enforced`, `minute_quorum_fraction_bp`; `pos_*`. `forecast_options_g` is a preset
  ARRAY (a tags/repeater input is a later enhancement); `low_stock_threshold_cg` is a fallback — the operative
  low-stock threshold is set per-article on the Article resource.
- **Debt is what enforcement reads (tested e2e):** toggling debt-allowed + a limit through the form is exactly
  what `RecordWalletTransaction` enforces at the counter — the configured value, never a hardcode.

**Multi-location staff assignment** — the Users form ALREADY had a `->multiple()` `locations` relationship
select backed by `location_user`; it was not a missing field but an under-documented one. Decisions recorded:
- `OVERNIGHT-DEFAULT — CONFIRM:` **OWNER picker = allowed-but-irrelevant.** An owner sees ALL org locations via
  `LocationSwitcher` (and the "All locations" rollup) regardless of the picker, consistent with how OWNER scope
  is special-cased elsewhere — so the picker is left enabled but optional for owners.
- `OVERNIGHT-DEFAULT — CONFIRM:` **A MANAGER/STAFF saved with ZERO locations is a deliberate "no access yet"
  state** (not blocked at save): they can log in but `LocationSwitcher::available()` is empty and `canAccess()`
  is false for every sede until one is assigned. Helper text on the field says so. Tested explicitly.
- **Timing = immediate (next request), no re-login:** `LocationSwitcher` reads `user->locations()` live each
  request, so a re-synced assignment takes effect on the next request; a currently-active location that is
  revoked fails the next `canAccess` scope check. The prompt-02 single-location denial test still passes, plus a
  new multi-location positive case (A and B reachable, C not).

---

## Prompt 25 — alerts render in Spanish regardless of locale

**Root cause.** The dashboard Alerts panel builds each sentence with `trans_choice()`
(`Dashboard::decorateAlerts()`), and 3 report/register counts do too. But `LangKeys::usedInCode()`
only scanned `__(` / `@lang(`, never `trans_choice(` — so all 9 pluralized keys were invisible to
prompt 19's parity test and were never added to `lang/*.json`. `trans_choice` then echoes the Spanish
key verbatim, and the app default locale is `en`, so the English UI showed Spanish. This is a *different
bug class* from prompt 19's: not "a key missing from one file" (parity catches that) but "a string that
never became a verified key at all."

**Audit checklist (every alert/notification path — same discipline as prompt 20's form audit; an
independent Explore-agent sweep corroborated):**
- Dashboard Alerts panel — 6 implemented types (members_over_limit, unreconciled_till, batches_expiring,
  stock_ceiling_exceeded, memberships_expiring, pending_applications): all `trans_choice`, all **were
  LEAKING** (keys absent) → **FIXED**. (The other types named in the prompt — near-limit, overrides-used,
  aforo, low-stock, unpaid-fees, till-variance — are not implemented as dashboard alerts; some are only
  stat-card figures. Documented, not invented.)
- Report/register counts — `registro-dispensacion.blade.php`, `libro-socios.blade.php`,
  `documents/register.blade.php`: same `trans_choice` leak → **FIXED**.
- 33 Filament `Notification::make()` calls across 13 files — every `->title()` is `__()`-wrapped;
  bodies are variables. **CLEAN.** Two `->body($e->getMessage())` families surfaced hardcoded ENGLISH
  exception text (inverse leak) → the surfaced exceptions **translated** (see below).
- Counter block/flash sentences (`DispensaryPos`, `CheckInScreen`, `TillSession`, `BarPos`,
  `ResolveMemberEligibility`): all `__()`-wrapped. **CLEAN.** One `DispensaryPos` `flash($e->getMessage())`
  surfaced English `DispensationBlockedException` text → **translated**.
- Interpolated/`sprintf` sentences: none unwrapped except the ledger descriptors below.

**Surfaced exception messages translated** (they render live in a toast body or counter flash, so a
hardcoded language there violates the "never one-language" rule; no test couples to the text —
`expectExceptionMessage` count = 0, so zero-risk): `CommitDispensation` ×2 (reused existing eligibility
keys), `RecordWalletTransaction`, `RecordStockMovement` ×2 (`:batch`/`:name` placeholders),
`ApproveApplication`. All added to both locale files.

**Deliberately OUT OF SCOPE (documented, conservative — this is a focused branch, not a stored-data
localization project):**
- **Stored ledger `reason`/`motivo` descriptors** (`Aportación por dispensación`, `Compra en barra`,
  `Cuota de socio`, `Liquidación automática de deuda`, `Alta de lote`, `Recuento de inventario`,
  `Reposición`, and the interpolated `Reversal of voided …`): persisted DATA shown in the Cartera
  "Motivo" column, in the canonical Spanish domain vocabulary (CLAUDE.md: terms of art stay Spanish even
  in English). Localizing stored historical descriptions is a distinct concern (needs a stored key +
  display-time mapping across ledger/reports/PDF/export; the void-reversal ones aren't fixed keys) and
  would churn core financial Actions — not this branch. Flagged for a future prompt.
- **Dev-mail preview fixtures** (`DevMail.php` sample names/reason): test-harness data for `/dev/mail`.
- **`FailedJobs` admin screen** shows raw queue-exception text by design (arbitrary framework
  exceptions, a developer diagnostic), left in English.

**The two complementary checks (both wired into `composer check`):**
1. `LangKeys::usedInCode()` extended to scan `trans_choice(` and `trans(` (safe negative-lookbehind so
   identifier suffixes like `reTRANS(` can't misfire). The EXISTING parity test now covers pluralized
   keys — i.e. it would now catch exactly this bug. This is the root-cause fix, not just the instances.
2. `App\Support\NotificationCopyScanner` — a **tokeniser-based** static scan (not regex, so
   `->body($e->getMessage())`, closures, concatenations and `__()` wrappers are reliably told apart from
   a bare literal). Flags any raw natural-language string literal (contains whitespace + a letter) passed
   directly to a notification/alert **sink** — `->title()`, `->body()`, `->flash()`. `NotificationCopyScannerTest`
   asserts zero violations in `app/` AND proves the scan catches a deliberately reintroduced hardcoded
   string (and does not flag any legitimate wrapped/dynamic form). Sink set chosen as the actual
   notification+counter-alert surface in this codebase; extensible if new sinks appear.

**Tests:** every implemented alert type asserted on the ACTUAL rendered sentence (via the real
`decorateAlerts`) in both locales; a pluralized alert asserted grammatically (1 socio/3 socios,
1 member/3 members — not just a substituted number); one seeded alert rendered translated end-to-end
through the full dashboard request; a sample toast from each area (settings/members/POS/till) in both
locales; the scanner regression test; prompt 19's parity test still green. No migration / no
driver-sensitive change → MySQL parity N/A.

---

## Prompt 26 — PIN operator switching: audit & complete the missing UI

**Audit (recorded before building).** The backend was fully built AND tested, but entirely inert:
- **EXISTS + correct:** `User.pin` cast `hashed`; `App\Actions\UnlockOperator` (matches a PIN against the
  location's active staff, **rate-limited** 5 attempts / 60s, a correct PIN refused while locked out);
  `App\Support\CounterOperator` session store; all 5 write paths (dispensation, bar order, cash
  movement, check-in, till open) already attribute `CounterOperator::id() ?? Auth::id()`; the Users
  admin form already has a set/reset-PIN control (`UserForm` pin field, 4–8 digits, dehydrated-when-filled).
- **MISSING:** the UI + wiring. Nothing called `UnlockOperator` or `CounterOperator::set()` outside the
  test file, so `CounterOperator::id()` was **always null** and every transaction silently fell back to
  the device session login (`Auth::id()`). No current-operator indicator, no PIN pad, no block-on-missing.
- **Verdict:** the smaller "missing UI on a working backend" fix — NOT a missing feature. An independent
  audit-agent sweep corroborated line-by-line.

**Build.**
- One shared trait `App\Livewire\Counter\Concerns\IdentifiesOperator` (single implementation) mixed into
  all four counter screens — `unlockOperator`/`switchOperator`/`openOperatorPanel`/`closeOperatorPanel`,
  the `requireOperator()` transaction guard, and `currentOperatorName`/`hasOperator`/`operatorLockedOut`.
  Reuses the existing rate-limited `UnlockOperator` + `CounterOperator` verbatim (no second code path).
- One shared Blade partial `livewire/counter/partials/operator-strip.blade.php` (@include'd in all four
  views) — "Trabajando: [name]" indicator + tablet-first numeric PIN pad + switch + wrong/rate-limited
  feedback. The PIN is cleared after every attempt and never rendered back.
- Every counter commit now calls `requireOperator()` first: with no operator identified it refuses with a
  clear prompt (the pad opens) — never a silent fail, never the device user attributed.

**Decisions (documented).**
- `OVERNIGHT-DEFAULT — CONFIRM:` the operator strip is on **all FOUR** counter screens (check-in,
  dispensary POS, bar POS **and till**). The prompt names three, but the till open + cash-movement writes
  attribute an operator and the required cash-movement attribution test needs one identified there —
  consistent and safer than leaving the till able to write with only the device login.
- The write-path Actions keep their defensive `?? Auth::id()` fallback for **non-counter callers**
  (Filament admin stock adjust/wallet actions, where the logged-in admin legitimately IS the operator);
  the counter components guard so that fallback can never fire for a counter transaction.
- Existing counter tests: each `operator()`/`staff()` fixture now calls `CounterOperator::set($actingUser)`
  — it records the SAME id the `Auth::id()` fallback used to, so no assertion changed; it just satisfies
  the new guard. The one shadowed petty-cash permission-denial test was given an operator so it still
  tests the PERMISSION path, not the operator guard.

No migration / no driver-sensitive change → MySQL parity N/A. 363 tests green (10 new UI-flow +
attribution tests, incl. one per transaction type asserting the stored operator is the PIN identity,
not the device login).

---

## Prompt 27 — discounts have no admin UI (per-member + org-wide templates)

**Audit.** Most of this was already built:
- The **org-wide discount TEMPLATES resource EXISTS** — `DiscountResource` (form/table/pages), in the
  **Dispensario** nav group, gated by `DiscountPolicy` on `discounts.manage`. Not missing.
- `App\Actions\Members\AssignMemberDiscount` EXISTS (owner-only, audited) and `ResolvePrice` already
  reads per-member `memberDiscounts` (linked template OR inline custom, with expiry). `PricingTest`
  covers the arithmetic.
- **The one real gap:** no per-member UI. `MemberResource::getRelations()` had Memberships/Wallet/
  Documents/Consents but **no Discounts** — so there was no way to attach a discount to a member (the
  reported bug).

**Build.**
- New `DiscountsRelationManager` — a **"Descuentos" tab** on the member detail page (its own tab, matching
  the existing relation-manager tabs; chosen over an Overview block for consistency). Shows the assigned
  discount(s): type (Personalizado / template), value (−15% / −€5,00), expiry, who assigned it, and an
  Activo/**Caducado** status badge. Assign / edit / remove actions, all gated `member.discount.assign`
  (`canViewForRecord` hides the whole tab from managers/staff).
- The relation manager **delegates to Actions, not a second code path**: `AssignMemberDiscount` (extended
  to capture the reason in its audit), plus new `UpdateMemberDiscount` and `RemoveMemberDiscount` mirroring
  it (owner-only, audited who/what/from → to + reason). `ResolvePrice` stays the ONE resolver.

**Decisions (documented).**
- `OVERNIGHT-DEFAULT — CONFIRM:` per-member CUSTOM discounts are **GLOBAL** (they apply to every genetic
  for that member). This matches `ResolvePrice`'s existing evaluation of an inline member discount (it does
  NOT check `appliesToGenetic` for a custom value — only linked templates carry a scope). Category-scoping
  stays a property of the org templates. Simplest interpretation, and it matches the resolver rather than
  forcing the resolver to change.
- **Reason → audit log, no new column.** Prompt 08 frames the reason as feeding the audit trail (who / what
  / when / from → to); it is captured in the form and written to the audit entry, so no schema change is
  needed. Keeps the branch migration-free → MySQL parity N/A.
- The counter + receipt already surface the applied discount because they render through `ResolvePrice`
  (label "Personalizado −15%"); verified by test rather than assumed.

369 tests green (6 new: assign-through-UI + resolver applies it; expired not applied + UI shows Caducado;
denied to manager/staff at both the UI gate and the action; audit who/from → to + reason; the POS/receipt
label; the org-template resource gated + resolving).

---

## Prompt 29 — application invite links had no management UI

**Audit.** Invites DO persist (a PENDING `MemberApplication` + `invite_token_hash`), but the raw link is
shown **once** (hash-only storage) → **unrecoverable** the moment the toast is dismissed (the reported bug).
No invitations status board, no expiry, no revoke. The blank "New application" create exposed only
Location/Status/reject-reason (not a real intake). Prompt 15's mail inventory has Approved/Rejected
mailables but **no invite mailable** → resend-by-email was never in scope.

**Build.**
- **Re-copyable link (the fix):** the raw token is now also stored **encrypted at rest** (`invite_token`,
  `encrypted` cast); the Invitations view rebuilds the shareable link via a **Copy link** action, so it
  survives navigating away. Additive migration; nullable columns; every existing member backfills untouched.
- **Invitations view:** the MemberApplications table now shows applicant, review status, **invite status**
  (Sin abrir → Abierta → Enviada, or Anulada/Caducada), invited-by and expiry, with a lifecycle filter
  (open invites vs submitted) and Copy-link + Revoke row actions.
- **Status tracking:** `opened_at` (first form view), `submitted_at` (submission).
- **Expiry + revoke:** `invite_expiry_days` setting (default 14) → `invite_expires_at`; `revoked_at` set by
  the Revoke action. The public controller refuses an expired/revoked invite with a **clean message page**
  (not a broken/blank form), additively — the hash-verification path is unchanged.

**Decisions (documented).**
- `OVERNIGHT-DEFAULT — CONFIRM:` **token discipline** — kept the existing non-guessable
  `Str::random(48)` + SHA-256 **hash verification** (already non-guessable; arguably stronger than a
  signature) and enabled re-copy via **encrypted-at-rest** storage, consistent with the ID-scan encryption
  posture. The literal "signed-URL" pattern was **deliberately NOT** retrofitted overnight: it would rewrite
  the ONE unauthenticated, security-critical public route, which the overnight rules say to leave for human
  review. Re-copy + expiry + revoke are all delivered without touching the verification path.
- `OVERNIGHT-DEFAULT — CONFIRM:` **single-use** — an invite maps to ONE prospective member's application;
  it is **not reusable** for multiple different people (each prospect gets their own invite). It stays
  openable until expiry / revoke / a review decision so the one applicant can complete or correct their
  submission. (A decided application already dead-ends the link, pre-existing.)
- `OVERNIGHT-DEFAULT — CONFIRM:` **New-application button removed** — the blank create lacked the
  compliance-critical applicant fields (DOB, document, consents). Walk-ins use the Member direct-create flow
  (prompt 20, which has those fields); prospects use the invite link. Removed the header CreateAction so the
  two real creation paths are unambiguous.
- **Resend-by-email deferred** (documented): no invite mailable exists; copy-link is the delivery. A future
  prompt could add an invite mailable if email delivery is wanted.
- Expiry duration is the `invite_expiry_days` setting (default 14), on the settings page.

Migration verified on MySQL (encrypted-token column + FK + invite flow). 374 tests green (5 new).

---

## Prompt 30 — verify the membership expiry sweep actually runs

**Audit verdict: mostly FINE, with two real gaps found and fixed.** The sweep itself was correct all
along — it exists (`memberships:sweep`), flips ACTIVE/EXPIRING_SOON → LAPSED by `expires_at`, flags the
expiring-soon window, sends renewal reminders once per member per period (`reminder_sent_for` marker,
idempotent), reads windows through Settings — AND it is genuinely registered with the scheduler
(`Schedule::command('memberships:sweep')->dailyAt('05:00')`). Existing tests already covered the flip +
idempotency + reminder-once. So this was NOT "never fired"; the worry was warranted but the code is sound.

**Gap 1 — heartbeat was GENERIC (fixed).** `SystemHealth` only read the `scheduler` component heartbeat
(the every-5-min `system:heartbeat`). So a silently-broken sweep — with an otherwise-healthy scheduler —
would show GREEN. Fixed: `memberships:sweep` now stamps its OWN `HeartbeatLog::beat('memberships-sweep')`
on success; `SystemHealth::expirySweep()` checks it against a **26 h daily threshold** (vs the 15-min
scheduler threshold); the health page shows a dedicated "Barrido de caducidades" section and a red alert
when the sweep is stale even if the scheduler is fresh.

**Gap 2 — deployment story under-documented (fixed).** `SETUP.md` only mentioned `schedule:run` in
passing. Added a "Scheduled jobs" section: the production crontab line, the full scheduled-command table,
LOCAL-dev instructions (`schedule:work` / manual `php artisan memberships:sweep`) so "nothing happened
overnight" isn't mistaken for a working feature, and how-you-know-it-ran via the health panel.

**Confirmed, no change needed:**
- A lapsed member IS blocked at the counter and flagged at the door — `membership` is `BLOCK` on both
  surfaces and `hasActiveMembership` requires ACTIVE (tested as a chain now, not assumed).
- **Unpaid vs lapsed is NOT conflated.** The sweep is purely date-driven on `expires_at`; there is no
  "unpaid forces early expiry" path. The `unpaid_fee` eligibility rule is a SEPARATE door/counter check
  and never lapses a membership. No accidental coupling — confirmed, no deliberate policy to add one.

Tests added: lapsed→blocked-at-counter-and-door chain; expiring-soon flagged (not lapsed); scheduler
registration asserted via `schedule:list` (inspecting the real schedule, not just manual invocation);
health panel reflects a stale sweep specifically + the per-job heartbeat freshness. No migration →
MySQL parity N/A. 378 tests green.

---

## Prompt 31 — temporary / short-stay members

**Legal-sensitivity note (front and centre).** Spanish CSC case law is built around genuine, stable
association membership; "temporary member for people passing through" is a real operational need but is
NOT explicitly settled in that case law. The build does NOT present this as a guaranteed-compliant
shortcut anywhere — the settings section carries a plain "the legal standing of this figure is unsettled;
use it with judgement" note, and the feature is OFF by default (`temporary_members_enabled = false`).

**Design — the load-bearing rule holds.** A `kind` (STANDARD|TEMPORARY) + `temporary_expires_at` were
added additively (every existing member backfills to STANDARD). Temporary status changes **list
visibility and retention timing ONLY**. It never loosens age, avalador, carencia or the gram limits —
proven structurally by a test asserting the shared compliance resolvers (`ResolveMemberEligibility`,
`CommitDispensation`, `ResolveMemberLimits`) contain NO reference to the temporary concept at all, so
there is nowhere a shortcut could hide.

**Decisions (documented).**
- `OVERNIGHT-DEFAULT — CONFIRM:` **temporary members DO count toward the active-member soft cap**
  (`temporary_count_toward_cap = true`). Conservative: they are real members using the club, so excluding
  them could let the association silently exceed its real active load. A setting, flippable.
- `OVERNIGHT-DEFAULT — CONFIRM:` default window **30 days** (`temporary_window_days`); pre-removal
  reminder lead **3 days** (`temporary_reminder_lead_days`). Both settings, on the settings page.
- **Auto-removal = the existing erasure Action.** `members:remove-temporary` (mirrors the retention purge)
  routes every past-window temporary through `AnonymiseMember` — anonymise-not-delete, never a bespoke
  deletion — so financial + consumption ledger totals stay intact in anonymised form (tested identical
  before/after). Idempotent (skips already-anonymised), dry-run capable, audited, scheduled daily 04:15,
  and its per-job heartbeat is surfaced on the health panel (only when the feature is enabled).
- **Convert / extend** = `ManageTemporaryMember` action (reuses the person record, audited who/from→to),
  gated `members.create` (manager+). Convert clears the expiry (sweep then skips them); extend pushes it out.
- **Directory:** the everyday list excludes temporary members by default (a `kind` filter defaulting to
  STANDARD); a Temporal filter returns exactly them; a "Temporal" badge marks them in the table.

**DEFERRED (documented):** the OPTIONAL pre-removal **email reminder**. The setting
(`temporary_reminder_lead_days`) and the sweep exist, but wiring an actual email needs a dedicated
`TemporaryRemovalReminder` mailable with the full mailable ceremony (permanent render test + `/dev/mail`
preview + inline CID logo, per CLAUDE.md). That is a focused follow-up (pattern: `MembershipReminderMail`);
its required test is deferred with it. The removal itself (the load-bearing part) is fully built + tested.

Migration verified on MySQL. 386 tests green (8 new).

---

## CORRECTION (2026-07-31 completeness-check) — prompt 24 inert-settings claim was WRONG

The prompt-24 entry above states `wallet_ring_fence` and `limit_override_requires_manager` were "confirmed
gaps — enforcement already read them, no form to set them." **That is incorrect.** The overnight
completeness-check (grep of the literal keys across `app/ config/ database/`) found BOTH are read by NO
enforcement code — they are inert toggles the settings-completeness test happily accepts (it proves a field
renders, not that it's consumed). Reality:
- `limit_override_requires_manager` — overrides are gated by the FIXED `limits.override` permission
  (`CommitDispensation`); the manager-vs-not toggle changes nothing. INERT.
- `wallet_ring_fence` (org) — read nowhere; the real ring-fence logic reads a per-location
  `ring_fenced` setting (default false) that no form exposes. INERT + the org toggle is a no-op.
`avalador_therapeutic_exempt` IS genuinely consumed (the avalador logic reads it), so that part stands.
Full inert-settings inventory + recommended wire-or-cut decisions: see `AUDIT-FINDINGS.md` (Step 3).

---

## Prompt 32 — encrypt documents at rest (S1) + authorise/log the streaming endpoint (S2)

**Merge authorization:** merged to `main` per the project owner's EXPLICIT instruction ("merge the last 3
prompts to main"), which overrides the prompt's default "wait for review" — the owner is the reviewer
authorising the merge. Recorded here for the audit trail, since these are Article-9 security changes.

**S1 — encryption approach.** `App\Support\DocumentVault` encrypts with `Crypt::encryptString` (AES-256,
app key) at the write boundary and decrypts ONLY at the streaming endpoint. Chosen over an encrypting
filesystem adapter because it integrates cleanly with BOTH write mechanisms — `Storage::put` for generated
PDFs and Filament's `saveUploadedFileUsing` for uploads — and is explicit + unit-testable (a test asserts
the on-disk bytes are ciphertext, and a round-trip is byte-identical).

- **Scope (documented, deliberate):** encrypted the streamed Article-9 documents — the **ID scan, medical
  certificate and generated PDFs** — which all read through the ONE access-logged, authorised signed-URL
  endpoint (`MemberDocumentController`), so encryption + decryption live in one place. The **member photo**
  (inline Filament avatar/infolist), the **POS signature** (receipt display) and the **non-member business
  uploads** (purchase invoices, expense receipts, batch/article/genetic docs) share this disk but read
  INLINE through other paths; encrypting them cleanly requires re-routing those displays through a decrypt
  path, so they are a **tracked follow-up** — still private-disk + HTTP-protected, but not yet
  encrypted-at-rest. Corrected the false "encrypted at the model layer" comment in `config/filesystems.php`.
- **S3 SSE-KMS:** added the `options` (`ServerSideEncryption`/`SSEKMSKeyId` via env) to the s3 documents disk
  as defence in depth ON TOP of the app-layer encryption — both matter; no-op locally.
- **Existing files:** **reseed** — the only stored documents are dev/test fixtures, so a re-encrypt migration
  is unnecessary here. **If any real member documents ever exist before this ships in a real deployment, a
  one-off re-encrypt migration is MANDATORY, not optional** (a plaintext file already on disk stays plaintext).

**S2 — authorise, own, bind, log the stream.** `MemberDocumentController::show()` now: (1) rejects a URL
whose bound `u` (the issuing user) ≠ the current session user — a leaked/replayed URL can't be reused by
another session; (2) `Gate::authorize('view', $document)` where the policy checks `member.documents.view`
AND org object-ownership (the document's member must be in the actor's active org — a valid signed URL for
another org 403s); (3) writes a `DocumentAccessLog` on EVERY view (moved out of issuance — "every view is
access-logged" now means the view, not just minting the link); (4) decrypts at the streaming boundary only.
`IssueDocumentUrl` now binds `u` and no longer logs (superseded by the view log). Existing prompt-04/17 tests
updated from issuance-logging to view-logging.

**Receipt controllers (documented decision):** the dispensation/bar receipt controllers already
`Gate::authorize` but do NOT per-view access-log. **Deferred as a follow-up** — receipts carry consumption
data (Article-9-adjacent, lower sensitivity than ID scans/certs); extending the per-view access-log pattern
to them is a smaller separate change, tracked in AUDIT-FINDINGS.md. No migration → MySQL parity N/A.

---

## Prompt 33 — finance dashboard widgets had no real authorization gate (A1)

**Merge authorization:** merged to `main` per the owner's explicit "merge to main once finished" instruction.

Audited all 6 chart widgets. Findings + fixes:
- **`IncomeVsExpensesChart`, `IncomeByPeriodChart`** — pure finance, gated only by the dashboard blade's
  `@if($canSeeFinance)` (presentation, not authorization). **Fixed both layers:** added
  `canView()` (`reports.view` / `reports.view.all`) so Filament won't render them for a STAFF user, AND
  `DashboardCharts::incomeByPeriod()` / `incomeVsExpenses()` now return empty series when `! canSeeFinance`
  — so a forgotten `@if`, a template change, or a direct call can never leak figures.
- **`DispensedByGeneticChart`** — MIXED: its "Importe (€)" mode rendered per-genetic `total_cents` (finance)
  alongside the grams (operational). **Fixed at the data layer:** `dispensedByGenetic()` zeroes `total_cents`
  for a non-finance actor, so STAFF keep the operational grams view but the € value can't leak. No `canView()`
  on this widget — it stays visible to STAFF for the operational data.
- **`ConsumptionDistributionChart`, `StockLevelsChart`** — operational only (member-usage histogram, stock
  grams). No finance figures; no gate needed (verified their data methods carry no cents).
- **`DashboardChart`** — abstract base; no data of its own.

Reused the existing `reports.view*` permission pattern (no new scheme). Tests: STAFF `canView()` false /
OWNER true; the income data layer returns empty for STAFF and real figures for the owner regardless of
caller; DispensedByGenetic `total_cents` zeroed for STAFF, real for the owner (grams visible to both).
No migration → MySQL parity N/A. Tests green.

---

## Prompt 34 — inert settings: wire the real ones, cut the dead ones (INCREMENTAL)

**Merge authorization:** owner-authorised ("merge to main once finished"). Done incrementally — the
highest-value, self-contained safety fix (item 2) is wired + merged now; the remaining wire/cut items
are DECIDED below and implemented in follow-up commits on this line of work. Per-setting decisions:

1. **`active_member_cap` (+ `temporary_count_toward_cap`) — WIRE (pending):** add a head-count-vs-cap
   dashboard ALERT (reusing prompt 14's alerts panel + the trans_choice pattern, NOT a new mechanism);
   `temporary_count_toward_cap` includes/excludes TEMPORARY members from the count. (Not a hard block —
   a soft cap is a warning, matching prompt 03.)
2. **`avalador_max_sponsees` — WIRED ✅ (this commit):** enforced via `App\Rules\AvaladorWithinSponseeCap`
   on the member form's avalador field — an avalador at capacity is refused with a clear message. It was
   inert before (a sponsor could back unlimited members). Tested (at-cap refused; raising the cap admits).
3. **`wallet_ring_fence` (org) vs `ring_fenced` (per-location) — RESOLVE (pending):** the per-location
   `ring_fenced` (read by `AutoSettleDebt`) is the REAL setting → **cut** the inert org `wallet_ring_fence`
   toggle and **expose `ring_fenced` on `LocationForm`** (it had no control).
4. **`aforo_enforcement` / `aforo_default` — RESOLVE (pending):** aforo mode is fixed `BLOCK` via the
   enforcement matrix → **cut** the lying `aforo_enforcement` dropdown from `LocationForm` and document that
   aforo enforcement is intentionally fixed BLOCK (the safe default for a legal capacity limit).
5. **`limit_override_requires_manager` — CUT (pending):** overrides are gated by the fixed `limits.override`
   permission (the real mechanism); remove the inert toggle + document.
6. **`fees_to_wallet_allowed` — CUT (pending):** `RecordFeePayment` always allows a wallet-charged fee;
   remove the inert toggle + document (a future prompt can add real enforcement if the club wants it).
7. **`currency_locale` — CUT (pending):** `Money::formatted()` uses `app()->getLocale()`; the format is
   governed by `ResolveLocale` (prompt 19). Remove the inert control + document.
8. **`blind_count_enforced` — CUT (pending):** `CloseTill` is always blind (the safe default); remove the
   dead constant from `Settings::DEFAULTS`.

The prompt-24 DECISIONS error (wallet_ring_fence / limit_override_requires_manager wrongly called "consumed")
was already corrected above (see the "CORRECTION (completeness-check)" entry). No migration in this increment.

---

## Prompt 34 — inert settings COMPLETE (all 8 resolved)

Following the incremental start (item 2, avalador cap), the remaining seven are done:
- **1. active_member_cap (+ temporary_count_toward_cap) — WIRED:** `Dashboard::membersOverCap()` counts
  org-wide active members and fires a dashboard alert (reusing prompt 14's alerts panel + a trans_choice
  key, EN/ES) when at/over the cap; `temporary_count_toward_cap` includes/excludes TEMPORARY members.
  Soft cap → a warning, not a block. Tested (fires at cap; the toggle changes the count 2↔1).
- **3. ring-fence — RESOLVED:** cut the inert org `wallet_ring_fence` toggle; exposed the REAL per-location
  `settings.ring_fenced` toggle on `LocationForm` (read by `AutoSettleDebt::isRingFenced`). Tested.
- **4. aforo_enforcement — CUT the dropdown:** aforo is a legal capacity limit, enforced as a fixed BLOCK
  via the enforcement matrix; the warn/block `LocationForm` dropdown was a lying control — removed +
  documented in the form.
- **5. limit_override_requires_manager — CUT:** overrides are gated by the fixed `limits.override` permission
  (the real mechanism). Toggle + DEFAULT removed.
- **6. fees_to_wallet_allowed — CUT:** `RecordFeePayment` always allows a wallet-charged fee. Toggle + DEFAULT
  removed (a future prompt can add real enforcement if wanted).
- **7. currency_locale — CUT:** `Money::formatted()` follows `app()->getLocale()` (governed by ResolveLocale,
  prompt 19); the control couldn't lock the format independently. Select + DEFAULT removed.
- **8. blind_count_enforced — CUT:** `CloseTill` is always blind (the safe default). DEFAULT removed.

Every cut control is REMOVED from the form (not left rendering with a note). The prompt-24 DECISIONS error
was corrected earlier. Tests confirm the cut keys are gone from `Settings::DEFAULTS` + the form, and the
wired ones behave. Owner-authorised merge. 402 tests green.

## Prompt 37 — structural cleanup: latent hazards, duplicated logic, missing confirmations

- **ForceDelete actions removed everywhere.** `ForceDeleteAction`/`ForceDeleteBulkAction` were auto-added on
  soft-deleting Filament resources but NO policy ever granted `forceDelete` — the buttons could only 403.
  Removed them (and the now-unused imports) rather than leave dead controls that imply a capability. Denial
  proven: `MemberPolicy` defines no `forceDelete`, so the Gate denies it even for OWNER (test).
- **Duplicated enrolment defaults → one source.** `ApproveApplication` and `CreateMember` each set member_no /
  ACTIVE status / joined_at / carencia_ends_at inline — two copies of the carencia rule that could drift.
  Extracted `App\Support\MemberEnrolment::defaults($orgId)`; approve `fill()`s it, create `+=`s it (keeps the
  old `??=` "don't clobber an existing value" semantics; when org is null the row can't persist anyway).
  Test proves both paths produce identical status/joined_at/carencia from one `carencia_days` fixture, with
  sequential member numbers.
- **Confirmations on money/stock mutations.** Wallet `adjust` (can subtract a member's balance) and batch
  `merma` (destroys compliance-relevant stock) had form modals but no final confirm — a mistyped negative
  committed immediately. Added `->requiresConfirmation()`; the form schema + confirmation coexist (test: the
  confirmed adjust still writes €10.00 → 1000 cents). Batch `adjust` (a signed ledger correction, not a loss)
  left as-is — outside the stated scope (merma + wallet adjust).
- **Inert MemberApplications bulk-delete removed.** `MemberApplicationPolicy` grants no `delete` (applications
  are the invite/review record, not disposable), so the `DeleteBulkAction` was inert. Removed + documented in
  the table; denial proven for OWNER/MANAGER/STAFF (test).
- **Dead code deleted:** `App\Support\SiteContent` (+ its test) and the default `welcome.blade.php` — both
  unreferenced (superseded by `Settings` and the dashboard-only `/`).
- **`grams_per_unit_cg` carve-out documented** in CLAUDE.md: a `*_cg`-named column deliberately cast plain
  `integer` (a definitional per-genetic constant, not a live weight-of-goods figure), every use already
  explicit `(int)` — NOT a WeightCast bug to "fix".

Owner-authorised merge. 405 tests green (Pint + Larastan L6 clean).

## Prompt 38 — low-severity hardening: application spam protection, CSP/HSTS

- **Application spam mitigation (honeypot + minimum submit time), SILENT.** The one unauthenticated form
  (invite → pre-registration) had only `throttle:10,1`. Added `App\Support\ApplicationSpamGuard`: a honeypot
  field (`website`, hidden from humans) + a signed render timestamp (`form_started_at`, Crypt-encrypted so
  it can't be forged). A filled honeypot, a submit faster than `MIN_SECONDS = 3`, or a missing/tampered
  token is discarded SILENTLY — the automated client gets the byte-identical thank-you redirect and nothing
  enters the review queue. Runs after validation, before the write. Chosen over a CAPTCHA (no third-party
  JS/secret; the form is legally non-public) and over a visible error (which would teach a bot how to pass).
  Tests: honeypot / too-fast / tampered all dropped; a human-paced valid submit still stores.
- **CSP report-only by default.** `config/security.php` holds the policy; `SecurityHeaders` sends it as
  `Content-Security-Policy-Report-Only` until `CSP_ENFORCE=true` flips it to enforcing. Permissive by
  necessity (`script-src` allows `'unsafe-inline' 'unsafe-eval'` — Alpine/Filament need eval) but still
  blocks external/injected scripts, framing, and base-uri / form-action hijacking. Report-only first is the
  conservative rollout: a too-tight policy is observed, not breaking, before it is enforced. No report-uri
  collector yet (documented follow-up).
- **HSTS production + HTTPS only, no preload.** Sent only when `app()->environment('production')` AND
  `$request->isSecure()`, so a dev machine can't get pinned to HTTPS. `preload` deliberately omitted (an
  effectively irreversible public commitment). `hsts_max_age` configurable (default 1 year).

Owner-authorised merge. 412 tests green (Pint + Larastan L6 clean). No migration → MySQL parity N/A.

## Prompt 36 — UI, empty-state & accessibility cleanup (Phase C leftovers)

Verified each audit item against the LIVE code first (several palette/enum/border items had already landed
in `chore/design-audit-fixes` @ 38c0e40 — not re-done). Outstanding items built:

- **Shared `<x-button>`** (`resources/views/components/button.blade.php`) — primary / secondary / danger /
  danger-soft / warning / outline × sm|md|lg|xl; renders `<a>` when `href` is set; layout + behaviour
  attributes (w-full, wire:click, type, @click) pass through; EVERY variant carries a focus ring (a11y).
  Adopted on the SOCIO CTAs (application, login, notifications, events) — which harmonises the main drift
  (socio was rounded-lg + no ring; the component is the counter's rounded-xl + ring). Locked by a render
  test. The counter screens are already internally consistent, so their adoption is a documented
  visual-pass follow-up, not a blind 20-site edit.
- **a11y:** one `<h1>` per counter screen via the shared top-bar (headings below start at h2 without a
  skipped level); `role`/`aria-live` on the flash banner (error → assertive alert, else polite status) on
  all four counter screens + on the offline banners; `role=img` + label on the footfall heatmap (a text
  alternative for the colour grid); placeholder contrast bumped `text-ink-muted/60` → `text-ink-muted`
  (the /60 was ≈3:1 — fails AA, near-invisible in dark) across every counter input.
- **Empty states:** tailored heading + description on the four sibling member relation managers (Consents,
  Documents, Memberships, Wallet) for parity with Discounts; socio `history.blade.php` empty states → the
  designed dashed-card.
- **code-style (behaviour-preserving, pinned by the existing suite):** `Batch::scopeDispensable()` — the ONE
  open/in-stock/not-expired predicate the FEFO selector and all three POS stock queries now route through
  (drops the per-type stock-column branching; the one-of-two invariant makes `remaining_cg>0 OR
  remaining_units>0` resolve per type); `ApproveApplication` records consent through the existing
  `RecordMemberConsent` action instead of an inline `consents()->create()`.
- **failed-jobs page:** off-palette `#eef2f7` + light-only `#e2e8f0` borders → `border-line
  dark:border-slate-800` (now dark-mode aware); `#16a34a`/`#dc2626` semantic hexes → `text-success`/`text-error`.

Owner-authorised merge. 417 tests green (Pint + Larastan L6 clean; EN/ES parity). **VISUAL VERIFICATION
PENDING** (built blind — no browser): the socio button restyle, counter flash/offline/heatmap a11y, and the
empty states want a screenshot pass across widths + light/dark.

## Prompt 35 — camera QR scanning: check-in + dispensary POS (built fresh)

Built the shared camera scanner the prompt-22/28 skip had left absent (OVERNIGHT-LOG). Design: a
PROGRESSIVE ENHANCEMENT over the existing wedge scanner + name search — it never gates identification, so
a missing/denied camera can't block the counter.

- **Per-location opt-in.** `camera_scan_enabled` added to `Settings::DEFAULTS` (false) — the LocationForm
  toggle (present since prompt 03 but reading nothing) now drives it. Excluded from the org settings page
  (it's per-location; documented in the settings completeness test).
- **Shared component.** `resources/views/components/counter/camera-scan.blade.php` + the `cameraScan`
  Alpine behaviour in `resources/js/app.js` (previously empty). Native **BarcodeDetector** on capable
  browsers (Chrome/Edge/Android); where the API is absent the trigger hides itself (`supported = false`)
  and the manual inputs remain. getUserMedia rear camera; the stream + scan loop are torn down on close,
  decode and `livewire:navigating` (camera released, no leak). Reuses `<x-button>`.
- **Same server lookup.** A decoded QR calls `$wire.submitCameraScan(token)` on BOTH screens, which sets
  `$scan` and delegates to the existing `submitScan()` → `ResolveMemberByToken` (QR_CARD token, sha256
  hash). One lookup path; no id in the payload.
- **Wired into** the check-in door + the dispensary POS Identify step, each `@if($cameraScanEnabled)`.

Verifiable here: the gate, the trigger's presence/absence, the token→member wiring (tests), and that the
bundle compiles (`npm run build`). NOT verifiable headless (owner verifies on a device): the live camera
decode. DEFERRED + documented: a pure-JS **jsQR bundled fallback** for Safari/Firefox/iPad (needs `npm i
jsqr` + a browser to add & verify) — until then those browsers fall back to manual entry. Camera access
needs a secure context (HTTPS/localhost) in production.

Owner-authorised merge. 421 tests green (Pint + Larastan L6 clean; EN/ES parity; Vite build OK).

## Prompt 40 — audit log viewer: formatted, plain-language changes

The audit entry viewer (`AuditLogInfolist::diffHtml`) rendered raw JSON cents/cg + snake_case column
names. It now renders each changed field via two generic classes, reusing what's already defined
elsewhere in the panel — no second labeling/formatting registry:

- **`App\Support\AuditFieldFormatter::format(?model, key, value)`** — value → the same display string the
  field shows anywhere else: money (euros), weight (grams), enum `->getLabel()`, dates (`d/m/Y`), Yes/No.
- **`App\Support\AuditFieldLabeler::label(?model, key)`** — field → its real Filament label, searched
  Table → Infolist → Form (first exact name match), reusing the resource's own translated labels.
  Settings (`settings.updated`, null model) resolves from `ManageSettings::form()`. A field with no home
  (e.g. `members.imported`'s synthetic summary keys) → `Str::headline()` + a `Log::warning` deduped per
  (model, field) per request so a real label can be added later.

**Decision — format from the column-name SUFFIX, not the Eloquent cast (cast check only as a secondary).**
`Member::declared_monthly_cg` (and the tier limit columns) are cast plain `integer`, NOT `WeightCast` —
grams conversion is done by hand at the page edge (`MemberInfolist` etc.). A cast-first formatter would
print those `_cg` fields as raw integers, silently breaking the spec's own primary weight case. The
`*_cents`/`*_cg` suffix is the reliable signal precisely because CLAUDE.md makes it a non-negotiable
naming convention. The settings `*_eur`/`*_g` keys are ALREADY display units and DON'T end `_cents`/`_cg`,
so the suffix rule correctly leaves them alone (re-dividing would corrupt the figure, not no-op). A
secondary check still formats a genuine MoneyCast/WeightCast column that somehow lacked the suffix.

**Label resolution needs a live owner.** `Schema::make(null)` throws in `getLivewire()`; the resource's
own List page (both HasTable and HasSchemas, whose mount/boot never runs) is used as the owner to build
the table/infolist/form component trees with zero DB hits. The model→resource map is built from
`Filament::getCurrentPanel() ?? getPanel('admin')` → `getResources()` → `getModel()`, memoised per request.

**Raw JSON demoted, not removed** — the before/after `<pre>` blocks (still escaped, `e()` throughout —
audit payloads are attacker-influenced) now sit inside a native, collapsed `<details>` (no JS, no dependency).

Tested: till-close cents → euros; `declared_monthly_cg` → grams (the non-WeightCast case) matching
`MemberInfolist`; enum → label; settings null-model with `_eur`/`_g` kept as-is; collapsed `<details>`;
generalises across TillSession/Member/Expense; unlabeled field → headline + logged once. 428 green.
Owner-authorised merge (the prompt's default "do not merge" overridden by the owner, who is the reviewer).
**VISUAL VERIFICATION STILL PENDING** (no browser here): before/after screenshots of an audit entry across
two model types, light + dark.

## Prompt 41 — bar POS: charge confirmation not visible without scrolling

Root cause (determined from the Livewire request cycle, not template-guessing): **candidate #2 (state
bug) ruled out; candidate #1 (scroll/position) confirmed.** `BarPos::commit()` sets `$lastOrderId` AND
the success flash, and `resetBasketState()` clears neither — the state DOES survive the render (a
Livewire test asserts `lastOrderId` retained + the "Última venta registrada" block present). The real
problem is discoverability: the prominent success/error flash rendered ONLY in the page-top banner
(above the 3-column grid), off-screen when the operator has scrolled to the basket to press Cobrar; the
colocated "last sale" block is a subtle receipt link, not an unmistakable "charged".

Fix: render the SAME `$flashMessage`/`$flashType` flash a second time COLOCATED in the basket column,
directly above the Cobrar button (reusing the existing mechanism + tokens, `wire:key="flash-basket"`,
role/aria-live). A successful charge — and equally an error — is now unmistakable at the point of action
regardless of scroll, covering the cash, wallet and every error path. The page-top banner stays.

Layout: the manual-amount section is structurally sound (standard `grid gap-3 sm:grid-cols-2`, token
spacing, `h-11` inputs — no positioning hacks or overflow), so no DOM overlap to fix; per the prompt's
own note the reported overlap was very likely the OS launcher/Spotlight in the screenshot, not the app.

Tests: success flash renders colocated (asserted via 2× occurrence in the rendered HTML) + `lastOrderId`
retained + receipt link resolves; error flash equally colocated. Owner-authorised merge. 430 green.
VISUAL VERIFICATION PENDING (no browser): before/after screenshots at the standard widths, light/dark.

## Prompt 42 — counter screen switcher in the shared top-bar

Added a screen switcher to the ONE shared counter header (`components/counter/top-bar.blade.php`), so all
four screens (check-in, dispensary POS, bar POS, till) get it for free — same shared-component approach as
the prompt-23 back-link and prompt-26 operator strip.

- **Pattern: inline icon+label pills (tabs), not a dropdown.** The counter is tablet-first (≥1024), where
  four labeled pills sit comfortably between the brand (left) and Panel/Log out (right); labels show from
  `lg` up and collapse to icon-only below, and the nav is `overflow-x-auto` so a narrow header scrolls
  rather than breaking. Simpler than a dropdown (no open-state JS) and keeps every destination one tap away.
- **Permission-filtered per the REAL per-screen gate** (mirrored from each component's mount): check-in →
  `checkin.manage`, dispensary → `pos.use`, bar → `pos.bar`, till → `till.open` OR `till.close`. A screen
  the user can't use is never rendered (no 403-in-waiting), exactly like the existing Panel link.
- **Active state** via `request()->routeIs()`: the current screen is a non-link `aria-current="page"`
  brand-tinted pill; the others are links. Same technique as the socio PWA bottom nav.
- **Unsaved-work confirm** reused verbatim from the Panel link (`$store.counter?.dirty` +
  `window.confirm(@js($confirmLeave))` before `window.location.assign`) on every switch link.

Tests: full-access sees all four (current active, others switch links); a `pos.bar`-only user sees only
Barra; every switch link carries the confirm guard; the active screen is marked per screen. Owner-authorised
merge. 434 green. Screenshots (four screens × light/dark × widths, two permission combos) pending — no browser.

## Prompt 43 — bar/merch sales: itemised reporting + oversight

FinancialReport already showed the "Barra" AGGREGATE; this adds the itemised/detail layer under it
(never touching that total).

- **OrderResource (read-only)** — list/view of individual sales, `canCreate() = false` (orders are
  committed/voided at the bar POS, mirroring TillSessionResource). Filters: status, location, operator,
  created-at range. Table + Infolist split; the Infolist's Anulación section (`->visible()` when VOIDED)
  shows void_reason / voided-by / voided-at. Authorised through the existing OrderPolicy — added a
  `viewAny` (pos.bar || reports.view) mirroring TillSessionPolicy; per-row org/location stays in `view`.
  Registered via the panel's resource auto-discovery + documented in the FORMLESS allowlist.
- **OrdersRelationManager on Member** — a member's bar-purchase history tab, `withoutGlobalScope(Location)`
  so it spans locations like WalletTransactions. Added the missing `Member::orders()` HasMany.
- **BarSalesReport + page** (`informes/ventas-barra`) via the existing ReportPage/AbstractReport base —
  top-selling articles by units + revenue, aggregating the order `items` JSON in PHP (portable across the
  SQLite/MySQL split). Off-catalogue manual lines (article_id null) group by name so every euro lands in a
  row and the GRAND TOTAL reconciles to the cent with FinancialReport's "Barra" (both = SUM(orders.total_cents)
  COMPLETED in scope) — a test asserts equality.
- Vocabulary: venta/ticket/pedido (the separate bar ledger), never aportación/dispensación.

Tests: list org+location scoped (wrong-sede hidden); viewAny denied without permission; voided order shows
its void audit; the member tab shows only that member's orders; report aggregation + the FinancialReport
reconciliation + voided/other-location exclusion. Owner-authorised merge. 442 green. Screenshots (resource/
relation-manager/report, light+dark) pending — no browser.

## Prompt 44 — settings coverage audit (continues prompts 24 + 34)

`camera_scan_enabled` confirmed fine (per-location, on LocationForm — prompt 35). The audit surfaced real gaps:

- **`pos_require_checked_in` / `pos_signature_required` — DEAD-TOGGLE bug, resolved via OPTION A
  (per-location).** DispensaryPos read two org-wide keys no UI ever wrote; the LocationForm's
  `restrict_pos_to_checked_in` / `signature_on_dispensation` toggles (built prompt 03) were read by nobody.
  Chose per-location: those toggles already exist, the app already has a per-location settings pattern
  (camera_scan_enabled, ring_fenced), and a multi-location club plausibly varies this by premises (a busy
  front-of-house may require door check-in; a small quiet sede may not). DispensaryPos now reads
  `signature_on_dispensation` / `restrict_pos_to_checked_in` (Settings::get resolves the active location
  first); the two org-wide `pos_*` DEFAULTS are retired. Exactly one setting per behaviour, genuinely read
  + editable. Tested end-to-end: toggling the location setting flips the enforced behaviour.
- **`aforo_default` — WIRED (was zero-consumer).** Now seeds a new Location's `capacity` default on
  CreateLocation (its evident intent); editable per sede. Tested.
- **`enabled_locales` / `default_locale` / `minute_quorum_fraction_bp` — now admin-editable** on
  ManageSettings (a locale multi-select + default-locale select + a quórum % edge field → basis points,
  like the `_eur`/`_g` pattern). Already READ by ResolveLocale / CreateMinute; the UI writes the SAME keys
  those consumers read (no second computation) — tested: saving flips ResolveLocale's result, stores 60% as 6000 bp.
- **Left alone (correctly deferred):** `forecast_options_g` (needs a tags/repeater — later);
  `consent_text_version` (read-only badge; bumping a legal consent version should be a deliberate audited
  action, not a casual settings edit — recommend a dedicated action if ever needed, not built here).

Owner-authorised merge. 447 green. Screenshots (global settings + Location form, light/dark) pending — no browser.

**FOLLOW-UP CORRECTION (prompt 59).** This entry's claim that toggling the LocationForm control flips POS
behaviour was only half true: `Settings::get` (which the enforcement reads) resolves `Setting` ROWS, but the
LocationForm `Toggle::make('settings.*')` controls wrote the `locations.settings` JSON COLUMN — a
disconnected store. The prompt-44 test passed because it wrote a `Setting` row DIRECTLY (`Settings::set(...,
$location->id)`), so the enforcement flipped, but the ADMIN toggle wouldn't have. This also means the
prompt-44/35 claim that `camera_scan_enabled` was "correctly wired end-to-end" was wrong. Prompt 59
reconciled the two stores to one (Setting rows) and re-tests through the LocationForm save path — see below.
The Option-A per-location resolution itself STANDS; only the storage plumbing under it was fixed.

## Prompt 46 — membership fee collection at the till

`RecordFeePayment` had zero callers, so `feesPaid()` always compared a nonzero `fee_cents` against €0 of
payments, leaving `unpaid_fee` permanently unsatisfied — and it's BLOCK at the counter, so EVERY member
with a fee was silently blocked from dispensing with no way to clear it. This builds the collection path
the till reconciliation + eligibility check already assumed existed. (The prompt-46 file was accurate —
no contradiction found.)

- **Till action "Cobrar cuota"** on `TillSession` (mirrors `recordExpense`): open session → requireOperator
  → gate on the NEW `membership.fee.collect` permission → org-wide member search (the SAME by-name/member_no
  query DispensaryPos uses) → show the outstanding fee on the member's latest active membership at this sede
  (the SAME membership `feesPaid()` checks) → collect CASH or WALLET via `RecordFeePayment` with
  `till_session_id` + operator. Partial/instalment allowed (rejects overpayment beyond owed; shows remaining).
  CASH flows into `TillSummary::fees_cash`; WALLET posts the FEE ledger movement and respects the debt limit
  (a WALLET fee from an empty wallet is refused — caught + surfaced).
- **New permission `membership.fee.collect`** (OWNER/MANAGER/STAFF), deliberately SEPARATE from
  `membership.fee.override` (who can change the *price* vs who can *take* the payment).
- **Members-section prompt-to-till.** Enrol/renew still ONLY set `fee_cents` (regression-tested — no payment
  row created there). After enrol/renew with an unpaid fee, a persistent notification points staff to the
  till (a "Ir a la caja" link when a till is open, else a note to open one). A "Cuota pendiente" badge column
  on the Memberships tab surfaces the balance at a glance.
- **Retroactive fix (step 3): DOCUMENTED, not synthesised.** Every membership enrolled/renewed before this
  has a real, unpaid `fee_cents` and is currently blocked at the counter. We do NOT backdate or synthesise
  payment rows (that would hide genuine unpaid debt). Staff clear existing balances through the new till flow
  — the badge shows who owes, collecting at the till unblocks them. A one-off console backfill was
  deliberately NOT built for this reason.

Transaction boundary: `RecordFeePayment` writes the payment (and, for WALLET, the ledger movement via the
single wallet writer) after the session/permission guards — matching the other till actions. Owner-authorised
(batch) merge. 455 green. Screenshots pending — no browser.

## Prompt 59 — one per-location settings store (corrects prompts 44 + 34)

**Root cause behind prompt 44's instance:** two disconnected per-location stores. (1) `Setting` rows carrying
a `location_id` — what `Settings::get()` resolves, and what all enforcement reads through. (2) a `settings`
JSON column on `locations` — what the LocationForm's `Toggle::make('settings.*')` controls wrote, read by
exactly one function (`AutoSettleDebt::isRingFenced`, itself never called — prompt 49). Zero location-scoped
`Setting` rows had ever been written; every per-location toggle was dead or read-only-by-dead-code.

**Decision — keep the `Setting`-row store, RETIRE the JSON column** (prompt 59's preferred): it's what the
resolver already uses, has `SettingType` casting and a clean org fallback, and a single flag change doesn't
re-save a whole model.

- `Settings::get($key, $default, ?$locationId)` gained an explicit-location param (backward compatible —
  null = active location) so the Location edit form can read a SPECIFIC sede's override.
- LocationForm's five toggles (`bar_enabled`, `signature_on_dispensation`, `restrict_pos_to_checked_in`,
  `camera_scan_enabled`, `ring_fenced`) are now VIRTUAL fields (a `SETTING_TOGGLES` const), loaded in
  `EditLocation::mutateFormDataBeforeFill` from `Settings::get(key, default, record)` and persisted as
  location-scoped `Setting` rows in `mutateFormDataBeforeSave` / `CreateLocation::afterCreate` — never a model
  column. `AutoSettleDebt::isRingFenced` reads `Settings::get('ring_fenced', false, $location->id)`.
- **Migration** copies every `locations.settings` JSON value into location-scoped `Setting` rows (BOOL) then
  drops the column (down re-adds it nullable). `ring_fenced` data survives (tested via the row → isRingFenced).
- **`bar_enabled` given a real consumer** (was dead): BarPos refuses with a friendly "Barra desactivada"
  state when off, and the counter screen switcher (prompt 42) hides the Barra link at a sede where it's off.
  Default true.
- **Corrections filed:** the `camera_scan_enabled` line in BUILD-LOG + an appended note on prompt 44's
  DECISIONS entry (Option A stands; only the storage under it was broken).

Tests: a location override overrides at A and not B; no override falls back to the ORG value not DEFAULTS;
**toggling through the EditLocation form flips the POS end-to-end** (the path the prompt-44 test masked);
ring_fenced survives as a row; the JSON store is gone (no column, no cast); and a GENERIC dead-key guard walks
`LocationForm::SETTING_TOGGLES` and fails if any toggle has no `Settings::get` reader (an enumerated test would
have passed while four were dead). Owner-authorised (batch) merge. 461 green. MySQL parity for the migration
PENDING — MySQL wouldn't start in this environment (SQLite parity holds). Screenshots pending — no browser.

## Prompt 48 — audit trail completeness (the five gaps prompt 17 named)

Wired `RecordAuditLog` into the five categories that wrote nothing to `audit_logs`.

- **Placement rule: audit in the DOMAIN action where one exists** (every path goes through it), else the
  Filament page's `afterSave` (plain resource edits). **Transaction boundary: INSIDE the existing DB
  transaction** — matching `CommitStockTake` (the already-audited neighbour), so a failed audit rolls back
  the money/stock movement it describes. One rule, not two.
- **Wallet adjustments** — `RecordWalletTransaction`, ADJUSTMENT type only (`wallet.adjusted`); before/after
  is the balance change. Routine top-ups/contributions/fees are traced in the wallet ledger, not flooded here.
- **Stock adjustments + merma** — `RecordStockMovement`, ADJUSTMENT → `stock.adjusted`, MERMA →
  `stock.merma`; before/after is the remaining quantity, and the merma REASON now lands in the audit log (not
  only the movement row). **Batch intake** — `IntakeBatch` (now wrapped in a transaction) → `batch.intake`.
- **Resource edits** via a shared `AuditsResourceChanges` trait (snapshot raw original in `beforeSave`, diff
  the saved values in `afterSave` — Filament fills the record AFTER `beforeSave`, so `getDirty()` there is
  empty): `article.updated`, `genetic.updated`, `member.updated`. Only CHANGED attributes, never the whole
  model, and credential/secret keys (password, remember_token, two_factor_*) are always excluded.
- **Role changes** — `EditUser` diffs the roles relation (names) → `user.roles.updated`.

Contradiction with the prompt file (flagged, as instructed): the prompt suggested `article.price.updated` /
`genetic.price.updated`, but both forms edit the whole record — used the accurate `article.updated` /
`genetic.updated`. And **genetic PRICES have no edit surface at all** (they live in `GeneticPrice` rows —
prompt 54's concern), so EditGenetic audits the DEFINITION, not a price. Also corrected AUDIT-FINDINGS.md AD4
(claimed merma/wallet adjust were "audited" — they were not; now they are).

Append-only stays enforced (writes only — the existing no-update/no-delete test still passes). No read is
audited. Tests: one per gap (right action/actor/subject + real before/after), a role change names the roles,
and no row carries credential material. Owner-authorised (batch) merge. 468 green. Screenshots pending — no browser.

## Prompt 60 — bar (and dispensary) POS: Charge could silently do nothing

**Root cause (determined by code analysis — no real browser available here):** the CLIENT-SIDE
silent-disabled swallow. Both POS Charge buttons bound `x-bind:disabled="! online || @js($commitDisabled)"`,
where `$commitDisabled` folded in server state (no till / empty basket / no socio / hard block / missing
signature). A native `<button disabled>` swallows its `wire:click` with NO feedback and NO `livewire/update`
request — exactly the "click does nothing, no order, no flash" the report described: a silent dead control in
any state where the button is disabled but that specific reason isn't rendered beside it. `requireOperator()`
was RULED OUT — it flashes + opens the PIN panel.

I could NOT reproduce the headless "enabled button, no livewire/update" capture in a real browser (none here),
so I can't say for certain whether that specific observation was a real enabled-button failure or a headless
artefact. But the fix closes the whole silent-failure class regardless of which it was.

**Decision — disable ONLY for offline; keep every other blocked state clickable.** The binding is now
`x-bind:disabled="! online"`. Offline is the one state a click genuinely can't reach the server, and its
banner is driven by the SAME `online` variable — so a disabled button ALWAYS has its reason on screen (they
can't desync). Every other blocked state now reaches `commit()`, whose guards each flash a reason (all
unchanged, none loosened). Chosen over "keep disabled + always render the reason" because an enabled button
that explains itself on click is more discoverable for a counter operator than a dead control they tap three
times before asking someone.

Applied to BOTH the bar and dispensary POS (shared structural pattern). The dispensary also gained a colocated
flash beside its Charge button (mirroring prompt 41's bar block) so a blocked reason is visible without
scrolling to the page-top banner. Prompt 41's bar block is untouched and now reliably reachable.

Tests: one per blocked state asserting commit() surfaces a stated reason (the regression guard); a valid charge
commits + confirms; both buttons' disabled binding is `! online` only. 479 green. Screenshots pending — no
browser. Owner-authorised merge (the prompt's default "do not merge" overridden by the owner, who is the reviewer).

---

## Batch 2·0 — fixture-drift: seeds go through the domain writer (new; not a numbered prompt)

**Root cause (confirmed on a freshly seeded DB).** `DemoDataSeeder::seedOrders` hand-built the order
`items` JSON as `{name, qty, price_cents}`, but `CommitOrder` (the real writer) writes `{article_id,
name, unit_price_cents, qty, line_total_cents}`. `BarSalesReport` sums `line_total_cents` grouped by
`article_id` — both keys the seeder omitted — so **the Bar sales report read €0,00 against ~100 seeded
units** (units half-worked because `qty` exists in both shapes). `BarSalesReportTest` hand-built items
*with* `line_total_cents`, so it stayed green while disagreeing with the seeder and with reality — the
classic "green test certifies a shape production never produces."

**Fix.** `seedOrders` now calls `CommitOrder::handle($location, [['article_id', 'qty']], [...])` — the
writer builds the item snapshot, depletes UNIT stock via a SALE movement, and posts cash. Nothing
hand-built. Verified on a fresh `migrate:fresh --seed`: BarSales `importe` = FinancialReport `barra` =
€141,90 across 102 units (reconciles + non-zero; was €0,00). New standing test
`SeededOrderShapeReconcilesTest` asserts an order written the seeder's way carries the real keys AND
reconciles — the regression guard.

**Standing rule added to `CLAUDE.md`** (Testing section): *fixtures and seeds go through the domain
action that owns the write; never hand-build a persisted shape a writer owns.*

**Dispensations — deliberately LEFT relational, not routed through `CommitDispensation`.** The prompt
named dispensations "the obvious next candidate." I checked: `seedDispensations` writes real
`DispensationLine` **rows** (not a JSON blob) carrying the FULL snapshot the writer produces —
`price_per_gram_cents`, `line_total_cents`, `discount_cents`, `genetic_name_snapshot`,
`batch_no_snapshot` — so there is no shape-drift bug there (a schema-enforced table can't silently
grow a wrong key the way `items` JSON did). Routing it through `CommitDispensation` would run every
seeded basket through the compliance boundary (membership/carencia/daily+monthly limits/eligibility/
pricing, and now prompt 46's unpaid-fee BLOCK), which arbitrary demo members won't reliably pass —
seeding would start failing on eligibility. Per the carve-out I added to the CLAUDE.md rule, a
compliance-boundary writer may stay relational-with-full-snapshot; this is that case. If a future
prompt wants demo dispensations to exercise the real enforcement path, it must first make the seeded
members eligible (fees paid, past carencia, under limits) — that is its own branch, not a sub-bullet
here. **Not an `OVERNIGHT-DEFAULT — CONFIRM`: this is a reasoned, reversible choice, and the seeded
shape already matches the writer's columns.**

Self-merge on green (batch 2 authorisation).

---

## Batch 2·1 — Bar POS layout (the half of prompt 41 that needed a browser)

Two confirmed layout defects (from real captures) + one tab-title fix. No browser here, so guarded at
the render level (assert the fix classes are present) in the house style of `ChargeAlwaysObservableTest`.

**1440 — long article name clipped the price.** The name/price row is `flex justify-between`; the name
`<span>` had no `min-w-0`, so its default `min-width:auto` refused to shrink, the row overran the card's
`overflow-hidden` edge, and the `shrink-0` price fell off the right ("Mechero €1,00" → "€1,0"). Fix:
name span → `min-w-0 … line-clamp-2` — it now wraps and clamps to two lines; the price is always fully
visible. Chose WRAP+clamp over truncate-with-ellipsis because on a POS the operator must be able to read
the whole product name, and a touch screen has no hover title to recover it.

**1024 (tablet-first, the counter's primary width) — basket + Charge buried behind a void.** The grid
was `lg:grid-cols-2`; auto-placement put the three panels Left→(r1c1), Centre→(r1c2), Right→(r2c1), so
the basket+Charge landed in column 1 BELOW the tall articles column, behind the empty space under the
short socio card — the operator scrolled past dead space to reach the primary action. Fix:
`lg:grid-cols-[minmax(0,1fr)_22rem]` + the RIGHT div explicitly placed `lg:col-start-2 lg:row-start-1
lg:row-span-2` (reset `xl:col-auto xl:row-auto` so the working 3-column xl sidebar is untouched). Now
socio+articles stack in column 1 with no gap and the basket+Charge sit at top-right, visible on load.
Chose column-placement over "stack everything at lg" so the primary action stays on-screen rather than
below the full article list. No `sticky` — it can't be visually verified here and behaves badly when the
basket exceeds the viewport; the placement fix alone removes the void.

**Tab title.** `BasePage::getTitle()` headlines the class name ("Bar Sales Report Page") and no report
overrode it — so EVERY Informes tab had the bug, not just Bar sales. Fixed once on the shared
`ReportPage` base: `getTitle()` now returns the (translated) `getNavigationLabel()`, same source as the
on-page H1. All seven report tabs corrected in one place.

**Left untouched (per prompt):** the broken avatar image beside the ES/EN switcher — that is prompt 61's
(the ui-avatars.com outbound call), not a cosmetic patch. 484 green. Screenshots pending — no browser.
Self-merge on green (batch 2 authorisation).

---

## Batch 2·2 — Prompt 49: wallet cross-location auto-settlement (dead code wired up)

`AutoSettleDebt` + `TransferCredit` existed with a ring-fence test but ZERO production callers — credit
at one sede never cleared debt at another, and there was no manual transfer either. Wired both up.

**Chose the scheduled nightly sweep over a writer-hook (per prompt).** New command
`wallet:settle-cross-location` (`SettleCrossLocationWallets`), scheduled `dailyAt('03:30')`. Per org it
reads a raw per-(member, location) balance aggregate and, for each member holding credit at one unfenced
sede and debt at another, calls the existing `AutoSettleDebt`. Lower risk than a hook: no re-entrancy,
and it reads LIVE balances so a retry/double-fire settles nothing extra (idempotent — tested).

**Subtle correctness point (the "contradicts the prompt" find):** ring-fencing is a per-location
`Setting`, and `Settings::get` resolves the location override THROUGH the active organisation scope.
The scheduler has NO ambient scope, so the command MUST `ActiveScope::setOrganisation($org->id)` before
any `AutoSettleDebt` call — otherwise `ring_fenced` falls back to its default (`false`) and a fenced
sede would be wrongly auto-settled. The ring-fence test clears the org scope before invoking the command
precisely to guard this; without the `setOrganisation` call it fails. Anyone wiring another console job
that reads a per-location Setting must do the same.

**Audit** — placed in `TransferCredit` (inside its transaction, prompt-48 style) as `wallet.transferred`
recording from/to/amount/reason, so it covers BOTH the sweep and the manual action with the actor coming
from the audit log's `actor_id` (null for the system sweep, the manager for a manual transfer).
`AutoSettleDebt::handle` now returns the cents settled (was `void`) for the sweep's summary line; the
existing ring-fence test ignores the return, so it stayed green.

**Manual transfer action** — `transferAction()` on `WalletTransactionsRelationManager`, gated on
`wallet.adjust` (manager+; reused rather than a new permission — a cross-location transfer is a
manager-level wallet mutation, same tier as the adjust it sits beside). Mandatory reason, from≠to, and a
server-side guard that it never transfers more than the source credit (a "transfer of credit" must not
manufacture debt at source). Denial test: STAFF (no `wallet.adjust`) cannot see the action. 491 green.
Self-merge on green (batch 2 authorisation).

---

## Batch 2·3 — Prompt 52: member history + RGPD pack include bar orders + visits

Three surfaces omitted bar/merch **orders** and check-in **visits**: the admin RGPD data pack
(`ExportMemberData`), the member's own PWA self-export (`PwaController::export`), and the PWA history
page. All three now include both (`Member::orders()`/`checkIns()` already existed — prompt 43/earlier —
so nothing re-declared). History gained a **Barra** and a **Visitas** section in the existing card style.

**The erasure finding (what the prompt asked me to check — and it contradicts the worry):** I verified
`AnonymiseMember` does NOT have the same omission, and the reason is a distinction worth stating. The
export gap was a **portability** gap (RGPD Art. 20 — giving the member their data); erasure (Art. 17) is
a different right. `CheckIn` carries NO standalone PII (only member/location/operator refs + timestamps +
method) and `Order` carries only a member_id reference plus operator-entered operational free-text — so
scrubbing the member ROW, where every personal field lives, erases the member from both. They are KEPT
attributed to the anonymised record (exactly like dispensations/wallet/till — the books stay whole),
which is correct, not a leak: a visit by "ANONIMIZADO Socio ab12cd34" reveals nothing. Documented this in
`AnonymiseMember`'s docblock and pinned it with a test. **So: an export missing a record type ≠ erasure
missing it; here only the export was wrong.** (The ID/photo FILES, which ARE PII, were already deleted by
erasure — that path is untouched and still correct.) 495 green. Self-merge on green (batch 2 authorisation).

---

## Batch 2·4 — Prompt 50: CSV import UI + duplicate detection on the real creation paths

`ImportMembers` (preview + import + audit) was fully built with ZERO callers, and `FindDuplicateMembers`
ran only inside it — so `CreateMember` and `ApproveApplication`, the two real ways a member is enrolled,
never checked for duplicates. Enrolling one person twice splits their balance + consumption history.

**Wired all three:**
- **CSV import UI** — an `Importar CSV` header action on `ListMembers`, gated on `members.import`
  (OWNER+MANAGER, first real use of the permission), uploading to a `TemporaryUploadedFile` and calling
  `ImportMembers::import()` (which already validates, skips duplicates idempotently, and audits). The
  result surfaces created / skipped / errored counts. `preview()` remains available for a future
  two-step wizard; I kept the UI to one step.
- **`CreateMember`** — a `beforeCreate()` runs `FindDuplicateMembers` on the entered details and HALTS
  with a persistent warning listing the matches, unless a create-only virtual toggle
  `acknowledge_duplicate` is ticked (the deliberate override for a genuine same-name other person).
- **`ApproveApplication`** — a new `bool $allowDuplicate = false` param; it throws a new
  `DuplicateMemberException` (extends `RuntimeException`, so the approve action's existing
  `catch (RuntimeException)` shows the message unchanged) unless the reviewer ticks a confirmation-modal
  toggle.

**Decision — WARN + override, not hard block.** The import path silently *skips* duplicates (idempotent
re-import), but the two interactive paths *warn and allow override*: an operator has context the system
doesn't (two real people can share a name+DOB). The override is server-enforced (a param / a form flag
read in `beforeCreate`), not merely UI-hidden, and each override is still fully audited via the normal
create/approve audit entries. Denial + both override paths are tested. 499 green. Self-merge on green
(batch 2 authorisation). **Batch 2 complete.**

---

## Prompt 61 — replace the external avatar provider + outbound sweep + RAT reconciliation

Filament's default `UiAvatarsProvider` built `https://ui-avatars.com/api/?name=<staff name>&…` and used it
as the avatar `src` on every admin page — an undeclared outbound of staff names to an unvetted third party
(Article 9 context) and a broken image on any locked-down network.

**Provider chosen: local initials, data-URI SVG.** New `App\Support\InitialsAvatarProvider` renders the
initials into an inline SVG returned as `data:image/svg+xml;base64,…` — no request leaves the server.
Brand blue #2563eb background, white initials, self-hosted Inter with fallbacks. Multibyte-safe so
accented Spanish names (Ángel Ñoño → ÁÑ) render correctly. Registered via
`->defaultAvatarProvider(InitialsAvatarProvider::class)` on the admin panel. The panel now renders with
zero external hosts — proven by a test that GETs a panel page and asserts `ui-avatars.com` never appears.

**Outbound sweep — what it found: nothing else.** Grepped `resources/{views,js,css}`, `public/sw.js` and
the panel config for any `http(s)://` host, CDN, Google Fonts, gravatar, or external API. The only hit was
ui-avatars (now removed). Inter is already self-hosted via the build (vendored woff2). So the only outbound
personal-data flow that genuinely remains is **Resend** (email delivery) — a legitimate processor.

**RAT reconciled.** `Rat.php` claimed "Sin cesiones" on every activity, including the ones that send email —
a drift, since email addresses + names go to Resend. Declared Resend as the email processor (encargado del
tratamiento) on RAT-01 (transactional mail: login link, approval) and RAT-06 (member communications), with
an honest international-transfer note (the provider may process outside the EEE, subject to its contractual
safeguards) rather than the previous blanket "Sin transferencias." ui-avatars was never in the RAT and is now
truly gone, so no removal was needed there — the fix makes the "no undeclared outbound" claim true again. A
test asserts the RAT names Resend and never ui-avatars. 504 green. Owner-authorised merge (wrapper: "merge to
main and run this").

---

## Prompt 63 — genetic price editing surface (closes the hole prompt 48 documented)

`GeneticPrice` rows — the single most important money figure in the product — had NO edit surface
anywhere; they could only be set by the seeder or a factory. This blocked prompt 48 (no price changes to
audit) and prompt 54 (no way to expose the per-price low-stock threshold).

**Surface: a relation manager on the Genetic resource** (`GeneticPricesRelationManager`), NOT a dedicated
resource. Rationale: a price is an attribute OF a genetic and is managed in its context — the tab shows
that one genetic's prices across every location and tier, which is the question an admin actually asks
("what does THIS strain cost?"), and it scopes each list to one genetic instead of a giant flat org-wide
table. Recorded here as the deliberate pick.

**One writer: `App\Actions\Pricing\SaveGeneticPrice`** — the counterpart to `ResolvePrice` (which stays
the single READER; this branch adds a way to WRITE, not a second way to read). It sets the ONE price
column `unit_type` allows (per gram for WEIGHT, per unit for UNIT) and nulls the other, so the wrong
column can't be set by construction — the model's existing saving guard then enforces it. Tier → base
precedence is untouched: a tier row (tier_id set) wins for that tier's members, everyone else and a
deleted tier row fall back to the base (tier_id null) — tested including the delete-fallback.

**Money at the edge:** virtual `price_eur` field, converted with `round_half_up($eur * 100)` (the tier-form
pattern). `low_stock_threshold` is exposed as grams only for WEIGHT genetics — the column is `*_cg`
(centigrams = a weight); a per-UNIT low-stock threshold is left to prompt 54, which owns the consumer.

**Audit:** every write is `genetic.price.updated` inside `SaveGeneticPrice`'s transaction (prompt 48
placement), before/after carrying the real cents — filling exactly the hole prompt 48's `EditGenetic`
comment named (comment now updated). **Gated on `prices.manage`** — declared since the start but never
enforced anywhere; this is its first real use (like `stock.take` in prompt 47). Snapshot integrity tested:
editing a price never changes a past dispensation's frozen total. 510 green. Owner-authorised merge
(batch 3 depends on this being merged so prompt 54 can build on it).

---

## Batch 3·MUST — Prompt 51: member resource completeness (the biggest single item)

Filled the gaps the audit named:
- **Four missing relation tabs** — `Consumption` (dispensations), `Visits` (check-ins), `Sanctions`,
  `Avalados` — all read-only, org-wide (drop the location scope so a socio's whole history shows),
  registered in `MemberResource::getRelations()`.
- **Carencia waive wired up** — `WaiveCarencia` existed with ZERO callers; added a `Levantar carencia`
  header action, gated on `carencia.waive` and only offered while the member is actually in carencia,
  reasoned + audited (`member.carencia.waived`). Denial tested.
- **Resumen** — added the wallet balance (live from the ledger, summed across locations) and the
  consumption-limits gauge (the SAME `ResolveMemberLimits` figures the POS/PWA show, resolved against
  the active sede or the member's latest active membership).
- **List** — a wallet balance column + a sede filter (members with an ACTIVE membership there).

**N+1 (the Rules-section risk):** the wallet balance list column is a DERIVED value on a table that can
hold thousands of rows. It is computed with a single `withSum` aggregate subquery (dropping the location
scope so it's the cross-location total), NOT a per-row `Wallet::balance()` call — so the list stays one
query regardless of row count. Tested that the aggregate returns the correct across-location total.
PHPStan needed `getAttribute('wallet_transactions_sum_amount_cents')` (the dynamic aggregate attribute
isn't a declared model property). 516 green. Self-merge on green (batch 3 authorisation).

---

## Batch 3·MUST — Prompt 53: enforcement matrix editor

The highest-stakes setting — BLOCK/WARN/OVERRIDE per rule, at the DOOR and the COUNTER — could only be
changed via tinker. Built `ManageEnforcement`, a Filament page (nav group "Sistema") that reads/writes
the single `enforcement` Setting `Settings::enforcement()` already consumes, gated on `settings.manage`
(owner only), every change audited as `settings.enforcement.updated`. A Select per (surface, rule) with
BLOCK / WARN / OVERRIDE.

**Locked cells — `aforo` AND `age`, both fixed to BLOCK, not editable:**
- `aforo` was already documented as deliberately BLOCK — you can't "warn" a room over capacity and still
  admit people; capacity is a hard cap.
- **`age` — DECISION (the one the prompt asked me to take): age is LOCKED to BLOCK too, i.e. removed from
  the editable set.** A Spanish CSC legally cannot admit or dispense to a minor; letting `age` be set to
  WARN (admit a minor with a warning) or OVERRIDE (a permissioned override past the age gate) would be
  legally indefensible and is exactly the kind of thing that sinks the club's judicial-tolerance posture.
  Age is a hard legal floor, not a club-configurable policy. So the editable rules are carencia,
  membership, sanction, debt, unpaid_fee, daily_limit, monthly_limit; age + aforo render disabled at BLOCK.

The lock is enforced SERVER-SIDE in `save()` (a disabled field isn't submitted, and even a tampered
submit for age/aforo is forced to BLOCK), and unknown modes fail safe to BLOCK — tested with a tampered
submit. `OVERRIDE` is offered where it's legitimate (a manager+ permissioned override of carencia/limits/
debt), matching the existing override affordances at the counter. 519 green. Self-merge on green
(batch 3 authorisation).

---

## Batch 3·MUST — Prompt 58: QR scan rate limit + audit retention decision

Two loose ends from prompt 17.

**QR scan rate limit (a real fix).** `ResolveMemberByToken` had NO throttle and is called from Livewire
(counter check-in + dispensary POS), so route middleware never applies — a scanner could try token after
token to brute-force a valid card. Added an in-Action throttle (`RateLimiter`, the app's `UnlockOperator`
pattern), keyed by operator/IP, tunable via a new `qr_scan_max_failures_per_minute` setting (default 10,
now on the org settings form). Design choice: **only FAILED scans count** toward the limit — a busy
counter scanning valid cards is never throttled, but repeated misses (the brute-force signal) are stopped
and refused with `ScanRateLimitedException`, which both counters catch and flash. Backward-compatible: a
call with no throttle key (e.g. the member PWA flow) is unthrottled, so prompt-15's PWA tests stay green.

**Audit retention — DECISION: Option B (the prompt's steer, taken explicitly).** The audit log is a
compliance/forensic record whose whole point (prompt 17 / RAT-07) is to evidence PAST accesses — purging
it would destroy exactly that. And it's already append-only BY CONSTRUCTION: the `AuditLog` model throws
on both `updating` and `deleting`, so no purge could even run. Therefore I did NOT build a purge; instead
I made the setting honest — `audit_retention_days` is a **MINIMUM/disclosure** figure (surfaced in the
audit-log subheading, the RAT and the health panel), NOT an auto-delete trigger, and the settings-form
helper text now says so. Contrast with `data_retention_days`, which IS a real purge trigger (`members:purge`
anonymises members) — the asymmetry is deliberate. Pinned with a test that deleting an audit row is
refused (retention by construction). 523 green. Self-merge on green (batch 3 authorisation). **Batch 3
MUST (51, 53, 58) complete.**

---

## Batch 3·TIME — Prompt 54: low stock per genetic (the consumer that never existed)

Genetics had a `low_stock_threshold_cg` column (per `GeneticPrice`) and an org-wide `low_stock_threshold_cg`
fallback setting, but NOTHING consumed either — only articles had working low-stock alerting. Added the
consumer, mirroring the article pattern (`stock <= low_stock_threshold` → a POS badge):
- `Genetic::lowStockThresholdCg(?locationId)` resolves the base `GeneticPrice` row's threshold at the sede,
  else the org-wide setting.
- `Genetic::isLowStockAt(remainingCg, ?locationId)` — at-or-below, matching the article rule.
- `DispensaryPos` genetic cards carry a `low_stock` flag; the blade shows a "Stock bajo" warning badge
  (only when a dispensable batch exists — an out-of-stock genetic shows "Sin lote", not "Stock bajo").

**Reconciled prompt 63's deferral.** The card's `remaining_cg` already normalises a UNIT genetic's stock
to a gram-equivalent (units × grams_per_unit_cg), so ONE threshold comparison serves both weight and unit
genetics. That let me remove the weight-only restriction prompt 63 put on the threshold form field (it had
noted "per-unit is prompt 54") — the `low_stock_threshold_cg` is now settable for every genetic as a
gram-equivalent, with a per-type helper text. Resolver precedence + the POS flag are tested. 526 green.
Self-merge on green (batch 3 authorisation).

---

## Batch 3·TIME — Prompt 56: emailable receipts + three unwired push notifications

Three fully-built notifications had ZERO dispatch sites; receipts couldn't be emailed. Wired all four:
- **MembershipExpiringNotification** → the EXISTING `memberships:sweep` (no second sweep), alongside the
  email, under the SAME `reminder_sent_for` marker so both channels send once per period. The email is
  still skipped for an email-less socio, but the push now reaches them.
- **EventReminderNotification** → a new nightly `events:remind` command (`RemindUpcomingEvents`) calling
  the notification's existing `dispatchFor()`. Idempotent via a new per-event `reminder_sent_at` marker
  (migration; MySQL parity PENDING — MySQL won't start in this env), tunable lead via
  `event_reminder_lead_hours` (a scheduler constant, excluded from the settings-form completeness gate).
- **LowBalanceNotification** → dispatched from `RecordWalletTransaction`, AFTER commit, only when a DEBIT
  CROSSES from at-or-above to below `low_balance_threshold_cents` (a new €-edge setting on the wallet
  form) — so it warns once on the drop, never repeatedly while already low. Tested both the crossing and
  the no-re-push.
- **Emailable receipt** → `DispensationReceiptMail` (scalar-data mailable, worded aportación not venta;
  CID logo via `$message->embed`), registered in `DevMail` so it rides the existing `MailRenderTest`
  (render + inline-logo assertion) and the `/dev/mail` preview; sent by a new `emailReceipt()` action on
  the dispensary POS's last-dispensation block. 530 green. Self-merge on green (batch 3 authorisation).

---

## Batch 3·TIME — Prompt 55: bar article discounts (shape decided first)

The bar had no discount path — even though `DiscountAppliesTo::ARTICLE` existed from the start (the case
was built and never consumed, a prompt-08→12 handoff gap).

**Shape decided BEFORE writing code (the prompt's instruction) — reuse the existing member Discount
system, do NOT build a framework:** a member's PERCENTAGE `Discount` that explicitly `applies_to` ARTICLE
or BOTH now discounts their bar order. Deliberate bounds:
- **Percentage only.** A fixed-amount discount on a whole order raises "which line?" distribution
  questions nobody asked to answer; percentages apply cleanly per line. Fixed-amount article discounts
  are out of scope.
- **Only explicit ARTICLE/BOTH discounts** (assigned standard Discounts + a therapeutic discount whose
  applies_to includes articles). A **custom per-member** discount (the cannabis-side override) does NOT
  leak onto beer — different act, deliberately excluded.
- **Guests get nothing** (no member ⇒ no discount).

**Single resolver, no desync:** `App\Actions\Pricing\ResolveArticleDiscount` (the counterpart to
`ResolvePrice`) is called by BOTH the bar POS display (`basketView`) AND `CommitOrder` (`buildItems`), so
the total the operator sees is exactly the total charged. The item snapshot gains a `discount_cents`;
`line_total_cents` stays the NET, so `BarSalesReport` still reconciles (tested). The bar receipt shows the
member discount. Tests: applied, guest-none, genetic-only-excluded, and shown==charged. 534 green.
Self-merge on green (batch 3 authorisation). **Batch 3 IF-TIME (54, 56, 55) done; 45 next.**

---

## Batch 3·TIME — Prompt 45: application invite email (the one that stalled when delegated)

Done in-session, not delegated (the reason it stalled before). Five pieces:
- **Nullable `applicant_email`** on `member_applications` (migration; MySQL parity PENDING) + fillable.
- **Optional email field on the invite form** (`ListMemberApplications::inviteAction`) — if given, the
  invite is EMAILED; if blank, it's link-only as before.
- **`ApplicationInviteMail`** modelled on `MemberLoginLinkMail` (scalar URL + expiry, CID logo, registered
  in `DevMail` so it rides `MailRenderTest` + `/dev/mail`). The raw link lives only in the email; the DB
  keeps the token hash.
- **Resend action** — re-emails the SAME token (rebuilt via `inviteUrl()` from the encrypted `invite_token`),
  visible only when there's an applicant email + a live invite. Tested that the resent URL == the original.
- **Real clipboard copy** — "Copiar enlace" used to just re-dump the URL in a toast. It now calls
  `$livewire->js('navigator.clipboard.writeText(...)')` (client-side, within the click's transient
  activation) and confirms "Enlace copiado" instead. Tested via the notification change.

538 green. Self-merge on green (batch 3 authorisation). **Batch 3 fully built (51, 53, 58, 54, 56, 55, 45).
Remaining in batch 3: prompt 57 = recommendation only (no build).**

---

## Prompt 57 — configurable per-club terminology  —  `OVERNIGHT-DEFAULT — CONFIRM:` RECOMMENDATION: DO NOT BUILD

Per the batch-3 instruction, prompt 57 is owner-decision-only — a recommendation, not a build. My
recommendation is to **CUT it** (do not add configurable per-club terminology). Reasoning:

1. **The vocabulary is legally load-bearing, not cosmetic.** `socio / aportación / contribución /
   dispensación / aval / carencia / arqueo` is the exact wording that keeps the club inside the
   non-profit, shared-cost, judicial-tolerance model. CLAUDE.md is explicit: never let "translate" slip
   into commercial framing (customer/sale/profit), and the bar/merch ledger is deliberately kept on
   *venta/ticket* wording so it can NEVER bleed into the cannabis side. A free-text, per-club terminology
   override is precisely the mechanism by which a club could rename "aportación" → "precio/venta" and
   quietly dismantle that posture — in the software the AEPD/inspection reads as the club's own record.
   That is a compliance regression disguised as a preference.

2. **The legitimate need is already met two ways.** (a) LANGUAGE differences are handled by the i18n
   system — English + Spanish today, and a new locale is a reviewed `lang/*.json`, centrally controlled,
   not a per-club free-text field. (b) "Our club calls its grades/tiers X" is already **user data**
   (Category names, MembershipTier names) that is NOT routed through `__()` and shows verbatim — so a
   club already names its own categories without any terminology framework.

3. **YAGNI + the "simplest solution wins" rule.** A configurable-terminology layer is a framework nobody
   has asked to use in a way the two mechanisms above don't already cover, and it carries real downside
   (compliance, translation-key parity churn, testing surface).

**Proposed decision (CONFIRM):** do not build prompt 57. If a real need surfaces, meet it with a new
reviewed LOCALE, never a per-club commercial-framing override. If the owner still wants per-club wording,
scope it narrowly to a whitelist of non-legal labels with the cannabis/non-profit terms LOCKED (the same
lock pattern prompt 53 used for age/aforo) — but that is a deliberate, separate prompt, not this one.

---

## Prompt 67 — cash (not-from-till) expense option

The admin `ExpenseForm` deliberately omitted `TILL_CASH` (petty cash is counter-only so the arqueo
reconciles) — but that left NO way to record cash that never touched the drawer (a supplier paid on
delivery, rent, a tradesman, the owner's pocket). Those were mis-recorded as "Other" or forced through a
till they never went through.

**Two kinds of cash, kept apart:**
- **`TILL_CASH` (petty cash)** — unchanged: recorded at the counter (`RecordTillExpense` → `PETTY_CASH`
  cash movement, requires an OPEN session), still NOT selectable in the admin form, and `RecordOverhead`
  still throws if handed `TILL_CASH`.
- **`CASH` (new case)** — selectable in the admin form, flows through `RecordOverhead` which NEVER writes
  a cash movement or attaches a till session. Money out, recorded + reported, zero drawer implication.

**Default decision:** the owner said expenses *should be* cash, so `CASH` is the **default** selection in
the admin form (a click saved on the common path; cheap + reversible). The `ExpensesTable` `paid_from`
column now renders `->label()` (was a no-default `match` that would have thrown `UnhandledMatchError` on
the new case). `FinancialReport`'s `gastos` sums all expenses regardless of `paid_from`, so `CASH` appears
automatically (tested). Approval thresholds / receipts / audit apply unchanged.

**`OVERNIGHT-DEFAULT — CONFIRM:` flag (not built, per the prompt):** if most club spend is cash-from-the-
owner's-pocket-then-reimbursed, that is a **liability to the owner**, not merely an expense — a different
accounting shape (a payable that's later settled). It's cheap to model early and expensive to reconstruct
at the accountant's. Recommend a future prompt add an optional "reimbursable / owed to <person>" flag on
cash expenses; do NOT infer it from `CASH` alone. Owner to confirm whether to pursue.

---

## Prompt 68 — rename "bar sales" → "Barra y tienda" (reporting layer only)

**Chosen term: "Barra y tienda"** (bar + shop). Weighed against the alternatives: *Ventas* is too broad
(and this app reserves *venta/ticket* for the non-cannabis POS/receipt already), *Tienda* alone drops the
bar, *Otros ingresos* is accurate but bloodless. "Barra y tienda" is honest about what the stream actually
contains (drinks/food **and** merch — the catalogue always held lighters + rolling papers) and stays
clearly distinct from the cannabis *aportación* stream. Added to the CLAUDE.md vocabulary table.

**Scope — reporting/vocabulary only; the counter is untouched (as the prompt steered):**
- Renamed the DISPLAY labels: `BarSalesReport` title + summary, `BarSalesReportPage` nav/tab, the
  `FinancialReport` + `AgmPackReport` income COLUMN label, the income chart datasets, the member's PWA
  history section, the Orders resource/tab, the admin nav group + Article resource, and the till arqueo's
  bar-cash line.
- **Left alone:** `BarPos`, the `/counter/bar` route, the `pos.bar` permission, `bar_enabled`, and the
  counter switcher label — which stays **"Barra"** (the physical bar position; `__('Barra')` there is the
  one surviving use, correct). So: the *counter* is still the bar; the *income figure* is bar + shop.

**No figures move (the whole safety property):** I renamed LABELS only. The internal `barra` totals key,
`TillSummary`'s `bar_cash` breakdown key (a documented phpstan type consumed elsewhere), and the report
route slug `/informes/ventas-barra` are ALL unchanged — so consumers, reconciliation and bookmarks don't
break. Tested: the report/nav use the new name, the `barra` key still exists in the totals, and "Ventas de
barra" is gone from BOTH lang files. The existing reconciliation tests (which read the `barra` key) stayed
green, proving the figure didn't move. Route deliberately KEPT (bookmarks) rather than renamed.

---

## Prompt 66 — StrainType (sativa/indica/hybrid) + POS filter labels + orthogonal seed (supersedes 62)

Added a `StrainType` enum (SATIVA/INDICA/HYBRID, `HasLabel`) on `Genetic` — a user-set, fillable property
(unlike observer-derived `unit_type`). Shows on the Genetic form + list (with a filter), the dispensary
POS (a filter row + a card badge), and the member PWA menu.

**Nullable — DECISION.** `strain_type` is nullable with a "Sin especificar" display. An edible or a
CBD-dominant variety legitimately has no strain type; forcing one would make a club lie about a product.
The migration + cast + all surfaces handle null (the badge/filter simply omit it; a null-strain genetic
still shows under "Todas"). Tested.

**Filter rows — DECISION: keep all three, LABELLED.** The two POS rows were unlabelled and read as one
filter duplicated in two languages. I labelled each (Categoría / Tipo / Variedad — a visible heading +
`role="group" aria-label` for a screen reader) and added strain as the third row. Chose labelled-3-rows
over merging or dropping a filter because they are genuinely orthogonal axes (a FLOWER can be sativa or
indica; a category is club grading) — now that the seed makes them orthogonal, all three earn their place.
Strain is ALSO a chip on the card + PWA menu, so the variety is visible at a glance, not only as a filter.

**Seed — DECISION: orthogonal grading + strain spread (replaces "Flores"-on-everything).** The demo
created one genetic category, "Flores", on every (all-FLOWER) genetic — which is what made the filters look
duplicated. Replaced it with a house GRADING (`Premium` / `Estándar`) assigned independently of strain and
type, and gave the six genetics a strain spread (sativa×2, indica×2, hybrid×1, and a CBD variety with NO
strain). So a Variedad selection returns a set distinct from any Categoría or Tipo selection — the
regression guard against the axes collapsing back into duplicates (tested).

**Untranslated data names — NOTE (no code, per the prompt).** Category names are club-entered DATA
(`Premium`/`Estándar` in the seed are Spanish and show verbatim in an English UI), NOT routed through
`__()`. This matches the existing DECISIONS precedent for stored ledger descriptors: persisted club
vocabulary stays as entered; localizing it is a separate concern for a future prompt. **Strain type itself
IS an enum, so it translates** — the right call for a fixed vocabulary. Supersedes prompt 62 (dropped).
Owner-authorised merge.

---

## Prompt 69 — Bar report double-rows (per-location articles)

With the seeder fixed (batch 2·0), the Bar report finally shows real money — but rendered every article
TWICE with identical labels at an All-locations scope. `Article` is per-location (`ScopedToLocation`): each
sede has its own Agua/Café/etc., distinct ids, so `BarSalesReport`'s group-by-`article_id` produced two
correct-but-indistinguishable rows once the report only showed the name.

**A fixture defect had been masking a product defect** — exactly the risk the fixtures-through-the-writer
rule warns about. Before the seeder fix, seeded orders carried no `article_id`, so everything fell down the
off-catalogue `m:name` path and merged by name into one row per article. Fixing the seeder to write real
`CommitOrder`-shaped data (with `article_id`) exposed a presentation bug that was ALWAYS there for real
orders. Noted as a case where a green-looking fixture hid a real defect.

**Option chosen: a Sede column, shown only at a multi-location scope** (`count(resolvedLocationIds()) > 1`),
hidden for a single location (no redundancy). Honest, simple, and it PRESERVES the correct per-article
grouping — this is a presentation fix, not a grouping change. Rejected "aggregate by name across sedes"
because two sedes may stock same-named articles at different prices, which merging would hide. The period
total is untouched (grouping unchanged), and the reconciliation with `FinancialReport`'s Barra column still
holds across sedes (tested). Manual off-catalogue lines (`m:name`, no location-bound article) still merge by
name across sedes and show a "—" sede. CSV/PDF exports read the SAME `ReportTable` (columns + rows), so they
can't diverge from the screen.

**Sibling check (as required):**
- **StockReport** — CLEAR. It groups by BATCH and renders the unique `batch_no` (`lote`), so two same-genetic
  batches at different sedes are never indistinguishable (the batch number tells them apart). Not the
  identical-row bug. (A sede column there is a reasonable future nicety, not this fix.)
- **ConsumptionReport** — CLEAR. It groups by MEMBER (org-wide, not per-location) and shows the unique
  `member_no`; its by-genetic table groups by genetic (org-wide). No per-location-same-name ambiguity.

553 green. Owner-authorised merge.

---

## Prompt 64 — price override at the dispensary counter (comps / goodwill / owner takings)

A permissioned, reasoned price override on `DispensaryPos` → `CommitDispensation`. It changes what the
member pays; it changes NOTHING else.

**Per-line vs whole-contribution — DECISION: whole-contribution.** The operator sets the total the member
pays for the WHOLE basket (in euros), not a per-line rate. The owner's ask ("adjust the price at
checkout", "give it free for mouldy flower") is about the total; distributing an override across a
multi-line basket adds arithmetic and ambiguity for no benefit the cases need. **Reduce-only** (0 ≤
override ≤ resolved) — the override comps/discounts, it never charges ABOVE cost (that would undermine the
at-cost model). Zero is valid (the free case) and goes through the identical permission + reason + audit
path — no special "free" shortcut.

**Permission — new, dedicated `dispensation.price.override`** (OWNER + MANAGER). NOT reused from
`limits.override` or `membership.fee.override`: different act, different risk, a club grants them
separately. First real use of the new permission; enforced SERVER-SIDE in `CommitDispensation` (not just
the UI `@can`), with a mandatory free-text reason (reuses the limit-override interaction).

**Storage + audit.** `total_cents` stays the CHARGED (overridden) figure so the tender invariant
(cash+wallet==total) still holds; `original_total_cents` records the RESOLVED price (null = no override),
so the override is fully reconstructable. Every override is audited `dispensation.price.override` (before:
resolved, after: charged + reason + authoriser + operator) INSIDE the commit transaction (prompt 48).
**Limits/eligibility are NOT bypassed** — the limit check runs before pricing, so a member over their
limit is still blocked even at €0 (tested). **Reporting:** the total value forgone to overrides for a
period is surfaced on the ConsumptionReport summary ("Ajustes de precio"), so a manager can answer "how
much left below cost, and why" without grepping the audit log.

**Owner-takings — DECISION: do NOT build a separate mechanism (per the prompt).** The owner is a socio; if
they consume product it must be DISPENSED to them AS A MEMBER — counted against their limits, present in
the register, visible in reporting like anyone else. If it's free, that is a €0 override through this
prompt's normal permission + reason + audit path. Routing personal consumption through `MERMA`/`ADJUSTMENT`
would hide exactly the thing that most needs to be visible and would make the club's own numbers describe
shrinkage that didn't happen. `MERMA`/`ADJUSTMENT` stay correct only for product NO member consumes (waste,
mould write-off, samples, destruction). Nothing extra was built for owner takings — it already works.
Owner-authorised merge (the prompt's "do not merge" overridden by the owner's standing "merge it all").

---

## Prompt 65 — partial + cash refunds (money back without voiding the whole thing)

A member can already be made whole by VOIDING a whole dispensation — but a void is all-or-nothing, returns
money only to the wallet, and always puts the product back as sellable. That misses the real cases: refund
PART of a basket, hand back CASH, and write off product that came back unsellable. Built `Refund` (model +
`refunds` table, append-only, one dispensation → many refunds) and `App\Actions\Dispensing\RefundDispensation`,
reusing VoidDispensation's mechanics (grams through the single stock writer, money through the wallet/till
writers) rather than duplicating them. The original dispensation is NEVER mutated — it stays COMPLETED.

**Cash vs wallet — the accounting gap a void doesn't have.** A full void is self-correcting: dropping the
row out of the COMPLETED-only arithmetic un-takes its cash automatically. A PARTIAL refund leaves the row
COMPLETED, so nothing self-corrects. **WALLET** refund → `RecordWalletTransaction(REFUND)`, no till session
(a wallet credit is not a drawer movement — same as a void). **CASH** refund → `RecordCashMovement(OUT)` on
the OPEN till, so `TillSummary` drops the expected drawer figure by exactly the refund and the arqueo still
reconciles. **A cash refund with no open till is refused** (there is nowhere for the money to come out of).

**Stock destination — the operator's explicit choice, no silent default.** `RefundDestination::STOCK` →
good product back to its originating batch (a positive `ADJUSTMENT`, sellable again). `RefundDestination::MERMA`
→ returned-but-unsellable: an `ADJUSTMENT` (+grams) immediately followed by a `MERMA` (−grams), so the ledger
honestly records "came back, then written off" and the **net effect on sellable stock is zero — it never
re-enters sellable stock**. MERMA is gated on the existing `stock.merma` permission inside `RecordStockMovement`.
A refund can be money-only (grams 0, no stock movement) or product-inclusive.

**Never over-refunds — enforced server-side, concurrency-safe.** Cumulative refunded amount AND weight can
never exceed the original charge / dispensed grams. Both sums are read INSIDE a `lockForUpdate` on the
dispensation header, so two genuinely concurrent partial refunds serialise — the second sees the first's
committed row before its own guard runs (the property is asserted by the repeated-partials test; the row
lock itself is exercised under MySQL in CI, since SQLite has no row locks).

**Refund policy — DECISION: a time window Setting (`refund_window_days`, default 30; 0 = no limit).** The
prompt asked for one real control (window OR approval threshold). Chose a window: it is the simplest concrete
REVERSIBLE control, a stale-transaction refund is the higher-risk case, and an approval-threshold would need
an approver-routing workflow this platform doesn't have yet. Configurable per-location (regional practice
varies), editable in ManageSettings, resolved through `Settings::get` (degrades to 30 on a stale cache,
never throws). A dispensation older than the window is refused at the counter.

**Permission — DECISION: reuse `dispensation.void` (manager+), do NOT mint a new one.** A refund is a
reversal act in the same risk tier as a void, and the prompt framed refunds as "reusing VoidDispensation's
mechanics." A dedicated `dispensation.refund` would add permission surface for no distinction a club draws.
Recorded as an OVERNIGHT-DEFAULT — CONFIRM: if a club wants staff to issue small refunds without void
rights, split the permission (reversible, additive).

**Reporting.** A period's refund total is surfaced on the ConsumptionReport summary ("Reembolsos"), so
refunds sit alongside takings, not only in the audit log. Every refund is audited `dispensation.refunded`
(before: refunded-so-far, after: amount + grams + destination + method + reason + authoriser + cumulative)
inside the transaction.

**Bar/merch refunds — DECISION: DEFERRED (stated, not silently skipped).** `VoidOrder` already fully
reverses a bar order (unit stock back, wallet spend reversed, cash off the till). A PARTIAL bar refund
would need the same Refund machinery generalised over `Order`/`items` (unit lines, not weight) — a clean,
focused follow-up. Not built here to keep this prompt to the dispensary cannabis path the owner described
("refund a member for mouldy flower"); documented so it is a decision, not an omission.

**Counter UI — DECISION: DEFERRED, explicitly (no inert placeholder shipped).** The existing counter void
affordance only "undoes the LAST just-committed dispensation" (`$lastDispensationId`). A refund is a LATER
partial correction against a PRIOR dispensation — it needs a prior-dispensation lookup + a refund modal
(amount / grams / destination / method / reason), a surface that does not exist yet (there is no Filament
Dispensation oversight resource either). The tested Action is the reusable core; wiring the lookup+modal is
a focused follow-up. Per the testing rule (tests prove correctness, not completeness — do not ship a dead
button), the feature is complete and correct at the mechanic layer and the UI is a named next step, not a
half-wired control. Owner-authorised merge (the prompt's "do not merge" overridden by the standing "merge
it all"). 572 green.

---

## Prompt 70 — locale-aware demo seed + a demo profile with the optional features switched on

Two problems: (1) an English UI sat on Spanish demo data ("Sede Centro", "Flores", Spanish names) — reads
as a half-finished translation; (2) six optional features ship OFF, so a fresh install can't demonstrate
them at all. Fixed both without touching production defaults.

**Locale-selection mechanism — DECISION: pick the string set from `app()->getLocale()` at seed time.**
`DemoDataSeeder::localeStrings($locale)` is a small keyed array (locations, grades, tiers, discounts, bar
articles, opening-stock/balance reasons) — DATA written once, so it deliberately does NOT go through
`lang/` files (that is for UI rendered per request). The faker locale comes from the SAME source
(`es → es_ES`, `en → en_GB`): the seeder sets `config('app.faker_locale')` and resolves an explicit
`\Faker\Generator` from it, so generated names match the chosen language instead of being independently
Spanish. Default `APP_LOCALE=en` now yields an all-English demo; `APP_LOCALE=es` yields all-Spanish.
**Strain names are left as-is** (Amnesia Haze, Northern Lights…) — proper nouns, identical in every
language. `ExpenseCategorySeeder` is likewise locale-aware now (an English club gets "Consumables",
"Rent"…); its stable identity is the KIND, so the demo's petty-cash lookup uses `default_kind = TILL`,
never the localised name.

**Test that stopped depending on a Spanish literal.** `ExpenseCategorySeederTest` asserted
`$byName['Consumibles']->default_kind === TILL`. Names are now locale-aware, so that literal dependency was
itself the smell the prompt names — rewritten to assert the SHAPE (exactly one TILL category + six
OVERHEAD, all active), which holds in any locale. No other test depended on a seeded literal (BarReport /
BarSales tests build their own inline locations/articles; LibrarySmokeTest's `'Socio'` is a report column
header, not seed data).

**Rule 1 honoured — `Settings::DEFAULTS` is UNCHANGED; the profile writes rows.** `seedDemoProfile()`
calls `Settings::set(...)` exactly as an admin would. A test asserts both halves: the rows are present via
`Settings::get()`, AND `Settings::DEFAULTS['wallet_debt_allowed'/…]` are provably still the conservative
values (the test fails the moment a default is flipped in code).

**Which optional settings the profile enables, and why.** Enabled (the four required + a door threshold):
`wallet_debt_allowed` with `wallet_debt_limit_cents=5000` (€50 counter cap — debt-allowed with a €0 cap
demonstrates nothing) and `wallet_door_debt_threshold_cents=3000` (€30, LOWER than the counter cap so a
member can be past the door limit yet within the counter limit — both blocks visible at once);
`temporary_members_enabled`; `camera_scan_enabled`; `signature_on_dispensation`. **Left OFF deliberately:**
`restrict_pos_to_checked_in` (would block dispensing to any un-checked-in member — muddies the "can dispense
to anyone" demo and the regression guard; better shown live by toggling), `discounts_stack` (subtle —
needs ≥2 overlapping discounts on one member to read as anything, risks confusing the demo pricing),
`ring_fenced` (changes wallet settlement semantics org-wide; balances are already per-location so the debt
demo doesn't need it, and making it legible needs a cross-location settlement scenario heavier than it's
worth here). Each left-off flag is a "use judgement" call the prompt invited.

**Data behind every switched-on flag (a flag with no data demonstrates nothing).** A member in debt at
−€40 (within cap, past door threshold) and one at −€48 (near the cap); a TEMPORARY member expiring in 3
days (removal-reminder path); an in-carencia member; a membership expiring in 5 days; a member carrying a
WARNING sanction (so prompt 51's sanctions tab isn't empty) — on top of the existing one-of-each-status
spread. **Every active member's fee is PAID via `RecordFeePayment`**, so the demo isn't fee-blocked from
dispensing to anyone (prompt 46's `unpaid_fee` block) — asserted end-to-end by a live `CommitDispensation`
succeeding on a freshly seeded member.

**Rule 2 honoured — new records go through their domain writer.** Memberships via `EnrolMembership`, fees
via `RecordFeePayment`, wallet (opening balances + seeded debt) via `RecordWalletTransaction`, opening
stock as a real `RecordStockMovement` INTAKE (batch created empty, then intaken — the go-live path, no
free-typed `remaining_cg`), the fortnight's stock depletions + wallet contributions likewise through the
writers, orders through `CommitOrder` (the batch-2 fix). **Carve-out kept + re-documented:** the
fortnight's back-dated `Dispensation`/`DispensationLine` stay relational-with-full-snapshot — the
compliance-boundary carve-out already in CLAUDE.md, because `CommitDispensation` would REJECT historical
demo data (carencia/limits/fees for a past day). The LIVE `CommitDispensation` path is exercised by the
regression test instead.

**Clean profile — RECOMMENDATION (not over-built, per the prompt).** The seeder remains the single "rich"
profile. A minimal/empty club is best added as a selectable profile via an env flag (e.g.
`DEMO_PROFILE=minimal` gating the fortnight + feature members) or a separate `MinimalDemoSeeder` — a small,
additive change when someone actually needs the empty shape. Not built now to avoid a second code path with
no current consumer.

**Screenshots:** the acceptance evidence the prompt asks for (dashboard / member list / POS in both
locales, light+dark) could NOT be produced — no browser in this environment. The locale correctness is
instead verified programmatically (`DemoSeedProfileTest` asserts the seeded literals + faker locale under
both `en` and `es`). Flagged so a human runs the visual pass. Owner-authorised merge (the standing "merge
everything to main" overrides the prompt's "do not merge"). 578 green.

---

## Prompt 72 — the declared forecast can be edited, but the signed declaration must never silently drift

`UpdateDeclaredForecast` was the only action in `app/Actions/` with zero callers: `declared_monthly_cg` was
edited inline on the member form, bypassing it. The mild consequence was a vocabulary loss (a generic
`member.updated` audit instead of `member.forecast.updated`); the real one was that a club could hold a
member's SIGNED declaration saying 100 g/month while its own record said 40, with nothing indicating the two
disagreed — exactly the documentary inconsistency an inspection surfaces.

**Routing — DECISION: take it off the generic form, make it a dedicated record action.** The inline
`TextInput` is gone from `MemberForm`; `UpdateDeclaredForecast` is now the SINGLE writer of
`declared_monthly_cg`, reached via the "Actualizar previsión declarada" record action (grams → centigrams at
the edge, gated on `members.edit`, audited `member.forecast.updated`). A declared legal figure is not a phone
number — editing it alongside contact details understated it. The column is nullable, so a new member simply
has no declared figure until it is set through the action (also where the declaration document is then
generated). Regression-guarded two ways: an explicit `assertFormFieldDoesNotExist` test, and the
`FormCompletenessTest` allowlist (declared_monthly_cg allowlisted with a reason; its honesty test fails if the
field ever returns to the form).

**Drift handling — DECISION: FLAG the drift; do not regenerate, do not block.** A DERIVED indicator
(`App\Support\DocumentDrift` + `Member::hasStaleDeclaration()` / `driftedDocuments()`) compares a generated
document's frozen snapshot against the live record on READ — never a stored flag that could itself go stale
(a project rule). Surfaced as a "Desactualizada — regenerar y volver a firmar" badge on the documents tab and
a toggleable warning column on the member list. Rejected alternatives: **regenerate automatically** replaces
signed evidence with an UNSIGNED artefact — worse than the drift, and it silently discards a signature; **block
the edit** until re-signed is too rigid for correcting a typo. Flagging leaves the immutable signed document
untouched (asserted: the snapshot is unchanged by the edit) and puts a human in the loop exactly where a
signature is required. The action's helper text warns that changing the figure will require regenerating.

**Sibling documents — ANSWER: yes, they drift too, so the mechanism is GENERAL.** `GenerateMemberDocument`
freezes the SAME snapshot (name + document number + declared forecast) for every type, so a REGISTRATION_FORM
drifts if the member's name or document number changes after it was generated. `DocumentDrift` is therefore
type-driven, not declaration-specific: `DECLARATION` is stale on a `declared_monthly_cg` change;
`REGISTRATION_FORM` on `declared_monthly_cg` / name / document-number change (both tested). Point-in-time types
(`CONSENT`, `ID`) are deliberately NOT drift-checked — a consent is valid exactly as signed and does not
"drift" because an unrelated figure moved. The libro de socios is an org-wide report/export, not a per-member
`MemberDocument`, so it is out of this mechanism's scope.

**Member-facing route — NOTED, accommodated, not built.** `forecast_options_g` + the PWA self-declaration
idea (a member picks their own forecast from a set of options) fits this design without rework: the PWA route
would call the SAME `UpdateDeclaredForecast`, and the drift flag would then prompt staff to regenerate and
re-sign — which is the correct division (the member declares; the club re-issues the signed evidence). Left as
a future branch.

**Also fixed in passing:** `MemberFormTest`'s forecast test (which asserted the now-removed inline field) was
repurposed to exercise the new action; and a latent flaky assertion in prompt 70's `DemoSeedProfileTest` (the
dispense-regression could pick a seeded member who had already consumed toward the daily cap during the
fortnight) was tightened to exclude members who dispensed today — a daily-limit concern, not the fee block
under test. Owner-authorised merge (standing "merge everything to main"). 584 green.

---

## Prompt 71 — refunds work perfectly and there was no way to trigger one

Prompt 65 shipped `RefundDispensation` complete, correct and tested, with (honestly, deliberately) no UI —
zero callers. This branch builds only the surface that calls it; it re-implements nothing (no over-refund
check, no stock branch, no window check — all already in the action).

**Surface — DECISION: a read-only Filament `DispensationResource` (oversight) with a refund action, not a
POS flow.** Chosen for the reasons the prompt lays out: it is far cheaper, it unblocks refunds immediately,
it keeps a money-out control off the busy counter until the club decides who holds it, and — the deciding
factor — **dispensations had no oversight surface at all**, exactly the gap `OrderResource` was added to
close for bar sales in prompt 43. So this closes a real second gap. The resource mirrors
`OrderResource`/`TillSessionResource`: `canCreate() = false`, a filterable list (the "which dispensation?"
lookup — by member, reference, status, location, date) and a View page whose only header action is the
refund. The POS-side refund (member standing at the counter) remains a legitimate future addition; the admin
surface is the right first one.

**The cash / open-till tension — RESOLVED, not dodged.** A cash refund needs a drawer to come out of (an
open till), which is a counter-side constraint on an admin surface. Resolution: the refund offers **wallet
always; cash only when an open till exists at the row's location** (found the same way `DispensaryPos` finds
it), and when a cash refund is chosen it attaches to that session so `TillSummary` reconciles. With no open
till, the method field offers wallet only and states why ("Sin caja abierta… solo se puede reembolsar al
monedero"). So the admin surface is NOT wallet-only in general — it does cash whenever a till is open — and
it never offers a cash refund that would have nowhere to come from.

**Every refusal is surfaced, never a silent dead end (prompt 60).** Missing permission → the action is
hidden (the `refund` policy ability). No open till for cash → cash is not offered, with the reason in the
field's helper text. Over-refund / outside `refund_window_days` → the action closure catches
`RefundDispensation`'s exception and raises a danger notification carrying its message. Crucially the UI adds
**no second source of truth**: the amount/weight fields show the remaining figure as guidance but do not
hard-validate; the ACTION decides what is refundable and the UI surfaces its verdict. The stock destination
is an explicit choice with **no default**, and the merma option is offered only with `stock.merma`.

**Remaining-refundable is shown.** `Dispensation::refundedAmountCents()`/`remainingRefundableCents()` (and
the weight equivalents) are queried live (never cached — a money/stock figure) and drive the modal heading,
the field helpers and a "Reembolsos" infolist section (refunded so far · available), so the operator is
never guessing.

**Permission — kept `dispensation.void`.** Added a `refund` ability to `DispensationPolicy` that delegates
to `dispensation.void` + the same per-row org/location visibility check (a manager cannot refund another
sede's row — denial-tested with a tampered `{record}` id). Not split from void: consistent with prompt 65's
deliberate, reversible choice; splitting would be its own DECISIONS entry, not a silent change.

**On the member record.** A `RefundsRelationManager` tab (prompt 51's member tabs) shows the member's
refunds — amount, weight, destination, method, reason — org-wide, so a refund is visible there, not only in
the audit log.

**Screenshots:** the lookup / refund modal / refusal states (light+dark) could not be produced — no browser
in this environment; the flows are covered by `RefundUiTest` (9 tests, incl. the action-parity, remaining,
merma-not-offered, cash-not-offered, over-refund/window refusals, member-visibility and cross-location
denial). Owner-authorised merge (standing "merge everything to main"). 593 green.

---

## Prompt 47 — end-of-day: close the till AND reweigh only the touched flower

The cash-count half of the ask (blind arqueo) existed; the flower-reweigh half was a real gap —
`StockTake`/`StockTakeLine`/`CommitStockTake` were fully built and `stock.take` declared and role-assigned,
but nothing ever called any of it (same shape as the `RecordFeePayment` gap). This wires it in.

**Where the counting screen lives — DECISION: a STEP inside the till close flow**, not a separate screen.
The ask was literally "an end-of-day function ON THE TILL", so the reweigh is a phase in
`TillSession`'s existing close sequence: `startClose()` lands on the flower reweigh first (when required),
and only once it is committed does the blind cash count proceed — one end-of-day ritual, not two
disconnected screens. `CommitStockTake` remains the ONLY writer of the count + its ADJUSTMENT movements;
the component opens a `StockTake` header and hands the counted figures to the action, building no variance
logic of its own.

**Blind vs visible — DECISION: BLIND, mirroring the cash arqueo.** The operator enters counted grams per
batch with NO expected weight shown; the variances are revealed only AFTER commit (read back from the
committed `StockTakeLine`s). Same integrity rationale as the cash count: staff should record what the scale
says, not weigh-to-target. This is a deliberate reading of prompt 07's "review variances before commit" —
prompt 47 explicitly allowed the choice, and blind-then-reveal matches the screen it lives on.

**Terminal-granularity — DECISION: prompt only when closing the LAST open till at the location** (there are
touched flower batches, and no count has been committed for the location today). Till sessions are
per-terminal and a location can run several at once (a dispensary and a bar terminal); flower stock is
per-location. Prompting at every terminal would nag twice or fire at a bar terminal that never touches
flower. Gating on "last open till for this location" fires it exactly once per location per evening, at the
final lock-up, whichever terminal that is. A batch never touched (`remaining_cg === initial_cg`) is simply
excluded — nothing for staff to do. UNIT genetics (prerolls/edibles, exactly countable from
`remaining_units`) and CLOSED/QUARANTINED batches never appear (all tested).

**Blocked close is explicit + recoverable.** If an operator tries to count cash while the flower reweigh is
still pending, `submitCount()` bounces back to the reweigh step with a stated warning ("Primero hay que
recontar la flor") — never a silent hang, mirroring how the blind-arqueo `needsNote` variance flow already
handles "can't finish yet, here's why". Gated on `stock.take` (its first real enforcement anywhere).

**Screenshots:** the reweigh step (filtered batch list, a variance on a line, the committed state) light+dark
could not be produced — no browser here; the flows are covered by `EodStockTakeTest` (8 tests: the three
exclusion rules, variance→one ADJUSTMENT + reconciled `remaining_cg`, zero-variance→line-but-no-adjustment,
the close-gating, the terminal rule, and the `stock.take` denial). Owner-authorised merge (standing "merge
everything to main"). 601 green.

---

## Prompt 74 — "Record contribution" refused whenever the operator typed the cash they were handed (LIVE BLOCKER)

Reported from a live counter: a €8.37 basket, Cash filled in as `10`, and Record contribution did nothing.
Cause: the dispensary POS treated its Cash field as the EXACT amount to charge, so `tenderSplit` took the
typed value literally and the commit guard `cash + wallet !== total` refused (`1000 !== 837`). The field
only worked if left blank (derive the remainder) or if the operator mentally computed the exact cents.
Meanwhile the bar POS modelled tender correctly — "Cash tendered", quick buttons, a change line — so two
same-looking fields had opposite semantics: a permanent error generator.

**Shared tender model — DECISION: extract `App\Livewire\Counter\Concerns\HandlesTender`, used by BOTH
screens.** The prompt said "do not leave two", and the bar was already right, so the bar's model became the
shared one. The trait owns `$walletInput` + `$cashTendered`, the derived split, the change calc, the
under-tender guard, quick-cash and the euro parse/format helpers; each screen implements only
`tenderableTotalCents()`. The dispensary's bespoke `cashInput`/`tenderSplit`/`parseCents` are gone. They
cannot drift again because there is one implementation.

**The fix, precisely.** Cash entered is what the member HANDED (`cashTendered`); the cash APPLIED and
recorded is always the exact remainder after wallet (`tenderSplit` derives `[$total - $wallet, $wallet]`),
so the split can never fail to reconcile. Over-tender produces CHANGE (displayed, `changeDueCents`);
**change is never stored and never posted to any ledger.** Under-tender (handed less than owed) is refused
with a stated reason ("El efectivo entregado no cubre el total.") — applied to BOTH screens now, so they
are genuinely one model. A blank cash field still means "exact" (the pre-fix regression case).

**Recorded money is unchanged for a correct entry** — asserted: `cash_cents` + `wallet_cents` still sum to
the contribution total, and `TillSummary::expectedCents` after an over-tendered dispensation equals float +
contribution, NOT float + tendered (the arqueo does not drift by the €1.63 change — a test asserts exactly
this). **Price override (prompt 64):** tender is measured against `$total`, which is already the OVERRIDDEN
total when an override is applied (ordering verified by a test: €8.37 → €5.00 override, €10 handed → €5.00
recorded, €5.00 change).

**Colocated-flash investigation (prompt 60) — FINDING: the block works; the message was unhelpful, not
absent.** `flash()` overwrites `$flashMessage`/`$flashType` and the keyed colocated block (`wire:key="flash-commit"`)
re-renders, so a NEW flash visibly replaces a stale one (asserted: a stale "Firma capturada" success is
replaced by the under-tender error). The old "does nothing" was the broken guard refusing a legitimate round
note with "El desglose de pago… no cuadra con el total." — a message that makes no sense to an operator who
just typed the value of the note in their hand. This branch removes that refusal (over-tender now succeeds),
so the confusing dead-end is gone. No prompt-60 regression: the flash surfaced; it was just wrong to fire.

**Screenshots:** dispensary over-tender-with-change and refused under-tender (light+dark, 1024/1440) could
not be produced — no browser here; the flows are covered by `DispensaryTenderTest` (7 tests). Owner-authorised
merge (standing "merge everything to main"). 608 green.

---

## Prompt 83 — eighth (3.5 g) pricing, including an eighth split across two strains

The owner asked for "prices on 1/8s (3.5 g) each batch — they can split it between strains, calculate it
in the background; only if both batches [have the] same price on 1/8s." There was no quantity-break pricing
of any kind. Built it inside `ResolvePrice` (the single resolver) so the POS, receipt and any report get
the eighth-aware total for free. The owner was unreachable, so the six model questions were answered with
the most conservative reading and flagged as **OVERNIGHT-DEFAULT — CONFIRM**:

1. **OVERNIGHT-DEFAULT — CONFIRM: only the eighth now, not a ladder.** One `price_per_eighth_cents` column,
   the exact ask. A 1/4/1/2/oz ladder is a clean additive follow-up (more break columns, or a small
   `price_breaks` table keyed by cg) — the resolver's grouping generalises to it. Not built speculatively.
2. **OVERNIGHT-DEFAULT — CONFIRM: "same price" = identical `price_per_eighth_cents` on the GeneticPrice**
   (per-strain, per-location; the tier row's if a tier applies, else base). The eighth price is a FLAT
   quantity-break — it is NOT discount- or tier-percentage-adjusted (a bulk deal already). Member discounts
   still apply to any sub-eighth per-gram remainder.
3. **OVERNIGHT-DEFAULT — CONFIRM: differing eighth prices fall back to per-gram.** Lines group by eighth
   price; two strains with different eighth prices land in different groups, neither reaches 3.5 g alone, so
   both are per-gram. (The alternative — charge the higher/average — is a different product decision.)
4. **OVERNIGHT-DEFAULT — CONFIRM: "at least 3.5 g".** A group of `G` cg is charged `floor(G/350)` eighths at
   the eighth price PLUS the remainder (`G mod 350`) per gram. So 3.4 g (340 cg) < one eighth → all per-gram;
   3.6 g → one eighth + 0.1 g per gram; 7 g → two eighths. All tested.
5. **OVERNIGHT-DEFAULT — CONFIRM: any number of strains** may share one eighth (two is the common case; three
   at ~1.17 g each is the same idea). The group is "all lines with the same eighth price".
6. **OVERNIGHT-DEFAULT — CONFIRM: the price lives on `GeneticPrice`, not `Batch`.** "Each batch" was the
   owner's word for the strain; prices already live per-genetic/per-location, and putting price on Batch would
   fragment pricing across two models. A genuinely per-lot price is a much bigger change and was not built.

**The rounding rule (explicit, per the prompt).** The eighth charge for a group = `eighths × E + round_half_up(minRate × remainderCg / 100)`, where `minRate` is the group's LOWEST effective (post-discount) per-gram
rate — deterministic and member-favourable for the remainder. That group total is split across the group's
lines proportional to grams by **largest-remainder**, so the per-line `line_total_cents` sum EXACTLY to the
charged total with no cent lost or gained (asserted with a €29.99 eighth over a 117/233 cg split).

**Where it lives + interactions.** All eighth arithmetic is in `ResolvePrice::applyEighthBreaks()` — the POS
basket assembly and `CommitDispensation::buildLines()` both call it, so the shown total can never desync from
the committed one (asserted end-to-end). The commit freezes the eighth into the line snapshot
(`dispensation_lines.pricing_note = "Octavo (1/8)"`), which surfaces on the POS (a `1/8` badge) and the
receipt. The price override (prompt 64) reduces from the eighth-aware resolved total (asserted: €30 eighth →
€25 override, original €30 kept). The **daily limit is enforced on grams and is completely untouched** — a
member at their 350 cg limit is still blocked at 360 cg regardless of pricing (asserted); eighth pricing is
no route around the limit. `price_per_eighth_cents` is a RATE (plain int cents, like `price_per_gram_cents`),
entered via a `_eur` edge field on the GeneticPrice form, written through `SaveGeneticPrice` (the single
writer) and audited.

**Screenshots:** the POS cross-strain eighth with the explanation + the receipt (light+dark) could not be
produced — no browser here; covered by `EighthPricingTest` (9 tests). Owner-authorised merge (standing "merge
everything to main"). 617 green.

---

## Prompt 84 — the till terminal is free text, and a typo silently hides the till from the POS

The terminal string KEYS a till session and was a free-text `<input>`, so "POS-1", "POS 1" and "pos-1" were
three terminals: open a till as one, and the POS looking for another said "no hay caja abierta" while a till
was open and money was going somewhere.

**Where the list comes from — DECISION: configured terminals per Location**, not derive-from-history. A
`terminals` JSON array on `locations` (the prompt's better long-term shape): the club names its tills once
and staff only ever pick, it gives reports a stable thing to group by, and it does not enshrine an existing
typo as a legitimate option. The migration BACKFILLS each location's list from the distinct terminals already
in `till_sessions` (normalised), so historical sessions still resolve and staff immediately see their real
tills. The list is grown automatically: `OpenTill` registers a genuinely-new terminal on the location as the
till opens (idempotent by key), so there is no separate admin chore — but it is a location-scoped record,
permissioned like other location data (only someone opening a till there can add one).

**Normalisation — the fix that makes it reliable.** `App\Support\TerminalName`: `key()` = lower-case,
alphanumeric-only (so "POS 1"/"POS-1"/"pos-1" collapse to `pos1`), `clean()` = trimmed, whitespace-collapsed
(the stored/display form). Applied in THREE places so the raw-string comparison can no longer miss: `OpenTill`
normalises the name, checks "already open" by KEY (a variant cannot open a SECOND till), and stores the clean
form; and BOTH POS lookups (`DispensaryPos`/`BarPos::openTillSession`) match the session by key, so a legacy
or variant string still resolves. The picker is a `<select>` over the location's terminals plus a "new
terminal" field (used in preference when filled).

**Better not-found message.** When a POS finds no session for its terminal, the flash now lists the terminals
that DO have an open till here ("No hay caja abierta en este terminal. Con caja abierta: POS-1") — a dead end
becomes a one-click fix, extending the bar POS's existing "go to the till" idea. The existing auto-adopt
(single open session → adopt) and disambiguation (several open → operator picks) are unchanged. The terminal
remains the key linking a session to its POS screens — this branch only makes choosing it reliable.

**Screenshots:** the open-flow picker + the POS not-found state listing terminals (light+dark, 1024/1440)
could not be produced — no browser here; covered by `TillTerminalPickerTest` (8 tests). Owner-authorised merge
(standing "merge everything to main"). 625 green.

---

## Prompt 85 — new members never received their QR card; the only send was a button labelled "Resend"

`MemberCardMail` and `IssueMemberToken` both worked, and `resendQrAction` wired them together — but nothing
sent the card on creation, so every member's card depended on staff remembering to click a button whose own
label ("Reenviar carné QR") implies it was already sent once. A member who applied online, was approved, and
never heard from anyone was the normal case.

**One send path — `App\Actions\Members\SendMemberCard`** — called from BOTH creation routes (admin
`CreateMember::afterCreate` and `ApproveApplication`) and the resend action. **Queued, never synchronous**
(`Mail::to()->queue()`) — the old `resendQrAction` used `Mail::send()`, which blocks the counter; fixed while
in the file, so there is no synchronous send left. **No email ⇒ no send, no error, no exception** — the
action returns false and the state is DISCOVERABLE via `Member::cardMissing()` (derived: no email, or no card
ever issued) surfaced as a toggleable "Carné QR" column ("Sin correo" / "Pendiente"). Audited
`member.card.sent` — the ACT, with the channel only, never the email address (the audit log has longer
retention — prompt 76).

**No double-send on approval.** The admin `CreateMember::afterCreate` hook only runs on the Filament create
page, NOT on the `ApproveApplication` path, so calling `SendMemberCard` explicitly once in each is correct —
verified by a test asserting exactly one queued mail after approval. No observer sends the card, so a
subsequent `$member->save()` cannot re-trigger it.

**Token rotation on resend — DECISION: it rotates, and this is FORCED, diverging from prompt 45 deliberately.**
`IssueMemberToken` stores the card token HASH-ONLY (NOTES §B) — the plaintext the QR encodes is returned once
and is unrecoverable — so a resend CANNOT re-emit the old token and necessarily issues a fresh one, revoking
the previous card. Prompt 45 took the opposite view for invite links (resend REUSES the token), but it can:
invites store the ENCRYPTED RAW token, which is recoverable. A QR card cannot, and the security requirement
(no recoverable card credential at rest) wins over the convenience of a still-valid printed card. Consequence,
recorded for staff: **a member who is re-sent their card will find any previously printed card dead.** If a
club needs "re-email the same card", that would require storing the card token encrypted-raw like invites — a
security trade-off to be decided deliberately, not defaulted into.

**Screenshots:** the `/dev/mail` card preview + the member-record card state (light+dark) could not be
produced — no browser here; `MemberCardMail` is unchanged so it keeps its render test + `/dev/mail` entry.
Covered by `SendMemberCardTest` (6 tests). Owner-authorised merge (standing "merge everything to main"). 631 green.

---

## Prompt 77 — three money paths have no lock, and the debt limit is bypassable

Three writes computed a value then acted on it with nothing serialising the two steps. All fixed with the
ONE pattern `CommitDispensation` already uses correctly: `lockForUpdate` on a CONTENDED row, inside the
transaction, covering the read AND the write. No queues, no global mutex, lock scope as narrow as correctness
allows.

1. **`RecordWalletTransaction` (the worst — money already wrong).** The balance was an unlocked `SUM()`, so
   under MySQL REPEATABLE READ concurrent debits each read the pre-existing snapshot and all passed the debt
   check (reproduced: €10 balance, four €8 debits, final −€22, every row's `balance_after_cents` wrong). Fix:
   lock the MEMBER row (`Member::…lockForUpdate()`) at the top of the transaction — the SAME row
   `CommitDispensation` locks, so wallet writes and dispensations serialise together. Re-entrant when
   CommitDispensation already holds it (same transaction), so no deadlock. The SUM now reads committed data and
   `balance_after_cents` is correct under contention.
2. **`CloseTill`.** Had no transaction and no lock, so a cash movement landing between "compute expected" and
   "write closed" was excluded from the immutable arqueo forever (a control failure — a real discrepancy could
   be masked, or a clean count flagged). Fix: wrap in a transaction and `lockForUpdate` the session row; re-read
   status, compute expected, close — atomically. `RecordCashMovement` now contends on the SAME session-row lock
   (it also locks + re-reads OPEN before inserting), so a concurrent movement either commits BEFORE the close
   (counted in expected) or finds the session CLOSED and is refused — never silently dropped.
3. **`MemberNumber::next()`.** `COUNT(*) + 1` raced (concurrent enrolments collided on the unique index → 500s)
   and REISSUED a number after a retention purge/soft-delete. **Allocation decision: a durable, monotonic
   per-organisation counter** (`organisations.member_no_sequence`), allocated under an org-row lock, backfilled
   from the max number already issued. It only ever increases, so it never reissues even after rows are deleted
   — the max-based read a pure `MAX()` would do still breaks after a purge, so a persisted high-water-mark is
   the right shape. Verified deterministically by `MemberNumberSequenceTest` (no concurrency needed).

**Sweep of the same shape elsewhere — what was checked and found:**
- `RecordStockMovement` — **FINE.** Locks the batch/article row before applying the signed delta.
- `RefundDispensation` — **FINE.** Locks the dispensation header and reads the cumulative-refunded SUMs under
  that lock (prompt 65's claim verified against the code).
- `CommitOrder` — **FINE.** Owns no contended read-then-write; it delegates stock to `RecordStockMovement`
  (locked) and any wallet spend to `RecordWalletTransaction` (now locked), so it inherits both fixes.
- `RecordFeePayment` — **FINE.** A pure append; its optional wallet side goes through the now-locked
  `RecordWalletTransaction`.
- `CheckInMember` vs aforo — **FOUND + FIXED.** It locked the member's own open check-in (double-check-in
  guard) but the door verdict read the aforo occupancy with an unlocked `COUNT`, so two concurrent scans could
  each see occupancy < capacity and both admit past the limit. Added a LOCATION-row `lockForUpdate` at the top
  of the transaction, so check-ins at a location serialise and the count is accurate. Lower severity than the
  money paths (a bounded soft-capacity overshoot, not corrupted money) but the same shape, so fixed here.

**Concurrency tests — SKIPPED WITH A STATED REASON, not silently passing.** These bugs manifest only under
genuine OS-level parallelism against MySQL (as the report reproduced them). Single-process PHPUnit cannot
reproduce a lock race — on SQLite `lockForUpdate` is a no-op and transactions serialise; on MySQL one process
runs its "concurrent" transactions sequentially. `tests/Feature/Concurrency/ConcurrencyLocksTest.php` documents
each scenario and skips it with that reason, and names the external harness that would run it (N forked workers
against the CI MySQL). The fix is verified instead by: reading it against CommitDispensation's proven lock, the
full sequential suite proving no single-writer behaviour changed, and the deterministic member-number test.

**MySQL run:** the required MySQL suite run (`phpunit.mysql.xml`) could NOT be executed here — MySQL does not
start in this environment (noted repeatedly in this file). This is flagged as an outstanding verification step:
a green SQLite run is explicitly NOT evidence for these fixes, so a human/CI MySQL run + the external parallel
harness is still owed. Owner-authorised merge (standing "merge everything to main"). 637 green (3 skipped).

---

## Prompt 76 — erasure left health data behind, the audit log undid it, and the RAT no longer matched

Three findings, one failure: the RGPD tooling was built for the data that existed at the time and nothing
updated it as the schema grew. **Written for whoever answers the next subject-access request.**

### 1. Erasure now actually erases
`AnonymiseMember` deleted only `photo_path` + `document_scan_path`. It now:
- Deletes EVERY member-linked file from the private disk: photo, ID scan, **medical certificate** (Art. 9
  health document), and every generated-document PDF.
- Clears `medical_cert_path` and **`is_therapeutic`** (the health flag is itself special-category data).
- Enumerates member-linked tables in `COVERED_MEMBER_TABLES` (14 tables), each with a reason. A guard test
  reads the schema for every `member_id` table and fails if one is undocumented — so a new table (refunds,
  orders and sanctions were all added after the original build) can't silently reopen the gap. All except
  `member_documents` hold only a `member_id` reference + non-identity operational fields, so scrubbing the
  member row erases the person from them.

**Retention vs redaction — the substantive Art. 17(3) decision, per document type:**
- **DECLARATION (consumo) + REGISTRATION_FORM (libro de socios): RETAINED as REDACTED metadata.** These may
  carry a legal-retention obligation (the club must be able to evidence that a member and their declaration
  existed). So the ROW survives with the name + DNI redacted out of its snapshot (`nombre`/`documento` →
  `[borrado]`), the non-identifying figure/version/date kept, and the **identifying PDF deleted** (its `path`
  nulled — the column was made nullable for exactly this). Nothing that IDENTIFIES the member survives; the
  minimal proof-of-existence does.
- **Every other generated document (CONSENT, ID, MEDICAL, SANCTION_ACT, OTHER): DESTROYED** — row + file.
- Rationale: resolves the erasure right against the retention obligation without deleting everything (which
  would breach retention) or keeping everything (which would breach erasure). Asserted against ACTUAL stored
  content: after erasure no surviving snapshot contains the name or DNI.

### 2. The audit log no longer undoes erasure
The audit diff excluded only credentials, so `member.updated` rows held `is_therapeutic` (health) and
`document_hash` — an **unsalted SHA-256 of the DNI**, a lookup table from the original — and the audit log's
retention is deliberately LONGER than member data. Two-part fix:
- **PREVENT (going forward):** `is_therapeutic` + `document_hash` added to `AuditsResourceChanges`'s sensitive
  list, so new diffs never capture them. And `member.anonymised` now records only the LIST of cleared fields,
  never their values.
- **REDACT (existing):** `RedactMemberAuditLogs`, invoked by erasure, masks those values (plus any direct PII)
  out of the member's existing `before`/`after` payloads in place. It is NOT a hole in the append-only log: a
  narrow, explicit bypass (`AuditLog::$redacting`) permits ONLY masking `before`/`after` — the entry's actor,
  action, subject, date and IP are frozen (enforced by the `updating` guard), nothing is deleted, and the
  redaction itself is audited (`member.audit.redacted`). Outside a redaction the log is still append-only
  (tested). `document_number` itself was already correctly encrypted — the fix is the unsalted INDEX.

### 3. The RAT now describes reality, anchored to the schema
- **RAT-03** (ID docs) now includes the **medical certificate store** and is flagged **`article_9 => true`**
  (was false — it processes health data).
- **RAT-06** (communications) now declares the **browser push services (Google/Mozilla/Apple/Microsoft) as
  processors and the transfer OUTSIDE the EEA** (subscription endpoint + payload) — Web Push is live via
  `MemberPushNotification` and this transfer was undeclared anywhere.
- **Anti-drift:** the erasure guard test derives its coverage from the live `member_id` schema, and the RAT
  test anchors the Art. 9 declaration to columns that genuinely exist (`is_therapeutic`, `medical_cert_path`)
  — so a schema change that removes the grounding fails the test rather than ageing silently.

Nothing here weakens the at-rest encryption or the signed-URL / document-access-log path (the audit found
those sound). Owner-authorised merge (standing "merge everything to main"). 643 green (3 concurrency skips).

---

## Prompt 73 — a guard test for the "built it, never wired it" pattern

The most-repeated defect in this codebase was never a bug in a feature — it was a finished, tested,
permissioned class with nothing that calls it (eight-plus times: RecordFeePayment, AutoSettleDebt/
TransferCredit, ImportMembers, CommitStockTake, WaiveCarencia, GeneticPrice editing, RefundDispensation,
UpdateDeclaredForecast). Every one passed `composer check`. `tests/Feature/Cleanup/UnreachableCodeGuardTest`
closes it, mirroring `FormCompletenessTest` (a guard + an honesty test that fails on a stale allowlist entry).

**Detection — the part that had to be right.** COMMENTS NEVER COUNT: the two hand-run scans during the
original investigation produced false negatives by counting docblock mentions (CommitStockTake and
RefundDispensation both looked reachable because other files named them in transaction-boundary docblocks).
So every target file is stripped of `T_COMMENT`/`T_DOC_COMMENT` via `token_get_all` before matching; a class
is reached if a stripped file contains `use FQCN;`, `new Short`, or `Short::`. Searched: `app/`, `routes/`,
`database/seeders/` — **never `tests/`** (a class exercised only by its own unit test is exactly the thing
being caught). A regression test asserts the exact docblock-mention false negative is not counted, and
another proves the detector reports a fabricated never-referenced class (a guard nobody has seen fail is not
a guard).

**Direct vs transitive — DECISION: DIRECT.** Reachability = "referenced from a non-test file under app/,
routes/ or seeders". True reachable-from-an-entry-point (controller/Livewire/Filament/command/job/observer)
is stronger but far more brittle; the direct check has caught every real instance to date. Documented as a
deliberate limit. A test confirms real entry points (Filament pages/relation-managers, scheduled commands)
count — an action reached only from a Filament page is not flagged.

**Sibling checks — added the cheap ones, did not build a framework.**
- **Notifications** (no dispatch site) — added; all four are reached.
- **Permissions** (declared in `Permissions::ALL` + role-assigned but never CHECKED) — added; a permission
  counts as checked only if its literal appears in `app/`/`routes/` (NOT the declaration or the role seeder,
  which merely ASSIGN it). This surfaced FOUR genuinely-dead permissions — `members.transfer`,
  `member.limits.set`, `stock.transfer`, `cash.bank` — the same anti-pattern, for permissions. They are
  allowlisted with a "wire or remove" reason rather than silently carried; the honesty test forces the entry
  to be removed the moment one is wired.
- **Settings** — NOT added: already guarded by `tests/Feature/Settings/InertSettingsResolvedTest` (prompt 59).
  Not duplicated.

**Allowlist policy:** every entry carries a written reason in the test file; the honesty test fails when an
entry becomes unnecessary; a short list is the point (it grows → that IS the signal). Actions + notifications
allowlists are EMPTY (every offender was wired by prompts 47/71/72 and the rest of this batch); the
permission allowlist holds the four above. The test is a comment-stripped file walk — fast enough to live in
`composer check` (~0.3 s).

Added the rule to `CLAUDE.md` alongside the fixture rule: **a domain action ships with the entry point that
calls it** — the two are the same lesson learned twice. Owner-authorised merge (standing "merge everything to
main"). 651 green (3 concurrency skips).

---

## Prompt 82 — there is no way to see who owes the club money

A `DebtorReport` on the shared `ReportPage`/`AbstractReport` (period picker, location scope, sortable table,
CSV/PDF export for free), listing members who owe the club — assembly, not invention: every figure already
existed.

**Two debt types kept DISTINCT — the core decision.** Wallet debt (spent past the balance; bounded, blocks
at the counter past a threshold) and unpaid cuota (the membership fee, a standing issue that blocks
dispensing) are DIFFERENT obligations with different consequences, so they are **separate columns with
separate totals**. The only place they are ever added is the summary's "Total adeudado" — the one aggregate
the treasurer takes to the asamblea. A member in both appears ONCE with both amounts, never double-counted
(tested).

**One definition, used by the report and the counter.** Wallet debt = `SUM(wallet_transactions.amount_cents)`
(the same source as `Wallet::balance`); the "Bloqueado en mostrador" flag = `debt > wallet_debt_limit_cents`,
which is exactly the negation of `ResolveMemberEligibility::debtWithinThreshold` at the counter; unpaid cuota
= `fee_cents − SUM(fee payments)`, the negation of `feesPaid`. A test asserts the report's over-threshold
flag equals the resolver's `debt` rule for the same member, so the report can never chase people the POS lets
through (or vice versa).

**Aggregated in SQL, not per-member — the N+1 the dashboard audit flagged.** The report groups
`wallet_transactions` by member and `memberships`⋈`membership_fee_payments` by membership, then resolves the
debtor union to member rows in ONE query — a constant ~3 queries regardless of membership size (asserted:
identical query count for 2 vs 12 debtors). The eligibility resolvers are per-member and would N+1 over the
whole roll, so they are the tested source of truth for the DEFINITION, not called in a loop.

**"Desde" (age) is a documented approximation.** It shows the earliest obligation date on the books (the
unpaid membership's `starts_at`, and/or the oldest wallet movement) so "€5 since March" sorts apart from "€5
since yesterday". A precise "in wallet debt since" needs a per-member running-balance walk — exactly the N+1
avoided above — so the cheaper, honest proxy is used and flagged here. **Scope:** a single-sede view sums that
sede's balance (matching the resolver there); the owner's "All" view sums a member's wallet across sedes (an
org-wide member's total position). Location-scoped with a denial test; gated on `reports.view` via ReportPage.

**Screenshots** (both debt types present, light+dark) could not be produced — no browser here; covered by
`DebtorReportTest` (6 tests). Owner-authorised merge (standing "merge everything to main"). 657 green.

---

## Prompt 86 — Batch recall (who received product from a batch)

**The inverse of the prompt-07 traceability spine.** Prompt 07 answers "where did THIS member's product come
from"; a health recall asks the other direction — "given this batch, WHO holds product from it, how much, and
when, so we can reach them." Built as a read-only `App\ViewModels\BatchRecall` over the dispensing history: it
never alters a dispensation, movement or wallet. One row per affected member (grams total + first/last date +
contact), aggregated **in SQL** (`GROUP BY member`, `SUM(grams_cg)`, `MIN/MAX(dispensed_at)`) so it does not
N+1 the batch's whole history. Surfaced as a per-row **Retirada** action on the batches table that opens the
affected-member list and downloads it as CSV through the existing `ReportExport::csv(ReportTable)` machinery
(no second export path).

**Completeness over tidiness — voided and refunded lines are INCLUDED and labelled, not filtered.** A voided
dispensation still means product physically left the counter at some point; a refund may mean only some came
back. For a *health* recall, dropping either risks missing someone who has product in hand. Each is counted
(`SUM(CASE WHEN status='VOIDED')`, and a `refunds`⋈`dispensations` id set) and shown as an `anulada` /
`reembolsada` flag on the row rather than excluded. The safe error is a name too many, never one too few.

**Recall ignores the batch's own stock and status — that is the whole point.** The batch being recalled is
precisely the one that is closed, empty or expired; a stock/status filter would hide exactly the case the
feature exists for. The list reads only the dispensing lines, never `remaining_cg`/`status`/`expires_on`
(tested: a CLOSED, drained, past-expiry batch still returns its full list). The batch's current status IS
shown prominently in the modal summary so nobody runs a recall on a batch still being actively sold without
noticing — surfaced, not enforced.

**By product, org-wide — never narrowed to a home sede.** Members are org-wide; the same batch may have been
dispensed to members across sedes. The query is by `dispensation_lines.batch_id` with no location scope, so
pointing the panel at a different sede does not shrink the list (tested). A recall that missed cross-sede
recipients would be worse than useless.

**Access = `reports.view`, deliberately narrower than batch access.** Who-consumed-what is Article 9
special-category data, so the recall is gated on `reports.view` — the consumption-report gate — NOT on the
`stock.manage` gate that merely opens the batches table. A stock manager without `reports.view` sees the batch
but not the recall action (denial test mounts `ListBatches` and asserts `assertTableActionHidden('recall')`);
a `reports.view` holder sees it. MANAGER/OWNER hold both; STAFF holds neither.

**Not built (recommended, with reasons):** (1) a one-click "quarantine this batch" from the recall modal was
declined — quarantine is a stock-status change owned by the stock writer and a different permission; the modal
instead surfaces the current status and the operator quarantines through the existing batch action, keeping
one writer. (2) Auto-notifying affected members (push/email) was NOT built — a recall is a legally and
medically sensitive broadcast that must be composed and authorised by a human, not fired by opening a modal;
the CSV hands the treasurer/secretary the exact contact list to act on. Both are logged here as the next
step if the club wants them.

**Tests:** `BatchRecallTest` (8) — one row per member with correct summed total and MIN/MAX date range;
different-batch recipient excluded; voided + refunded included and labelled; closed/empty/expired batch still
returns the full list; CSV lists the same members; cross-sede not narrowed by active location; recall action
hidden from a `stock.manage`-only viewer and visible to a `reports.view` holder. **Screenshots** (recall
modal, light+dark) not produced — no browser here. Owner-authorised merge (standing "merge everything to
main"). 665 green (662 passed, 3 pre-existing concurrency skips).
