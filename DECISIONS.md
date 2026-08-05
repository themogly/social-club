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

---

## Prompt 87 — Acta signatory (record who signed a minute)

**An acta recorded THAT it was signed (`signed_at`) but not by WHOM.** Added a nullable `signed_by` FK
(→ `users`, `nullOnDelete`) that `SignMinute` sets to the acting user, in the SAME `save()` as `signed_at`.
Because the model refuses any update once `signed_at` is present (`Minute::booted`), the signatory is frozen
with the signature — never re-signable, never editable. `signedBy()` relation added; the signatory is
rendered on the acta PDF (status line + an explicit "Cerrada y firmada en el sistema por … el …" line above
the wet-signature blocks) and in the Filament infolist.

**Signing is a new permission, `minute.sign`, deliberately narrower than `minutes.manage`.** Drafting an acta
and *signing* one are different acts: signing attributes a legal signature and makes the record immutable, so
it belongs to the club's signing authority, not to everyone who can edit a draft. `minute.sign` is granted to
**OWNER only** by default (the legal representative / president); MANAGER keeps `minutes.manage` (create,
correct, export) but cannot sign — a natural role-based denial, tested. It is a *permission*, not a hardcode:
a club whose secretary signs simply grants `minute.sign` to that account. (`SignMinute` now checks
`minute.sign`; existing signing flows already act as OWNER, so nothing regressed. The prompt-73 unreachable-code
guard confirms the new permission is referenced.)

**Historical actas stay honest — no backfill, no fabrication.** `signed_by` is nullable and never
backfilled: an acta signed before this column existed, or one whose signer's account was later deleted
(`nullOnDelete`), has no attributable signatory. Every surface reports that as **"No consta"** (not recorded)
rather than inventing a name or implying the signature is void — the signature is real, only its author is
unrecorded. Tested: a `signed_at`-present / `signed_by`-null acta renders "Firmada el …" AND "No consta".

**Immutability of the signatory.** The `updating` guard on `Minute` already refuses any change once signed, so
`signed_by` cannot be rewritten via the model; `SignMinute` refuses a second signature outright. Both paths
are tested. The signatory (user id + name) is also written to the append-only audit log's `after` payload —
neither is special-category data, so it is safe to retain there.

**Tests:** `MinuteSignatoryTest` (8) — signing records the signatory + relation resolves; audit log names the
signer; a manager can draft but is refused signing; staff refused; a signed acta cannot be re-signed and its
signatory cannot be changed; the PDF names the signatory when recorded and shows "No consta" when historical;
the sign action is visible to a signer and hidden from a manager. `FormCompletenessTest` allowlist documents
`signed_by` as system-set. **Screenshots** (infolist signatory row, signed acta PDF) not produced — no browser
here. Owner-authorised merge (standing "merge everything to main"). 673 green (670 passed, 3 concurrency skips).

---

## Prompt 88 — Convocatoria de asamblea (convene an assembly, email the membership)

**The club had no way to convene a general assembly or email its members.** Built a `Convocatoria` (the
formal notice) + `ConvocatoriaRecipient` (the roll), a Filament resource under "Documentos" to draft and
issue one, a per-member `ConvocatoriaMail`, and the one writer that ties it together —
`App\Actions\Governance\IssueConvocatoria`. A DRAFT is editable; issuing freezes it.

**The recipient roll is FROZEN at issue and never recomputed.** `IssueConvocatoria` snapshots every member
of the association as-at the notice date (joined by now, not yet left — the same "as-at" semantics the acta
quorum uses, so the convened roll and the quorum agree) into `convocatoria_recipients` with their
number/name/email AS THEY WERE. The point of the roll is to evidence exactly who was convened, which cannot
change even as the membership does — a test proves a member who joins *after* issue is never added to a
stored roll. `roll_count`, `quorum_required` (roll × `minute_quorum_fraction_bp`) and `notice_days` are all
frozen onto the row at the same instant.

**One separate email per member — never a shared To/CC.** Each recipient with an address gets its own
`Mail::to($email)->queue(...)` message (queued after the DB commit), so the whole membership's addresses are
never leaked to every recipient. A test asserts one queued `ConvocatoriaMail` per member AND that each
carries exactly one `to`. The mailable carries scalars (not the model) so it renders in `/dev/mail` and the
permanent `MailRenderTest` without a database.

**Members with no email are recorded as un-notified, not dropped.** A member without an address still gets a
roll row, flagged `NO_EMAIL` with `notified_at = null`, so the club can see (and reach another way) exactly
who it could not email. Silent omission was the specific failure to avoid.

**The notice period BLOCKS.** `assembly_notice_days` (default 15, now an editable org Setting on the settings
page) is the legal minimum between issuing and holding; `IssueConvocatoria` refuses an assembly sooner than
`now + notice_days` (too-short notice invalidates the assembly), leaving nothing issued and nothing sent.

**Permissioned, audited, immutable, linked.** Gated on `minutes.manage` (governance — the same authority as
actas; STAFF denied, tested at the action AND the Filament index). Issuing writes a `convocatoria.issued`
audit row (counts + notice/quorum, no PII). An issued convocatoria is immutable (`Convocatoria::booted`
refuses update/delete; policy withholds edit/delete). The acta references the convocatoria that called the
meeting (`minutes.convocatoria_id`, surfaced on the acta form).

**A general assembly is of the ASSOCIATION.** The roll is always the whole org; the convocatoria's `location`
is only the venue, never a filter on who is convened — documented in `IssueConvocatoria`.

**RGPD:** `convocatoria_recipients` snapshots name + email, so `AnonymiseMember` now redacts that snapshot
(name → `[borrado]`, email → null) on erasure while keeping the row + NOTIFIED/NO_EMAIL status as the
assembly's convening evidence. Documented in `AnonymiseMember::COVERED_MEMBER_TABLES` (the RGPD-completeness
guard passes).

**Also fixed (was blocking the green gate):** `DemoDataSeeder::seedOrders` committed a fortnight of bar
sales against fixed seeded article stock and intermittently exhausted an article ("Insufficient stock for
article Coffee/Water"), a real flaky-seed bug. It now re-reads live stock each line, sells only articles
still in stock and bounds the quantity to what remains — 4/4 clean seed runs.

**Tests:** `ConvocatoriaTest` (9 — frozen/never-recomputed roll, one-mail-per-member never shared,
no-email un-notified, notice-period block, frozen quorum/notice, no double-issue, immutability, permission
denial, acta↔convocatoria link) + `ConvocatoriaResourceTest` (3 — index denied to staff, loads for
governance, issue-from-table freezes + queues). Mailable in `MailRenderTest` + `/dev/mail`. **Screenshots**
(resource, issue modal, frozen roll, email) not produced — no browser here. Owner-authorised merge (standing
"merge everything to main"). 685 green (682 passed, 3 concurrency skips).

---

## Prompt 89 — Counter shows (and asks for) its sede; stops the silent panel-scope write-back

**The counter now keeps its OWN location state, fully separated from the admin panel's scope.** The old
`mount()` read `scope.location_id` and, when it was null ("All locations"), silently adopted the operator's
alphabetically-first assigned sede AND wrote that guess back via `ActiveScope::setLocation()` — the same
session key the panel's `LocationSwitcher` reads — so visiting a counter screen quietly changed the panel's
active location, and nothing on screen said which sede you were on. Replaced with a shared trait
`App\Livewire\Counter\Concerns\ResolvesCounterLocation` used by all four screens: the working sede lives in
its own session key `counter.location_id` and the panel's `scope.location_id` is **never written** by the
counter.

**Separation mechanism — an in-memory scope override.** The four counter components are self-sufficient
(every stock/till/dispensation query already passes `location_id` explicitly with `withoutGlobalScopes`), but
per-location `Settings::get` (e.g. `signature_on_dispensation`, `restrict_pos_to_checked_in`) still resolves
through `ActiveScope::locationId()`. So the counter must make its sede *active for the request* without
persisting it. Added `ActiveScope::useLocation()` — sets the in-memory location only, no session write — and
the trait applies it on every request (`bootedResolvesCounterLocation`). `ActiveScope::forLocation()` (used
by `ResolveMemberEligibility`/`ResolveMemberLimits`, which the counter calls) was switched from `setLocation`
to `useLocation`: it is a temporary switch-and-restore that must not persist, and under the old code its
*restore* would have written the counter's sede back into the panel scope — the exact leak, via a back door.
A regression test asserts `forLocation` inside a counter request leaves `scope.location_id` null.

**Multi-sede is an explicit choice, never a guess.** Resolution from the operator's own assignments
(`LocationSwitcher::available` — owner sees all, others their assignments): a valid prior `counter.location_id`
wins (sticky); else exactly one assigned sede is adopted without ceremony; else (several) the screen ASKS —
`mustChooseLocation` is set, `locationId` stays null, and the shared header shows a highlighted "Elige tu
sede" picker (auto-opened). Getting this wrong toward asking is far cheaper than dispensing from the wrong
sede's stock/aforo/till, so a multi-sede operator is never silently placed.

**One shared change shows the sede everywhere.** The sede name, the switcher and the choose-state all live in
`resources/views/components/counter/top-bar.blade.php` — the single header every counter screen renders — so
a fifth screen gets it for free. Zero sedes → a warning badge; one → a static badge; several → a dropdown
switcher (current marked).

**Switching is server-side-validated, confirms unsaved work, and refuses an open till.** The only writer of
`counter.location_id` is `POST /counter/location` (`CounterLocationController`), which validates the target
against `LocationSwitcher::available` — never a raw `setLocation` from client input, and "All locations"
(null) is not a valid counter target. Each switch form fires the existing `$store.counter?.dirty` unsaved-work
confirm before the full-page navigation that re-mounts the screen. And because the till is the thing most
likely to end up mis-scoped, switching **away from a sede whose till is still open is refused** — close the
blind arqueo first.

**Test-setup consequence (documented):** counter tests that set `scope.location_id` but never assigned the
operator to that sede were relying on the old scope-reading behaviour; four Till tests now assign the operator
to the location (the realistic setup — you cannot work a counter at a sede you are not assigned to).

**Tests:** `CounterLocationTest` (11) — each screen displays the sede; single-sede adopts + displays;
multi-sede asks (not silently adopted) and prompts to choose; visiting never writes the panel scope (incl. via
`forLocation`); switching validated server-side (unassigned sede refused); valid switch applied; refused while
a till is open; the switch control carries the unsaved-work confirm; no-assignment shows the no-sede state.
**Screenshots** (four screens showing the sede, the choose state, the switch flow, light/dark, 1024/1440) not
produced — no browser here. Owner-authorised merge (standing "merge everything to main"). 696 green (693
passed, 3 concurrency skips).

---

## Prompt 90 — Eighth pricing overcharge fixed (floor at per-gram; discount applies on top)

**A money defect: members were overchargeable.** Prompt 83's eighth arithmetic was cents-exact, but the
break was applied *unconditionally* — whether or not it was actually cheaper — and it compared a *discounted*
per-gram total against an *undiscounted* flat eighth. So a badly-set eighth (above 3.5 × per-gram) overcharged
everyone, and any member discount silently vanished on eighth-priced quantities (hitting therapeutic members
hardest). Both are now fixed in `ResolvePrice::applyEighthBreaks()` — still the only place the eighth
arithmetic lives.

**Never charge more than the alternative (the acceptance property).** The group charge is now FLOORED at the
group's own per-gram total: `applyEighthBreaks` computes the eighth charge and, if it is not strictly cheaper
than the sum of the group's per-gram line totals, keeps the per-gram totals. A member is never charged more
because an eighth price exists — asserted single-strain and split across two, discounted and not.

**Discounts apply ON TOP of the eighth price — per the owner's explicit decision.** Added
`PriceResult::effectiveEighthPriceCents()` alongside the existing `effectiveRatePerGramCents()`, using the
SAME chosen discount (`discountAmount`, which reads the one `chooseDiscount` result) — never a second,
independently-resolved discount. Both counter call sites (`DispensaryPos`, `CommitDispensation`) now pass
`effectiveEighthPriceCents()` to `applyEighthBreaks`, so a member on 30% off buying an eighth pays 30% off the
eighth, and the floor above is discounted-vs-discounted.

**Group charge computed once, then distributed — the exact-sum property preserved.** For N eighths (+ any
sub-eighth remainder at the group's lowest effective per-gram rate) the charge is computed once for the whole
group and split across its lines by the existing largest-remainder `distribute()`, so line totals sum EXACTLY
to the group charge at every multiple and with a discount — including a 33%-style discount that does not
divide evenly across a 117+117+116 split (tested: sums to the cent). Discounting per line and summing was
explicitly avoided; it would reintroduce a rounding error at every line and multiple.

**Guard at entry.** The `GeneticPrices` relation-manager form now REJECTS an eighth price above 3.5 × the
per-gram price (a validation rule on the field) — almost always a typo, and cheaper to catch here than to rely
on the counter's floor. The boundary (exactly 3.5×) is allowed.

**What was applied survives to the receipt.** The per-line `pricing_note` ("Octavo (1/8)") set when the break
is taken, plus the discount label already carried on each line, render on the basket and on the contribution
receipt — so an operator asked to explain "2 × eighth, less 30%" has it on the printout. (Kept the existing
mechanism; the money is the correctness surface and is asserted to the cent.)

**Seed.** `DemoDataSeeder` seeded 0 of 12 `GeneticPrice` rows with an eighth price (prompt 70 predated
prompt 83). Now every weight strain at a sede shares an eighth of €23 — below 3.5 × the lowest per-gram, so
always a genuine break — making the feature visible on a fresh install and letting a 1.75 g + 1.75 g
cross-strain basket exercise the split immediately (tested against the real seed).

**Tests:** `EighthPricingDiscountTest` (14) — floor (single/split, discounted/not); discounted eighth charged
(single/split); multiples carry the discount (7 g, 10.5 g, 8 g = two eighths + 1 g remainder); exact-sum over
an uneven three-line split with a discount; `effectiveEighthPriceCents` uses the same discount as the rate;
end-to-end discounted commits; the entry guard rejects > 3.5× and allows the boundary; the seeded DB has two
strains sharing an eighth that splits to one. The prompt-83 `EighthPricingTest` (9) still passes unchanged.
**Screenshots** (discounted multiple + explanation, the guard firing) not produced — no browser here.
Owner-authorised merge (standing "merge everything to main"). 710 green (707 passed, 3 concurrency skips).

---

## Prompt 91 — A staff day at the counter (one blocker + six frictions)

Presentation, sequencing and one missing state. No change to what is recorded — money, stock and the blind
arqueo behave exactly as before.

**1. The blocker: a jar that can't be weighed no longer traps the close.** The EOD reweigh required a figure
for every touched batch; a mislaid jar made the till uncloseable, and typing `0` posts a catastrophic merma
of the whole remaining weight. Added an explicit per-batch **"not counted"** state: `stock_take_lines` gains
`not_counted` + `not_counted_reason`; `CommitStockTake` records the omission as a line and touches NOTHING in
the ledger (no variance, no adjustment, no merma); `TillSession::submitReweigh` accepts a batch marked not
counted WITH A REASON and the close proceeds. It is NOT a count of zero — `0` is still a real
`counted_cg = 0` that adjusts down (tested: the two paths diverge — zero drains the batch, not-counted leaves
it). The omission surfaces in the reveal, and a batch left not-counted in a recent prior committed count is
flagged **"otra vez"** — a jar that keeps escaping is exactly what a count exists to catch.

**2. The loudest button was the most dangerous.** "Cerrar caja · arqueo" was a full-width primary blue CTA;
the routine movement/expense actions were pale. Inverted: the close is now a quiet outlined button (and it
opens a deliberate multi-step close — reweigh → blind count — rather than committing on tap), and
"Registrar movimiento" / "Registrar gasto" / "Cobrar cuota" carry the brand fill their frequency deserves.

**3. Forms gate BEFORE the work, not on submit.** `requireOperator()` refused only on submit, after the
operator had filled the form. The cash-movement, expense and fee forms now show a **"identifícate como
operario"** banner and disable their controls (a `<fieldset disabled>`) whenever no operator is identified —
prompt 60's "never a silent dead control", applied in reverse (the control isn't silent, it was just too
late). Shared partial `needs-operator.blade.php`, one pattern across all three.

**4. The dispensary basket is pinned to its own column at 1024 — the batch-2 bar POS fix, now shared.** The
dispensary grid became `lg:grid-cols-[minmax(0,1fr)_22rem]` with the basket div `lg:col-start-2 lg:row-start-1
lg:row-span-2` (auto at xl), IDENTICAL to bar-pos — one layout, asserted by both POS-layout tests, so it is
never fixed twice.

**5. Progressive disclosure of the payment apparatus.** With an empty basket the screen showed the whole
tender/wallet/cash/breakdown block and an empty signature canvas — a form for a transaction that did not
exist. The heavy apparatus (total, price override, tender, signature, breakdown) is now revealed only once
the basket has a line; before that a "identifica un socio y añade una genética" hint sits there instead. The
charge button STAYS shown and offline-only-disabled (prompt 60) — disclosure governs what is *shown*, not
what is disabled.

**6. One lookup, not two boxes with the wrong one focused.** A name typed into the autofocused "scan" field
now falls through to the SAME member search (`submitScan` routes an unrecognised token into `$search`), so the
lookup never depends on the operator noticing which of two adjacent boxes has the cursor. The scan field's
label/placeholder now say it accepts a name too.

**7. The reweigh copy matches its filter, with progress.** The panel said "flor dispensada hoy"; the filter is
`remaining_cg <> initial_cg` — touched since intake. Copy corrected to say so (the filter is the correct
behaviour for a stock count). Added a **":done de :total pesados"** progress indicator (a weight OR a
not-counted mark counts as done) so a long list has something to anchor against.

**Seed.** `DemoDataSeeder` dispensed from every batch, so the reweigh's "only touched batches" exclusion could
never be seen working. It now reserves one WEIGHT batch per sede as never-dispensed-from, so a fresh install
has an untouched batch that is correctly absent from the reweigh (tested against the real seed).

**Tests:** `CounterStaffDayTest` (10) — not-counted closes + leaves stock untouched + records the omission;
not-counted needs a reason; zero ≠ not-counted; till forms gated (and ungated) by operator; basket pinned to
its column; apparatus hidden until a basket line (charge still observable); a name in the scan field routes to
search; reweigh copy matches the filter + progress reflects the count; the seed leaves an untouched weight
batch out of the reweigh. Existing `EodStockTakeTest` (7) still green; `ChargeAlwaysObservableTest` updated for
disclosure. **Screenshots** (the full staff-day sequence, both widths, light/dark) not produced — no browser
here. Owner-authorised merge (standing "merge everything to main"). 720 green (717 passed, 3 concurrency skips).

---

## Prompt 92 — In-app help (empty states, glossary, per-screen guides, counter help)

**Where help content lives.** ALL of it is Spanish source strings in `app/Support/Help.php` (empty states,
per-screen topics, glossary), rendered through `__()` so it flows the prompt-19 lang pipeline — English in
`lang/en.json`, es identity in `lang/es.json`, key-set parity gated. No parallel content store. Because the
strings reach `__()` dynamically (`__($definition)`), which `lang:sync` cannot statically extract, they are
added to BOTH JSON files explicitly; the parity test still guards them, so the Spanish cannot silently rot.
Help is CONTENT ONLY — it never gates an action, changes behaviour, or restates a rule as a number (rules
live in code; help NAMES them — "el límite diario configurado" — a test asserts no help string hard-codes a
Setting value; the eighth's 3,5 g is allowed because it is a definitional constant, not a Setting).

**The layering (cheapest, highest-leverage first).**
1. **Empty states that teach** — every resource table gets a heading + description (what the records are, why
   they matter, the first action) from `Help::EMPTY_STATES`, applied by ONE global `Table::configureUsing()`
   default in `AppServiceProvider` keyed by model. A resource's own `emptyStateDescription` still overrides.
   A walk test fails when a new resource ships without one.
2. **A shared help affordance on every panel page** — one global topbar render hook (`filament.help-menu`),
   static + Alpine, `data-screen-help`, linking to the glossary and the per-screen guides. One pattern, not 40
   bespoke; a page without it is visibly missing it.
3. **The glossary** — its own searchable page (`Glosario`, nav group "Ayuda"), static Alpine filter, the
   club's terms of art defined once in both languages. The terms STAY Spanish (they are legally load-bearing —
   aportación vs venta); the page explains them, it does not translate them away.
4. **Counter help** — a shared help panel in the counter top-bar (`x-counter.help`, on all four screens),
   answering the blocked states staff actually hit ("¿por qué no puedo dispensar?") in plain language, plus
   the key terms. Static, lazy — nothing slows the till. It NAMES the rules (age, carencia, límite, cuota,
   debt) that `ResolveMemberEligibility` enforces; the counter still shows the exact per-rule reason live.
5. **The help index** — the Glosario page collects the per-screen guides (`Help::TOPICS`) as "Guías por
   pantalla", reachable from the topbar affordance.

**Who is the Spanish authority for the domain terms.** The Spanish in `Help::GLOSSARY` is the authoritative
version — written first, in Spanish, for the legal vocabulary; the English is a faithful gloss that keeps the
Spanish term. This is the highest-value help in the system precisely because these terms are not guessable.

**Scope note (honest v1).** Per-screen help is a shared, global affordance plus registered per-screen guides
surfaced on the help index — not yet a bespoke inline modal on each of the 35 screens. The registry
(`Help::TOPICS`) is the seam: adding a richer inline panel later is content already written, not new plumbing.

**Tests:** `HelpTest` (6) — every resource has an empty state (walk); every glossary term resolves in both
locales and is translated (not one-language-only); no help string hard-codes a Setting value; the shared
affordance renders on a resource page; the glossary renders in es AND en (term stays Spanish); the counter
carries help in both locales. Prompt-19 parity still green. **Screenshots** (empty state, help panel, glossary,
counter help — both languages) not produced — no browser here. Owner-authorised merge (standing "merge
everything to main"). 726 green (723 passed, 3 concurrency skips).

---

## Prompt 93 — Guided flows + derived incompleteness

**The case that proves it:** a genetic created and nothing else is `Active: Yes, Published: Yes`, yet it is
absent from the POS — "No genetics with an active price at this sede" — and nothing said why. Two
complementary mechanisms, the derivation being the higher-value half.

**1. Tell the truth about incompleteness — DERIVED, never stored.** A stored "complete" flag disagrees with
reality the first time someone edits around it, so completeness is computed live:
- `Genetic::completenessReason()` → `no_price` (needs a per-location price — the exact `whereHas('prices',
  active, base)` condition the POS filters on), `no_stock` (priced but no OPEN batch with stock), or null
  (ready). Plus `hasActivePriceAt($location)` / `hasStockAt($location)` for the per-sede truth.
- `Member::hasActiveMembership()` — a member with none cannot be dispensed to.
- `Location::hasActivePrices()` — a sede with none is a counter that sells nothing.
- `User::setupIncompleteReasons()` → `no_role` (canAccessPanel refuses), `no_location` (scoped to nothing),
  `no_pin` (cannot identify at the counter).
Each is surfaced as a badge in its resource LIST (Genetics "Sin precio/Sin stock/Lista", Members "Sin
membresía", Locations "Sin precios", Users "Falta rol · PIN"), naming the gap with a tooltip pointing to the
fix. It catches the problem whenever it arises, including for records created before any guidance existed, and
does not depend on anyone following a happy path.

**2. Carry the user onward — guide, don't force.** `CreateGenetic::getRedirectUrl()` now lands the user on the
new genetic's own page (where the GeneticPrices relation manager lives), with a notification naming the
remaining steps (add a price, add a batch), instead of dropping them on the list one third of the way through.
It never traps them: they can stop, and mechanism (1)'s "Sin precio" flag is what makes stopping safe and
visible. No wizard — these are genuinely separate records with their own permissions.

**No new authorization, no broken links.** Each step keeps its existing permission; the guidance NAMES the
next steps in text (a notification, a badge tooltip) rather than linking to a page the viewer might not be
allowed to open — so a user without `stock.manage` is told about the batch step, never shown a link that
403s. The derived flags are permission-independent (anyone who can see the list sees the truth).

**Boundary with prompt 78 (go-live/first-run):** untouched. This branch is about the ongoing incompleteness of
individual records (a strain, a member, a user, a sede), not the one-time organisation/first-owner bootstrap
prompt 78 owns.

**Tests:** `GuidedFlowsTest` (7) — a genetic without a price is flagged in the list AND absent from that
location's POS (asserted together, since the whole point is they currently disagree); adding a price clears
the flag for that location only and moves it to "no stock"; a priced + batched genetic is ready and appears at
the POS (regression: no false alarm); a member without a membership, and a user missing a role/location/PIN,
are each flagged (and complete ones are not); a location without prices is flagged; creating a genetic guides
onward (redirect to its page) and skipping leaves it visibly incomplete. **Screenshots** (incomplete genetic,
the guided path, the strain then at the POS — both languages) not produced — no browser here. Owner-authorised
merge (standing "merge everything to main"). 733 green (730 passed, 3 concurrency skips).

---

## Prompt 94 — Spanish localisation fixes (Contador→Mostrador; raw-enum leaks; the guard)

**Term chosen for the counter: `Mostrador`.** The counter layout subtitle was the key `'Contador'` → es
`Contador`, which in Spanish means *accountant* (or an electricity *meter*) — a Spanish staff member opened
the POS labelled "Accountant". Renamed the key to **`Mostrador`** (the literal, correct word for a service
counter), updated both call sites (the counter layout `<title>` and the shared top-bar `<h1>`), and removed
the old `Contador` key from both lang files. A test asserts the new term renders in es/en and that
`__('Contador')` appears nowhere in the app.

**Three raw backed-enum values were reaching humans in BOTH languages** — every enum here implements
`HasLabel` with a proper `label()`, and these call sites bypassed it (CLAUDE.md forbids rendering a raw enum):
- `DispensaryPos` genetic cards passed `cultivation_type?->value` (INDOOR/OUTDOOR/GREENHOUSE), then the blade
  wrapped it in `__()` which silently returned its input — now passes `->label()`, rendered verbatim like its
  neighbours `product_type_label` / `strain_type_label`.
- `MembersRegister` (the **libro de socios** — a statutory register) emitted `status->value`, printing
  ACTIVE/SUSPENDED in English on screen, in the **CSV** and in the **PDF that leaves the building** — now
  `status->label()` (Activo/Suspendido).
- `ZReport` (till Z-report) emitted `status->value` — now `status->label()`.

**A permanent guard, in the localization suite.** `RawEnumRenderTest` (5) pins each fix — the libro de socios
label in es AND en (asserted on the register data AND the rendered PDF, the artefact that leaves the
building); the cultivation label; the Mostrador rename — and, crucially, asserts the DISCRIMINATION the prompt
demanded: the display producers (`MembersRegister`, `ZReport`) use `->label()` and NOT `->value`, while the
**Article 20 portability export** (`ExportMemberData`) legitimately KEEPS `->status->value` (machine-readable
is correct there). So the guard fires on a display leak but never on the legitimate machine-readable / audit /
comparison uses.

**Seed English-in-es (decided: documented, no change).** The demo seed is already locale-aware (prompt 70): a
Spanish-locale seed produces Spanish reference data (verified by `DemoSeedProfileTest`). Premium/Standard and
"Central Branch" appear only when the seed is run under `en`; that is correct behaviour for real club-entered
data (it does not translate), and the demo simply reflects the locale it is seeded under. No code change.

**Native-read follow-up (flagged, not in scope here):** `TillSessionsTable`'s status *filter* builds its
option labels from `$case->value` (renders OPEN/CLOSED raw in the filter dropdown) — a fourth instance of the
same class, in a filter rather than a record. Left for a focused follow-up so this branch stays the three
named human-facing renders + the guard.

**Tests:** `RawEnumRenderTest` (5). Prompt-19 EN/ES parity still green (the `Contador` key removed from both
files together, `Mostrador` added to both). No behaviour change — presentation only. **Screenshots** (the POS
in both languages with the corrected subtitle and cultivation badges) not produced — no browser here.
Owner-authorised merge (standing "merge everything to main"). 738 green (735 passed, 3 concurrency skips).

---

## Prompt 95 — Member menu 500 on an unpriced/soft-deleted genetic + withoutGlobalScopes() sweep

**Production-down: `/socio/menu` returned 500 whenever any genetic had no active price at the member's sede**
— an entirely ordinary half-priced strain (exactly prompt 93's state) took down a member-facing screen for
every member, and soft-deleting the strain did not fix it.

**Defect 1 — filter, don't throw (one definition of "sellable").** `menu()` mapped
`ResolvePrice::forGenetic()` over EVERY genetic; that resolver throws for an unpriced strain and nothing
caught it. Rather than a try/catch, the menu now uses the SAME filter the dispensary POS uses — extracted into
`Genetic::scopeSellableAt($locationId)` (active + an active base price at that sede) and adopted by BOTH
`PwaController::menu()` and `DispensaryPos::geneticRows()`. So there is ONE definition of what is available,
the two surfaces can never disagree (asserted against each other), and `forGenetic()` is only ever called
where a price exists.

**`ResolvePrice` contract — kept throwing, made the safe path the default.** `forGenetic()` still throws for a
caller that asks to price an unpriceable genetic (a genuine programming error), but callers no longer defend
individually: the shared `scopeSellableAt()` filter is the default-safe path, and `Genetic::hasActivePriceAt()`
(prompt 93) is the cheap companion check. Changing the resolver to return null would ripple through every
caller (POS/PWA/reports/receipts) for no gain now that the filter is shared, so the throw stays — recorded per
the prompt's request.

**Defect 2 — `withoutGlobalScopes()` also stripped the soft-delete scope.** `Genetic` uses `SoftDeletes`; the
blanket call removed `SoftDeletingScope` too, so the menu advertised DELETED strains (why soft-deleting didn't
fix the crash). The intent was only to escape the *organisation* scope (the member guard sets no active
scope), so it is now `withoutGlobalScope(OrganisationScope::class)` — soft-deleted genetics are excluded.

**The sweep (every blanket `withoutGlobalScopes()` vs `SoftDeletes`).** 190 call sites. The rule applied: a
MEMBER-FACING surface must never see trashed rows; staff oversight that reports HISTORY may. Findings:
- **Fixed (member-facing, were leaking trashed rows):** `PwaController::menu` (Genetic),
  `Member/AnnouncementController` (Announcement — a deleted, still-published aviso would show),
  `Member/EventController` (Event — same), and `PwaController::location()` (Membership — a deleted membership
  must not resolve as a member's home sede). Each narrowed to escape only Organisation (+ Location for the
  member's cross-sede memberships), keeping the soft-delete scope.
- **Reviewed, correctly left as-is:** the Dashboard and Reports viewmodels (`Dashboard`, `*Report`) — these
  are staff oversight aggregating HISTORY, where excluding a later-deleted member/genetic/batch would silently
  change past figures; the counter screens (`DispensaryPos`/`BarPos`/`CheckInScreen`) — id-lookups of a
  just-created record or an eligibility membership under the staff scope, not member-facing; and the model
  internals (`Batch`/`GeneticPrice` fetching a parent Genetic's `unit_type` by id) — a trashed parent's
  arithmetic constant is still needed. `PwaController` history/export read the member's OWN non-soft-deletable
  records (dispensations/orders/wallet/visits) plus their own memberships in an RGPD Art. 20 export (left
  inclusive for completeness — the member's own data, not a catalog advertised to them).

**Tests:** `MemberMenuCrashTest` (7) — unpriced genetic absent + 200 (the crash regression); priced at A not B
(both 200); soft-deleted genetic never appears; member with no active location gets an empty menu not an
error; the menu and the POS agree on the available set (asserted against each other); a soft-deleted
announcement and event are not shown to members (the sweep). **Screenshots** (`/socio/menu` degrading vs
crashing, light/dark, phone) not produced — no browser here. Owner-authorised merge (standing "merge all to
main"). 745 green (742 passed, 3 concurrency skips).

---

## Prompt 96 — Member PWA locale (member.locale, one widened resolver, switcher, queued-mail locale, es default)

**The member PWA rendered in English for a Spanish club, with no way to change it.** Three causes, all fixed
around the SINGLE resolver.

**1. One resolver, widened — not a member fork.** `ResolveLocale::handle()` took `?User`; on the `socio`
routes the guard is `member` (provider Members, not Users), so `SetLocale` passed `null` and skipped the
per-preference step entirely. The signature is now `?HasLocalePreference` — Laravel's own
`Illuminate\Contracts\Translation\HasLocalePreference` (idiomatic, and it wires framework mail-locale for
free), implemented by BOTH `User` and `Member` via `preferredLocale()`. `SetLocale` now reads the subject
from whichever guard is authenticated (web User OR member). One resolver, one documented order
(per-subject preference → org default → system), unchanged for the admin panel (regression-tested).

**2. Members have a language + a switcher.** Added a nullable `members.locale` (mirroring `users.locale`). A
persistent ES/EN switcher lives in the shared socio header (so a member who lands in the wrong language
escapes it on any screen), reusing the admin switcher's pattern exactly: persist to the member row AND drop
a session override so `SetLocale` applies it on the very next request — no re-login. Only an enabled locale
is honoured; an unknown/stale value degrades to the org default, never throws.

**3. The shipped default is Spanish — deliberate, with a wide blast radius.** `Settings::DEFAULTS['default_locale']`
flipped `en → es` (a Spanish product for Spanish clubs; a member/user with no preference must not get
English). Because `Settings::get` prefers `DEFAULTS` over the caller's fallback, this flips BOTH the admin
panel and the PWA by default. `.env.example` was internally contradictory (comment said "Spanish default",
values said `en`) — corrected to `APP_LOCALE=es` / `APP_FALLBACK_LOCALE=es` so a real install is Spanish at
every level, with English fully available. English remains the ultimate system fallback in code
(`config('app.locale')`). This supersedes the earlier en-default note; recorded here per the prompt (and it
sits inside prompt 78's flagged contradiction — prompt 78 is not merged, so no boundary conflict). Blast
radius on the suite: 6 tests updated to the new default (two locale-resolution tests, the middleware test, the
`Member.locale` form-completeness allowlist, and two dashboard tests that formatted an expected figure in the
test's ambient locale rather than the page's).

**4. Queued mail resolves an EXPLICIT locale.** `SetLocale` is HTTP middleware, so a job (QR card, invite,
reminders, the convocatoria) runs with no session or request and would send in the worker's locale. Each
member-facing send now resolves the recipient's language IN-REQUEST via `ResolveLocale` and pins it onto the
message with `->locale()`: `SendMemberCard` (QR card) and `IssueConvocatoria` (per recipient, resolved at
issue). A statutory convocatoria now reaches each member in THEIR language — the same bug in a place that
matters more.

**Tests:** `MemberLocaleTest` (7) — a member sees the PWA in their own locale (es/en); no-preference falls to
the ORG default not unconditional en (and an org override to en is honoured); the switcher changes the
language immediately and persists across a fresh session; an unknown/disabled member locale degrades
gracefully; the QR card and the convocatoria are QUEUED in each recipient's resolved locale (dispatched from
a job, no HTTP); the admin user-locale behaviour is unchanged. Prompt-19 parity still green. **Screenshots**
(PWA home/menu/history in both languages + the switcher, light/dark) not produced — no browser here.
Owner-authorised merge (standing "merge all to main"). 752 green (749 passed, 3 concurrency skips).

---

## Prompt 97 — Public application form: unambiguous DOB, informed consent, fillable fields, next steps, es

The first thing a prospect ever sees — the tokenised `/socio/solicitud/{token}` form. Structurally sound;
the fixes are in what it asks for and records. The route stays unauthenticated + token-gated (bad token still
404s), and the honeypot + `throttle:10,1` are untouched.

**Date of birth — unambiguous, not US format.** The `<input type="date">` always SUBMITS ISO (`yyyy-mm-dd`)
regardless of the picker's displayed order, so storage was never actually transposed — but the picker showed
`mm/dd/yyyy` because the page rendered in English (§5). Two fixes: the page now renders in the club default
(Spanish — see below), so the native picker shows `dd/mm/aaaa`; and an explicit "**Formato: día / mes / año**"
hint sits under the field, removing any doubt regardless of the browser. Tested: 4 March entered stores as 4
March and approves to `04/03/1990`, in a Spanish-locale context; an under-age applicant (date entered in the
form's ISO format) is rejected, so a format regression fails the test.

**Consent — informed and version-tied.** The privacy notice and the statutes are now DISPLAYED on the page
(two `<details>` expanders fed by new `consent_privacy_text` / `consent_statutes_text` Settings — there is no
public page to link to, NOTES §A), tagged with the exact `consent_text_version`. The single bundled tick
became TWO separate required consents (data processing / statutes) — different agreements, stronger evidence.
The version tie: the form captures the `consent_text_version` the applicant SAW at submit into the payload,
and `RecordMemberConsent` (still the single writer) now stamps THAT version at approval, not the current one —
so a revision between application and approval can never rewrite what they agreed to. Tested: submit at v1.0,
bump to v2.0, approve → the consent records read v1.0.

**Two fields an applicant can actually fill.** The sponsor field takes a **name OR a member number** (a
prospect knows the person, not the number), resolved best-effort — by number, else an unambiguous single
active-member name match — with the raw text kept on the payload for the reviewer. It is NEVER required at the
form (the `avalador_policy` is enforced and waived at approval), so an applicant who cannot supply one is not
blocked. Declared consumption became a **guided select of the club's `forecast_options_g`** (with "prefiero no
indicarlo") instead of a free number the applicant has no basis for — it is the figure that lands on their
signed declaration (prompt 72). No new personal data is collected (Art. 9).

**What happens next.** The form now states, before submit and on the confirmation, that the association
reviews the application and — if approved — sends a membership card with a QR code by email (prompt 85), and
that it may take a few days.

**Language (§5) — resolved by prompt 96.** The route has no authenticated anything, so `ResolveLocale` falls
straight to the org default. Prompt 96 (merged) flipped that default to `es` and `SetLocale` runs on the `web`
group, so the form now renders Spanish — the sharpest case for the club default, since a prospect cannot have
a preference. Tested (`lang="es"` + Spanish copy). No new lever needed here; the dependency on prompt 96 is
noted and satisfied.

**Tests:** `PublicApplicationFormTest` (9) — unambiguous DOB (es); under-age rejected in the form's format;
consent texts shown + recorded version = displayed version (across a revision); either consent missing is
refused; honeypot + bad-token guards intact; renders in the club default; an applicant without a sponsor is
not blocked; sponsor resolved by name or number; consumption guided + stored as centigrams. Existing
application/invite tests updated to the two-consent + `avalador_ref` contract. **Screenshots** (form at 390px,
both languages, date/consent/confirmation, light/dark) not produced — no browser here. Owner-authorised merge
(standing "merge all to main"). 761 green (758 passed, 3 concurrency skips).

---

## Prompt 98 — Per-scheme WCAG-AA contrast tokens (+ switcher target size)

**Three of the four semantic tokens failed AA (4.5:1) — each in only ONE scheme**, so a single swap could
not fix them. The tokens in `resources/css/app.css` now carry PER-SCHEME values, verified against the actual
surfaces (`#ffffff`, `#f8fafc`, `#0f172a`) AND the `/10` tinted badge/banner backgrounds those states use
(the tint lowers the ratio ~0.6, and it was the binding constraint — the amber-700/green-700 candidates in
the prompt cleared the plain surfaces but FAILED the tint, so the values went one step further).

Final values with their worst-case measured ratio (minimum across plain surfaces + tints in that scheme):

| Token | Light (`@theme`) | worst light | Dark (override) | worst dark |
|---|---|---|---|---|
| warning | `#92400e` (amber-800) | **5.80:1** | `#d97706` (unchanged) | **5.01:1** |
| success | `#166534` (green-800) | **5.86:1** | `#16a34a` (unchanged) | **4.81:1** |
| error | `#b91c1c` (red-700) | **5.24:1** | `#f87171` (red-400) | **5.69:1** |
| brand | `#2563eb` (unchanged) | 4.94:1 | — | — |

warning/success needed DARKENING on light (they were dark-on-light); error needed LIGHTENING on dark (it was
dark-on-dark) AND darkening on light for the `/10` tint (`#dc2626` was 3.97:1 there). Semantic meaning is
preserved — amber-800 still reads amber (a touch deeper), green-800 green, red-700/red-400 red; the deeper
amber/green is the deliberate, documented cost of clearing the tint.

**Scheme mechanism.** `@theme` holds the LIGHT set (the default). The dark set is applied under
`@media (prefers-color-scheme: dark)` (the counter follows the OS — no manual toggle) gated by
`:not(.light):not([data-theme='light'])`, AND under `:root.dark, :root[data-theme='dark'], .dark` (Filament's
class toggle). The verified worst case — `"Sin operador identificado"` (`.text-warning`, the default state of
every counter screen) — is now 4.80:1+ on light and 5.60:1 on dark.

**Language switchers** (admin + the prompt-96 PWA control) measured 20×24 / 22×24, below WCAG 2.2 Target Size
(24×24). Both now carry `min-h-[1.5rem] min-w-[1.75rem] inline-flex items-center justify-center` — ≥24×24.

**The permanent guard.** `ColourContrastTest` (4) reads the token values back OUT of `app.css` and computes
the WCAG ratio deterministically for every token on both surfaces + `/10` tints in its scheme, asserts ≥4.5,
pins the operator-warning case in both schemes, asserts brand is not regressed, and checks both switchers meet
24×24 at the markup level. A token edit that drops below AA fails the suite (prompt-73-style permanent check).

**axe-core NOT wired** — it needs a real browser (Playwright), which this environment does not have (no
browser anywhere in this project's runs, hence no screenshots). The deterministic token guard is the runnable,
permanent equivalent for the contrast dimension; axe-core over the four counter screens + an admin screen in
both schemes remains the recommended follow-up when a browser/CI is available, per the prompt's own steer.

**Out of scope (documented, per the "fix the tokens not the call sites" rule):** a few HARDCODED hexes remain
outside the token system — Filament chart segment colours (`DashboardChart`, `BreachLogForm` — graphical, not
text, exempt from text AA), the receipt PDF badge (white-on-red, passes), and the audit-log diff highlight
(`AuditLogInfolist` uses `#16a34a` green text on white ≈ 3.30:1 — a genuine but minor, admin-only, inline-diff
gap). These are call-site decisions, left for a focused follow-up so this stays a token branch. No functional
change — colour and padding only.

**Tests:** `ColourContrastTest` (4). **Screenshots** (four counter screens + an admin screen, both schemes,
warning/success/error) not produced — no browser here. Owner-authorised merge (standing "merge all to main").
765 green (762 passed, 3 concurrency skips).

---

## Prompt 99 — Help that covers every screen, matches the role, and teaches the tasks

**Extended `Help`, did not fork it.** Added `PAGE_TOPICS` (18 admin pages, keyed by page FQCN) alongside the
existing model-keyed `TOPICS` (now 25 resources), and a `permission` key on EVERY topic derived from the
screen's own policy `viewAny` (grepped from `app/Policies`, not invented). `allTopics()` unions both;
`topicsVisibleTo(?User)` filters by `permission === null || $user->can($permission)`. Coverage is now
**enforced**: `HelpGuidesTest` walks every `*Resource` and every concrete `Filament\Pages\Page`
(excluding the abstract `ReportPage` and the two help pages) and fails if one has no topic.

**Five task GUIDES** (`Help::GUIDES`), each a walkthrough with the consequence flagged where it bites, gated
on its FIRST step's permission (`guidesVisibleTo`): new-location (`locations.manage`), add-product
(`genetics.manage`), eighth-pricing (`pos.use`), taking-payment (`pos.use`), till-day (`till.open`).
Role filtering is tested **both directions** — STAFF sees the counter/till/payment guides and the
member/expense/dispensation/order topics but NOT settings, enforcement, RGPD (`Rat`/`DataRequest`), audit
or users; OWNER sees all; and no guide is ever shown to someone who cannot perform its opening move.

**Eighth-pricing worked example is pinned to code, not typed.** `Help::EIGHTH_EXAMPLE` holds only RAW inputs
(€10/g, €25/eighth, 30 % member discount, 3.5 g); `Help::eighthExample()` computes the effective rates and
runs them through `ResolvePrice::applyEighthBreaks` — so the €17.50 the guide shows IS what the till charges
(post-prompt-90 floor behaviour). `HelpGuidesTest` reproduces the arithmetic independently from the raw
inputs and asserts equality, that the break is genuinely cheaper, and the saving. The guide view renders
`eighthExample()` live, so it can never drift.

**Surfaces.** New `Manual` page (`ayuda/manual`, group "Ayuda") renders the role-filtered guides (deep-linked
by `#guia-<key>`, printable) + the live eighth table + the per-screen topics; `Glosario` slimmed to the
glossary alone and links to it. The prompt-92 topbar help menu (`data-screen-help`) now lists the guides the
reader can start (so help is reachable from where the task begins) and links to the Manual. Rules are NAMED,
never restated as a number — `HelpTest`'s setting-value guard was widened to scan `allTopics()` + `GUIDES`.

**i18n.** All ~146 new Spanish strings added to `lang/es.json` (identity) and `lang/en.json` (English) with
EN/ES parity gated by `LocalizationTest`; the topic/guide strings are `__($var)` (dynamic, so not
auto-extracted) — a dedicated `HelpGuidesTest` asserts every one is a key in BOTH files, so English can't
leak Spanish. Domain terms keep their established English (Members/Batches/Bar & shop…). The Order topic
title is **"Barra y tienda"** (reusing the glossary key), NOT "Ventas de barra …", to satisfy the prompt-68
rename guard (`BarSalesRenameTest`).

**Owed / not done:** no browser here — the Manual + help-menu (owner/staff, light/dark, five widths) are
un-screenshotted; MySQL suite not run locally. Owner-authorised merge (standing "merge all to main").
776 tests, 773 passed, 3 pre-existing concurrency skips, PHPStan 0.

---

## Prompt 75 — Cross-sede authorization (three verified-live exploits closed)

Re-run after an audit found it was never actually in the tree (commit line existed, code did not).

**1. Counter `$locationId` is now `#[Locked]`** on all four counter components (`CheckInScreen`,
`DispensaryPos`, `BarPos`, `TillSession`). It is resolved in `mount()` from the operator's OWN available
sedes (`ResolvesCounterLocation`), and the booted hook re-applies it as the request scope on EVERY request —
so while it was a plain public property, a client could set it to a sede they were not assigned to and write
there (staff wrote a check-in at another sede; this is why). `#[Locked]` makes the client unable to mutate it
post-mount. Guarded by `CounterLocationLockTest` (reflection walk over all four).

**2/3. Void and refund now authorize through the POLICY, not a bare permission.** `VoidDispensation`,
`VoidOrder` and `RefundDispensation` were checking `->can('dispensation.void' | 'order.void')` — the
permission, but NOT the location. They now call `$actor->cannot('void'|'refund', $model)`, routing through
`DispensationPolicy`/`OrderPolicy`, whose `::view` binds the actor to the row's own sede (or org-wide
`reports.view.all`). A manager at sede A can no longer void/refund a sede-B row. `RefundDispensation` was
the same-family sibling of the two the audit named and shares the policy, so it was fixed in the same pass.

**Denial tests (CLAUDE.md §Security).** `VoidDispensationTest` + `BarPosTest` each gained
`test_a_manager_cannot_void_another_sedes_{dispensation,order}` (actor assigned only to a DIFFERENT sede →
`AuthorizationException`). The existing allow-path helpers now `locations()->sync([...])` the row's sede —
the correct real-world setup (an operator IS assigned to the sede they work), which the loose check had
never required.

**Owed:** no browser — counter screens un-screenshotted; MySQL suite not run locally. Owner-authorised merge.
779 tests, 776 passed, 3 pre-existing concurrency skips, PHPStan 0.

---

## Prompt 81 — Authorization loose ends (Article-9 exposure + dead permissions)

Re-run after the audit found none of it in the tree.

**Article-9 relation managers gated (the headline).** `ConsumptionRelationManager`, `VisitsRelationManager`
and `OrdersRelationManager` each lift `LocationScope` to show a socio's history ACROSS every sede — health
and attendance data — with NO authorization. A STAFF user with only `members.view` could open any member and
read their org-wide consumption, visits and bar orders. Each now has `canViewForRecord()` gated on
`reports.view` (manager/owner oversight), so the lowest role never sees them. Denial tests both directions
(`MemberRelationManagerGatingTest`).

**`member.limits.set` wired** (was declared, role-assigned to OWNER, and checked NOWHERE — the per-member
gram override at the TOP of `ResolveMemberLimits`' precedence could not be written). New `SetMemberLimits`
action (single writer, audited, reasoned) + `MemberPolicy::setLimits` + a `Límites personalizados` header
action on the Member resource (grams at the edge → centigrams stored; null clears the override). Tested: it
stores cg, beats the org default in the resolver, clears back to the default, and is denied for MANAGER
(owner-only) and STAFF.

**`cash.bank` wired** (the `BANKED` cash movement existed and was even offered in the till UI, but ungated —
any `till.open` holder could bank cash out of the drawer). `TillSession::recordMovement` now whitelists
IN/OUT/BANKED (petty cash keeps its own audited flow) and gates BANKED on `cash.bank`; the blade hides the
option otherwise. `CashBankingTest`: a manager banks (drawer drops), staff forcing the type past the UI is
refused (drawer untouched).

**Honesty.** `member.limits.set` and `cash.bank` were removed from `UnreachableCodeGuardTest`'s
`PERMISSION_ALLOWLIST` (its honesty test would otherwise fail — they are now checked).

**Deliberately NOT done — flagged, not hidden.** `members.transfer` (cross-location member transfer) and
`stock.transfer` (inter-location stock transfer) remain declared, role-assigned and allowlisted. These are
net-new subsystems (two-sided transfers with their own audit/consistency rules), not authorization loose
ends, and building them blind without a spec would be guesswork — each deserves its own prompt. The
allowlist entries keep them honestly visible until then.

**Owed:** no browser — the member limit action + till screens un-screenshotted; MySQL suite not run locally.
Owner-authorised merge. 787 tests, 784 passed, 3 pre-existing concurrency skips, PHPStan 0.

---

## Prompt 78 — Go-live blockers (HTTPS behind a proxy + no install path)

Re-run after the audit found neither in the tree.

**1. `trustProxies` in `bootstrap/app.php`.** Without it, behind any reverse proxy `isSecure()` is false, so
on an HTTPS page the panel and POS emit `http://` asset URLs the browser blocks as mixed content — the admin
panel and POS are unusable on a normal deployment. Added `$middleware->trustProxies(at: ...)` reading
`TRUSTED_PROXIES` (default `'*'` so it works out of the box behind a managed LB; tighten to the LB addresses
in production; a comma-list is exploded to an array). Proven functionally (`TrustedProxyTest`: a request with
`X-Forwarded-Proto: https` is treated as secure) plus a source guard.

**2. `csc:install` command.** There was NO production path to create the Organisation — only the test factory
and the demo seeder — so after a real deploy the `Organisation` didn't exist: the RAT's data controller
(`legal_name`) was blank and no one could log in. The command (re)seeds the roles/permissions (idempotent,
required in every environment), then creates the Organisation (with its `legal_name` — the RAT controller)
and the first OWNER user in one transaction. It refuses if an organisation already exists (unless `--force`),
and validates (legal name required so the RAT is never blank; owner email unique; password ≥ 8). Fully
non-interactive when every option is supplied (deploy automation), prompts otherwise — `??` not `?:` so an
explicitly-empty option is respected and fails validation rather than triggering an interactive prompt.

`User` has no `organisation_id` (staff are global, scoped to the org via the location pivot), so the owner
needs only a role to access the panel — asserted in the test (`canAccessPanel`).

**Tests.** `InstallCommandTest` (creates org+owner, the RAT controller now resolves to the legal name, refuses
when already installed, refuses a blank legal name) + `TrustedProxyTest`.

**Owed:** none material (both are non-visual). Owner-authorised merge. 792 tests, 789 passed, 3 pre-existing
concurrency skips, PHPStan 0.

---

## Prompt 80 — Member exit (baja)

Re-run after the audit found members could not leave.

A member could be EXPELLED (a punitive sanction) but could not simply LEAVE: there was no `CancelMembership`
action, `MembershipStatus::CANCELLED` was assigned nowhere, and although `left_at` (the baja column) existed
and `TransitionMemberStatus` would set it for INACTIVE, nothing ever transitioned a member to INACTIVE
voluntarily — so the libro de socios (`MembersRegister`, which maps `baja => left_at`) printed "—" in the
Departure column.

**`CancelMembership` action.** Cancels every ACTIVE membership → `MembershipStatus::CANCELLED` (across all
sedes — a baja leaves the association, not one premises), then records the baja through the single member-status
writer `TransitionMemberStatus(INACTIVE)` (sets `left_at` + status + audits), plus its own
`member.membership.cancelled` audit row. Reasoned; refused once the member has already left (no double baja).
Gated on **members.edit** (a routine admin act), deliberately NOT `member.sanction` — a voluntary departure is
not a punishment, which is the distinction the existing Suspender/Expulsar actions carry.

**Reachable:** a `Registrar baja` header action on the Member resource (confirmation + reason), gated on
members.edit and hidden once the member has left — so the Action isn't a finished-but-unreachable class.

**Tests (`CancelMembershipTest`):** the baja cancels the membership, sets INACTIVE + left_at, and the libro de
socios then shows the departure date (not "—"); STAFF (no members.edit) is denied; a second baja is refused; a
reason is required.

**Owed:** none material. Owner-authorised merge. 796 tests, 793 passed, 3 pre-existing concurrency skips,
PHPStan 0.

---

## Prompt 79 — Performance (dashboard N+1 + audit-log index)

Re-run after the audit found both untouched.

**1. `Dashboard::membersOverLimit()` was an N+1 on the landing page** — a `->get()->filter()` over
override-carrying members, each running its own `DispensationLine` sum inside the closure (the audit measured
~401 queries / ~20 s). Rewritten set-based: one `pluck` of the members with a monthly override, then ONE
grouped aggregate (`JOIN dispensations … GROUP BY member_id, SUM(grams_cg)`) of this month's COMPLETED grams
for just those members, compared in memory. Two queries, flat in member count (short-circuits to one when no
member has an override). `MembersOverLimitPerformanceTest` pins the query count (≤ 3) so the N+1 cannot return,
and checks the count stays correct.

**2. `audit_logs` index.** The list is `WHERE organisation_id = ? ORDER BY created_at DESC` (+ date filters),
scoped in `AuditLogResource::getEloquentQuery()`. The only index, `(action, created_at)`, is led by `action`
and by leftmost-prefix cannot serve that ordering. Added `(organisation_id, created_at)` in a new migration
(the `(action, created_at)` index stays for action-filtered views). `AuditLogIndexTest` asserts the index
exists.

**Owed:** none material (both non-visual; the win is measured as a query count, not a wall-clock, since the
suite is SQLite). Owner-authorised merge. 799 tests, 796 passed, 3 pre-existing concurrency skips, PHPStan 0.
## Prompt 100 — Filament custom-panel views had no stylesheet

**Root cause confirmed:** the admin panel never had a Filament theme, so panel pages were styled only by
Filament's stock precompiled CSS (the classes its own components need). `app.css` is loaded by the counter
and PWA layouts, NOT by panel pages — so hand-written Tailwind in a custom Filament page (the prompt-99 help
manual/glossary) received nothing and rendered as raw text. `primary-*` is a Filament palette that only exists
inside a Filament theme.

**Fix (framework's supported path).** `php artisan make:filament-theme admin` scaffolded
`resources/css/filament/admin/theme.css` (imports Filament's preset so `primary-*`/the panel palette resolve;
`@source` globs scan `app/Filament/**` and `resources/views/filament/**` — the compile step that was missing),
registered `->viteTheme('resources/css/filament/admin/theme.css')` on the panel, and added the entry to
`vite.config.js`. No `app.css` injected into the panel, no hand-rolled stylesheet.

**One token source, shared — not forked.** Extracted the semantic tokens (`--color-brand/ink/surface/line`
+ `--color-success/warning/error` with prompt 98's per-scheme WCAG values and dark overrides) into
`resources/css/tokens.css`, imported by BOTH `app.css` and the panel `theme.css`. A token edit now reaches
both surfaces; 98's corrected values are verified present in both built bundles (`#166534/#92400e/#b91c1c`
+ dark `#f87171`). `ColourContrastTest` now reads `tokens.css` (the single source). `app.css` keeps only
`--font-sans`.

**Custom-view sweep (resources/views/filament/**):**
- *Were silently unstyled, now fixed by the theme:* `manual.blade` (41 hand-written utilities — the reported
  one), `batch-recall.blade` (13, used by `BatchesTable`), `glosario.blade` (7), `help-menu.blade` (8).
- *Fine — render via their own inline `<style>` partial, independent of the theme:* the dashboard
  (`partials/dashboard-styles` `.csc-card`) and every report (`partials/report-styles`). This is why they
  looked right without a theme (and why prompt 101's `.csc-card` clipping is a separate design issue, not a
  missing-stylesheet one).
- *Fine — built from Filament components (`x-filament-*`), covered by Filament's own CSS:* failed-jobs,
  manage-settings, manage-enforcement, registro-dispensacion, rat, exportacion-contable, system-health,
  libro-socios, reports/report.

**Bundle.** The theme is 632 kB (65 kB gzip) — Filament's preset baseline; the `@source` globs are scoped to
the two custom-view roots, not the whole app, so it scans the custom views (the point) without pulling in
everything.

**Tests (`FilamentThemeTest`).** Source guards (always run): `viteTheme` registered, theme is a Vite entry,
tokens are one shared import (not redefined in `app.css`). Compiled guards (skip when `public/build` absent —
it is gitignored): the built theme serves the help utilities (`primary-*`, `rounded-xl`, `grid-cols-2`,
`tabular-nums`, `scroll-mt-24`), the WCAG token values are identical across both bundles, and the counter
bundle still carries `--color-brand/ink/surface`.

**Owed / not done:** no browser here — the acceptance screenshots (`/ayuda/manual` + `/ayuda/glosario`, both
languages, light/dark, before/after) are NOT produced. Branch pushed, **NOT merged** (per the prompt, and
because the visual acceptance can't be self-certified without a browser). 793 tests, 790 passed, 3 skips,
PHPStan 0, `npm run build` clean.

---

## Prompt 103 — A closed till's Z-report changes after the fact

**Closed sessions read stored figures; open sessions derive live.** `ZReport::for()` merged
`TillSummary::breakdown()` (recomputed live from the ledger — correct for an OPEN session) with the frozen
`counted`/`variance`, so a CLOSED session's `expected` followed the ledger forever while `counted`/`variance`
stayed pinned at cierre. After a routine next-day void the three figures contradicted each other on the face
of the signed document (and `TillReport`'s totals row: Σesperado − Σcontado ≠ Σdescuadre). Fixed in
`ZReport::for()` — for `status = CLOSED` and a stored `expected_cents`, `expected` comes from that stored
figure (written by `CloseTill` under lock); an OPEN session still derives live (prompt 42 depends on it, and it
is the number the operator counts against). Every consumer (`TillReport`, the `TillSessionInfolist` reprint)
inherits it. No new storage column — the three stored figures already express it; the marker is derived.

**Post-close void: ALLOW it, freeze the figures, surface the correction.** Two things are both true — a
next-day correction is legitimate, and yesterday's cash-up must not silently change. So the void is permitted
(blocking it pushes staff into deleting/re-keying/leaving the error), the session's stored expected/counted/
variance are untouched, and the amendment is made VISIBLE: `ZReport` returns `post_close_adjusted` (true when
the live recomputation now differs from the stored figure) plus `expected_live`; `TillReport` flags the row
(«Cerrada (ajustada tras el cierre)») and the reprinted Z (infolist) shows a warning with the current
recomputation. This mirrors `RefundDispensation`'s precedent (a cash refund still needs an open till) rather
than inventing a second pattern.

**The invariant.** `counted − expected === variance` now holds structurally on every closed session (all three
from storage, `CloseTill` sets `variance = counted − expected`). The **totals row reconciles the closed
sessions only** — an OPEN session has no arqueo, so its live `expected` is shown per row but excluded from the
reconciliation total, so an in-progress drawer can't make the total contradict itself. Tested: closed reads
stored, the void-after-close regression leaves all three unchanged + flags the session, open still tracks live,
and the totals row is consistent over a mixed open/closed/voided period.

**Owed:** no browser — the till report totals row + a reprinted adjusted Z un-screenshotted. Owner-authorised
merge (standing "merge + push to main"). 809 tests, 806 passed, 3 pre-existing concurrency skips, PHPStan 0.

---

## Prompt 113 — Member photos + POS signatures: the plaintext files on the encrypted disk

Closes the prompt-32 tracked follow-up. The member photo and the POS signature sat plaintext on the private
Article-9 disk and were served by a bare `temporaryUrl` (no user binding, no policy, no access log). Both are
now on the same footing as ID scans/certs.

**Encrypted at rest.** Photo writes go through `DocumentVault::storeUpload` (Filament `saveUploadedFileUsing`,
`previewable(false)` like the ID scan); the signature capture in `DispensaryPos` now `DocumentVault::put`s the
PNG instead of `Storage::put`. A round-trip test + "bytes on disk are ciphertext" test pin it.

**Served through the authorised, access-logged endpoint — pattern extracted, not forked.** The five prompt-32
protections moved into `App\Support\VaultStream::respond()` (signed route, `u`-binding, `$authorize` closure,
per-view `DocumentAccessLog`, decrypt-at-boundary). `MemberDocumentController` now calls it, and a new
`MemberMediaController` serves the photo and signature through it. `App\Support\VaultUrl` mints the short-lived,
user-bound signed URLs (mirroring `IssueDocumentUrl`). The counter (`CheckInScreen`/`DispensaryPos`) and the
member infolist render the photo via that URL — no bare `temporaryUrl` anywhere on the disk.

**Access log widened.** `document_access_logs.member_document_id` is now nullable and gains a polymorphic
`(subject_type, subject_id)`, so a photo view (subject = Member) or signature view (subject = Dispensation) is
logged the same way. Cross-driver migration (drop FK → nullable → re-add nullable FK; SQLite rebuilds).

**Authorisation.** Photo = `MemberPolicy::viewPhoto` (`members.view` + org) — deliberately NOT the owner-only
`member.documents.view`, because the counter shows the photo to identify the member and STAFF hold
`members.view`; the view is logged regardless. Signature = `DispensationPolicy::view` (counter/report right +
org + location). Denial + URL-replay tests cover both.

**Migration of existing files.** A one-off `csc:encrypt-vault-media` COMMAND (not a schema migration, so the
test suite's filesystem isn't rewritten on every RefreshDatabase) walks `member-photos`/`signatures`,
encrypting only files that don't already decrypt — idempotent, safe to run twice, `--dry-run` supported.

**Erasure.** `AnonymiseMember` already deleted the photo + document files; it now also deletes the member's
POS signature files and nulls the pointers (biometric-ish PII on retained dispensation records).

**Non-member uploads (decision).** Purchase invoices, expense receipts and batch/article/genetic docs share the
disk but are ordinary private BUSINESS documents, not Article-9 special-category — they stay on the private disk
UNENCRYPTED by decision (they are financial, so not `public` either). Encrypting them is a possible defence-in-
depth follow-up, not a compliance requirement, so out of this branch.

**Notes corrected.** `config/filesystems.php`, `DocumentVault`'s docblock and `AUDIT-FINDINGS.md` now say the
follow-up is closed rather than pending.

**Owed:** no browser — check-in/POS photo screenshots + the door-render before/after timing are NOT produced.
A per-view decrypt is heavier than a static signed URL; if the door proves slow the lever is a smaller stored
image or a short in-request cache, never dropping the log. Owner-authorised merge (standing "merge + push").
816 tests, 813 passed, 3 pre-existing concurrency skips, PHPStan 0.

---

## Prompt 110 — The legal stock ceiling: wrong member count, and nothing enforced it

**Arithmetic fixed (the bug).** `StockCeiling::forLocation()` counted every member of the whole association
(`Member::where('organisation_id', …)`) against one sede's stock, so each sede was credited with the entire
org's headroom. It now counts members who are ACTIVE **and** hold an ACTIVE membership AT THIS location (via
`whereHas('memberships', location + status)`), so two sedes with different membership get different ceilings —
the single assertion that is the bug. An expelled/suspended member (member status) or a lapsed membership no
longer raises it.

**Settings scope made consistent.** `daily_limit_cg` was location-scoped, `stock_ceiling_days` global — both
are per-premises concepts, so both now resolve through `ActiveScope::forLocation(...)`.

**"On site" widened (decision).** Was OPEN batches only. A quarantined, closed or expired batch is still
physically on the premises and still counts legally — so on-site now sums remaining across ALL batches at the
sede regardless of status/expiry (a depleted batch contributes 0 through its remaining columns). This is the
number a lawyer reads, so it errs toward counting everything present.

**Now enforced, through the existing machinery.** Added a `stock → ceiling` rule to
`Settings::DEFAULTS['enforcement']`, default **WARN** (a club legitimately receives a harvest that briefly
exceeds a rolling figure; a hard block with no way through would push staff into not recording stock at all).
`IntakeBatch` checks the projected on-site weight after the intake: WARN proceeds; BLOCK/OVERRIDE refuses
unless a `limits.override` holder authorises with a reason, audited as `stock.ceiling.overridden`.
`ManageEnforcement` exposes the toggle (owner-only). **Override permission:** reused `limits.override` (the
existing "authorise a compliance breach" authority — same contract the member limits use) rather than minting
a new permission.

**Stock take.** Deliberately NOT blocked — a recount records physical reality and must never be refused. The
overage a recount reveals surfaces on the dashboard's ceiling indicator, which reads the SAME live
`StockCeiling` intake enforces against (one figure, not two — the dashboard's `ceilingBreaches()` and intake
share it by construction).

**Seed (decision).** The demo deliberately, labelledly exceeds: the curated 16-member base makes each sede's
ceiling small, so realistic dispensary stock trips the WARNING on purpose — a demonstration of the feature. A
genuine fresh install (`csc:install`, prompt 78) has no stock and never alarms.

**Owed:** no browser — the dashboard chart with the corrected ceiling + the intake warning are un-screenshotted.
A Filament CreateBatch override affordance for BLOCK mode is not built (default is WARN; the Action enforces and
accepts an override via its options). Owner-authorised merge. 824 tests, 821 passed, 3 pre-existing skips, PHPStan 0.

---

## Prompt 107 — The dashboard never showed rent, and still counted deleted rows

**One expense rule, expressed once.** The dashboard's outgoings query was a bare
`DB::table('expenses')->whereIn('location_id', $ids)` — no organisation filter, no soft-delete filter, and
crucially no org-level fold-in, so every overhead with `location_id = null` (rent, utilities, insurance) was
dropped and the superávit read healthier than the financial report by the club's largest fixed costs.
Extracted the rule into `Expense::concreteForPeriod(QueryBuilder, org, locationIds, includesAllLocations)` —
organisation + NOT deleted + CONCRETE (no recurrence template) + location scope WITH the `orWhereNull(location)`
fold-in on the all-locations view. `FinancialReport::expensesQuery()` and `DashboardCharts` now both call it,
so they agree by construction (tested: an org-level overhead makes the two totals equal; a location-scoped
view does not pick it up).

**Soft-delete filter on the aggregating raw queries.** `DB::table()` bypasses `SoftDeletingScope`, so four
dashboard aggregates counted deleted rows: expenses (fixed via the shared rule), the stock-levels chart
(`batches`), the new-joiners series (`members`) and the consumption distribution (`members`). Each now
`whereNull(...deleted_at)`. **Also fixed `StockCeiling`** (prompt 110) — it uses `withoutGlobalScopes()`, which
strips `SoftDeletingScope` too, so its member count and on-site batch sum were counting soft-deleted rows; both
now filter `deleted_at`, so the headroom a club reads against the legal cap excludes deleted stock.

**Aggregate vs lookup (decision).** The label-resolution lookups (`StockReport` genetics, financial/attendance/
consumption name lookups) are ALSO raw and unfiltered — and that is correct: a name must still resolve after
its parent row is soft-deleted, or an old report renders blanks. Left untouched; guarded by a test that a
soft-deleted genetic's name still appears on the stock report.

**Sweep result.** `Dispensation`, `Order`, `CheckIn` and `TillSession` have no `deleted_at` column, so their
dashboard aggregates need no filter and already agree with the report (identical status/period filters). The
income streams were verified consistent. The only divergences were the four aggregates above.

**Owed:** no browser — the dashboard/financial-report side-by-side (same superávit, with an org-level overhead)
is un-screenshotted. Owner-authorised merge. 830 tests, 827 passed, 3 pre-existing skips, PHPStan 0.

---

## Prompt 104 — Bar/shop article opening stock never entered the ledger

Articles wrote `stock` straight from the form with no movement, so every article's ledger held only
depletions and summed negative (10/10 wrong); batches were correct because `IntakeBatch` writes the opening
INTAKE. The asymmetry was the bug.

**Opening stock enters through the ledger.** New `IntakeArticle` action (the article mirror of `IntakeBatch`)
creates the article EMPTY and writes its opening balance as an INTAKE through the single stock writer, atomically
— zero opening writes no movement. `CreateArticle::handleRecordCreation` and the demo seeder both route through
it, so every article reconciles (Σ qty_units == stock) from the first row. Tested as a property over the whole
seeded install (articles AND batches), not just one article.

**Restock is an INTAKE, not an ADJUSTMENT.** `ArticlesTable::restockAction` filed each delivery as
`ADJUSTMENT` — but `RecordStockMovement` audits ADJUSTMENT/MERMA as corrections and deliberately does not audit
INTAKE/SALE, so every purchase read as "someone corrected a miscount", destroying that signal. A restock is now
`INTAKE`. A genuine count correction remains `ADJUSTMENT` (still reachable via `CommitStockTake` / `VoidDispensation`).

**Back-fill (decision).** `csc:reconcile-article-stock` (a manual command, not a schema migration, so the test
suite's data isn't rewritten every RefreshDatabase) writes ONE opening INTAKE per pre-existing article sized to
reconcile (`stock − Σqty_units`), with a reconciliation reason so the rows are identifiable as a back-fill, not
a real delivery. Idempotent — an article that already reconciles gets nothing, safe to run twice.

**Stock-writer sweep.** Grepped every write to `stock`/`remaining_cg`/`remaining_units` in `app/`. The only
writers are `RecordStockMovement` (the single writer), `IntakeBatch` and now `IntakeArticle` (opening balances,
both writing their ledger row). The other matches are casts, POS display arrays (reads, not writes) and the
enforcement config — none bypass the ledger. `ArticleForm` restricts `stock` to the create operation, so Edit
can never free-type it.

**Owed:** no browser — the article stock-movement history (opening intake + restock row) is un-screenshotted.
Owner-authorised merge. 835 tests, 832 passed, 3 pre-existing skips, PHPStan 0.

---

## Prompt 112 — The ROPA promised an audit retention the code never applied

`audit_retention_days` (3650) was declared, rendered into the RAT as a binding retention statement, printed
under the audit-log screen and reported by SystemHealth — and nothing ever pruned an audit row, so the register
grew forever: a stated retention never applied is worse than none.

**Redact, not delete (decision).** The register is described — in the ROPA and the UI — as INALTERABLE, and the
model already sanctions exactly one reason to touch an existing row: an Art.17 REDACTION masking payloads (the
`$redacting` flag). The retention sweep reuses that: `audit:redact-retention` nulls the `before`/`after`
payloads of entries past retention, keeping each row's identity (action, actor, subject, date) so the register
stays inalterable and the SHAPE of past activity survives while the special-category detail is removed.
Deletion was the other defensible answer, but it contradicts "inalterable", would need a NEW exception to the
append-only guard, and the ROPA already says "anonimización al vencer" — redaction makes the document true with
the least change. Trade-off recorded: redaction does not bound the table's row count; if size ever matters,
archival is separate future work.

**Accounts for its own gap.** Each run that redacts anything writes ONE summary entry (`audit.retention.redacted`,
count + up-to date), which is itself exempt from the sweep — an audit trail with an unexplained hole is worse
than a truncated one. Batched via `chunkById` (the table is unbounded); `--dry-run`; a `audit-retention-sweep`
heartbeat so `SystemHealth` shows it RED if it stops running (a number with no mechanism is what caused this).
Scheduled daily in `routes/console.php` — scheduled-only, no user-facing delete/edit path (tested: a user delete
or edit through the model throws).

**ROPA updated** to say member data is anonymised AND the audit register is redacted at retention, so document
and behaviour ship together.

**Declared-retention sweep.** `data_retention_days` → `PurgeExpiredMembers` (members) ✓; audit → this ✓; the
per-activity retentions for check-ins/consents/data-requests/member-documents/convocatoria-recipients are all
covered by `AnonymiseMember` (its COVERED_MEMBER_TABLES enumerates why). No declared period is now un-applied.

**Owed:** no browser — the audit-log + SystemHealth screenshots are not produced. Owner-authorised merge. 843
tests, 840 passed, 3 pre-existing skips, PHPStan 0.

---

## Prompt 114 — Integrity harness (acceptance gate for 103–113)

Added `audits/integrity-harness.php` verbatim (use-as-given) — plain PHP, no test framework, reads real data,
rolls back the two mutating sections, exits non-zero on any failure. `composer audit:integrity` runs it;
deliberately NOT in `composer check` (it needs a seeded DB; `check` must run on an empty one). `audits/` is
excluded from Pint so the tool stays exactly as supplied.

**Baseline confirmed as a progress signal.** On a fresh seed with 103/104/107/110 already merged it reports
**22 passed, 9 failed** (up from the batch's 16/15 baseline on bare main). Every remaining failure maps to an
unmerged prompt:
- `closed tills match a fresh TillSummary recomputation`, `every till-cash expense has its PETTY_CASH movement`
  → **106**
- `'today' agrees for <sede>` ×2 → **105**
- `<sede>: on-site stock is within the ceiling` ×2 → **the seed** (see below)
- `every settings key is read somewhere` (temporary_reminder_lead_days, aforo_enforcement) → **111**
- `the till report does not scale queries with sessions` → **108**
- `reading one settings key ten times is not ten queries` → **109**

Target: **31 passed, 0 failed** once 105/106/108/109/111 land.

**Conflict surfaced — supersedes a prompt-110 decision.** The harness asserts `on-site stock is within the
ceiling`, which contradicts the "deliberate labelled overage" I chose for the prompt-110 demo seed. The harness
is the acceptance gate, so it wins: the seed must be brought WITHIN the ceiling. This is folded into **106**
(the seeder branch), which will also flip the two ceiling rows green and update the prompt-110 seed test from
"expects exceeded" to "within".

**Guard (`IntegrityHarnessTest`).** Runs the harness against an isolated COPY of the seeded dev DB: `--list`
exits 0; a directly-broken batch `remaining_cg` makes it exit non-zero (the property that makes it a gate); a
full run leaves the row counts unchanged (the mutating sections roll back).

**Out of scope (recorded):** the two concurrency properties (gram cap + no-oversell under simultaneous
counters) need real child processes and a `--concurrency` mode; verified separately on MariaDB, not in-process.
Owner-authorised merge. 846 tests, 843 passed, 3 pre-existing skips, PHPStan 0.

---

## Prompt 105 — Reports used a UTC calendar day; the cap and Z-report use the business day

`BusinessDay` is the single definition of a day (gram cap, month reset, auto-checkout, entry sheet, Z-report),
but `Period` computed a naive UTC calendar day inline, and every report + dashboard widget ran on `Period` —
4–5 h apart for a Madrid/06:00 club, so a member at the cap could read over or under depending on the document.

**Reports/dashboard now resolve through `BusinessDay`.** `Period::fromKey($key, ?Location)` gains a location;
with one it returns the BUSINESS day/week/month window (`Period::businessWindow`), computed in the location's
timezone then converted to storage-tz instants (so `whereBetween` string-compares like-for-like), exactly as
`BusinessDay::window` does — a test asserts the report day EQUALS the cap day. Without a location it stays the
legacy calendar window (callers with none). `ReportPage` and `Dashboard` pass their scoped sede.

**Multi-location (decision):** resolve against the ACTIVE sede; for the "All" rollup (no single sede) against
the organisation's canonical (first) sede, and state the constraint — sedes share timezone/cutoff in practice,
and when they differ the rollup uses the canonical sede's day. (Union-of-windows was the alternative; the
single canonical sede is simpler and exact when configs match, which they do.)

**`previous()` is DST-correct.** For a business-day period it recomputes in the location's timezone the window
containing the instant just before this one's start, so across a DST transition the previous window is a
genuinely different absolute length — tested at both 2026 transitions (spring-forward previous day = 23 h,
fall-back = 25 h). Storage stays UTC; `app.timezone` unchanged.

**Half-open bounds.** 48 `whereBetween($col, [$start, $end])` sites across the report/dashboard layer compared
inclusively on both ends while `Period` documents `[start, end)`, so a row stamped at the exact boundary fell
in two adjacent periods. All converted to `>= $start AND < $end`. No-op on the seeded data (nothing on a
boundary), a test proves a boundary instant now falls in exactly one period.

**Demo seed → timezone-neutral (UTC/00:00).** So its business day equals the storage calendar day: the demo is
reproducible regardless of the deployer's locale, has no 00:00–06:00 gap to confuse it, and the integrity
harness's day-agreement check is green. Real clubs configure their own timezone + cutoff (fallback Madrid/06:00)
and reports honour it — proven by tests using a Madrid location.

**Harness:** the `dayboundary` section is now 3/3 (was 2 failing); overall **24 passed / 7 failed** (was 22/9).
Owner-authorised merge. 853 tests, 850 passed, 3 pre-existing skips, PHPStan 0.

## Prompt 109 — Settings memo (per resolved scope, safe under multi-tenancy)

`Settings::get()` now memoises resolved values in a request-lifetime static (`self::$memo`), keyed on the
**RESOLVED `(organisationId, locationId, key)`** — never on key alone. The scope is ambient and changes
within one process (the seeder loops locations; a queued worker touches several orgs), so a key-only cache
would hand one club's setting to another — a multi-tenancy leak worse than the query it saves. The key uses
the location *after* `?? $scope->locationId()` resolution, so an explicit-location read and the active-location
read of the same sede share one entry, and a different sede gets its own.

**Only successful resolutions are memoised:** a real override row (cast value), or — when there is no row — the
**constant** `DEFAULTS[$key]`. A key absent from `DEFAULTS` falls through to the caller's `$default`, which
varies per call site, so it is deliberately **not** memoised (else a later call with a different default would
get the first call's). The `catch (Throwable)` degrade-to-default path is never cached — a DB blip must not pin
the app to code defaults for the rest of the request.

**Invalidation:** `Settings::set()` clears the whole memo (writes are rare, reads constant — simpler and safer
than surgical invalidation). Between tests the base `TestCase::setUp()` calls `Settings::flush()`, so one test's
resolved value can never leak into the next (the isolation a fresh request gets for free).

`DebtorReportTest::test_the_query_count_does_not_scale_with_member_count` now flushes the memo before each
measurement so both are COLD renders — otherwise the first render pays the one-time setting query the second
gets free, and the two differ by that constant (which is not the member-count scaling that test guards).

**Harness:** "reading one settings key ten times is not ten queries" → **1 query** (green). Overall **25 passed
/ 6 failed** (was 24/7). Owner-authorised merge. 859 tests, 856 passed, 3 pre-existing skips, PHPStan 0.

## Prompt 108 — Till report: batched Z-report (no N+1 over sessions)

`TillReport::sessions()` previously called `ZReport::for($session)` inside its per-session loop, and each
`for()` issued ~12 queries (the `TillSummary::breakdown` sums, two transaction counts, two void counts) — so a
period report scaled its query count linearly with the number of sessions.

Introduced batched siblings and made the single-session entry points delegate to them, so the arithmetic and
the per-model scoping have ONE definition and a batched figure can never disagree with a per-session one:
- `TillSummary::breakdownMany(Collection $sessions)` — one grouped `SUM … GROUP BY till_session_id` per ledger
  source (dispensations, orders, wallet top-ups/refunds, fees, cash movements), returning `[id => Breakdown]`.
  `TillSummary::breakdown($session)` now delegates with a one-element collection.
- `ZReport::forMany(Collection $sessions)` — calls `breakdownMany` once plus two grouped COUNTs (total +
  `SUM(CASE WHEN status = VOIDED …)`), returning `[id => zArray]`. `ZReport::for($session)` delegates.
- `TillReport::sessions()` calls `ZReport::forMany($sessions)` once.

Query count is now FLAT in session count (a fixed ~12 whether 5 sessions or 29). The two grouped-aggregate
helpers are `@template TModel of Model` generic (Builder's model generic is invariant, so a non-generic
`Builder<Model>` param rejects `Builder<Dispensation>`); aggregate columns are read via array access
(`$row['agg']`), which returns the dynamic attribute without a declared-property assumption.

**Harness metric corrected (not loosened).** The old check divided total queries by session count and asserted
`< 2.0` — date-fragile (at a month boundary "this month" holds a session or two, so a correct O(1) report's
fixed overhead reads as scaling) and it never compared two session counts, so it could not tell flat from
linear. Replaced with a direct test: render the report over a narrow window and a wide one and assert the wide
window has MORE sessions but the SAME query count. This still fails loudly if a per-session query returns.

**Harness:** "the till report does not scale queries with sessions" → **12 queries for 5 sessions, still 12 for
29 — flat** (green). Overall **26 passed / 5 failed** (was 25/6). The remaining 5 are 106 (petty-cash movement,
2 stock-ceiling, closed-till recompute) and 111 (two unread settings keys). Owner-authorised merge. 859 tests,
856 passed, 3 pre-existing skips, PHPStan 0.

## Prompt 111 — Wire the temporary-member expiry reminder; retire the dead aforo_enforcement key

Two settings keys were never read (the harness "every settings key is read somewhere" row named both).

**`temporary_reminder_lead_days` — now wired.** The `members:remove-temporary` sweep gained a REMIND step ahead
of its REMOVE step: a temporary member whose window closes within the configured lead gets one heads-up push
(`TemporaryAccessEndingNotification`, new `temporary_ending` opt-out channel) so they can ask to continue before
the auto-anonymisation removes them. Idempotent via a new nullable `members.temporary_reminder_sent_at` marker
(same shape as `memberships.reminder_sent_for`); the push is a silent no-op for a member with no subscription or
a channel opt-out (the base `via()` gates both); `--dry-run` reports without sending or stamping. The command's
name stayed `members:remove-temporary` (one scheduled job owns the whole temporary lifecycle) with a broadened
description. `MemberPushTest` now asserts `array_keys($cases) == Member::PUSH_CHANNELS`, so a future channel
can't ship without a render + opt-out case.

**`aforo_enforcement` — removed.** It was dead: aforo is a fixed BLOCK via the enforcement matrix
(`enforcement.door.aforo`), there is no block/warn toggle, and `LocationForm` documents "No aforo control".
Deleted from `Settings::DEFAULTS` and from the `DebtAndLocationSettingsTest` exclusion list (stale reference).

**Harness:** "every settings key is read somewhere" → **51 keys** (green); "no aggregate references a column that
does not exist" stays green (301 columns — the new marker column exists after migrate). Overall **27 passed / 4
failed** (was 26/5). The remaining 4 are all prompt 106 (petty-cash movement, 2 stock-ceiling, closed-till
recompute). Owner-authorised merge. 862 tests, 859 passed, 3 pre-existing skips, PHPStan 0.

## Prompt 106 — Demo seed routed through the owning actions; stock seeded within the ceiling

The demo till lifecycle was hand-built, and its stock deliberately over the ceiling. Both are now corrected —
this is the "route the seed through the Action" rule (CLAUDE.md) applied to the last places that dodged it.

**Till through its Actions.** `seedFortnight` now opens with `OpenTill`, records petty cash with
`RecordTillExpense` (which writes the matching `PETTY_CASH` cash movement — the hand-built `Expense` wrote none,
so the drawer read 12 sessions over), and closes with `CloseTill` (so `expected_cents` is DERIVED from the
ledger, never a hand-tallied figure a later void could contradict). Only the wall-clock timestamps
(`opened_at`/`closed_at`, `Expense.incurred_on`) are backdated onto the action-created rows to build historical
demo days — the financial shape is entirely action-owned. Dispensations keep the documented
compliance-boundary carve-out (`CommitDispensation` would reject back-dated data), orders already went through
`CommitOrder`.

**Stock within the ceiling (supersedes prompt 110's deliberate overage).** Members are now seeded BEFORE the
catalogue so each sede's ceiling (`active-members × daily-limit × ceiling-days`) is known, and total opening
intake per sede is capped at 80% of it, split across the genetics. Because dispensing only REDUCES stock,
on-site can only end lower — so a fresh seed always reads "within" (Central 117g / 245g, North 54g / 140g). The
ceiling WARNING is exercised by `StockCeilingTest`, not by shipping demo data that alarms. `DemoSeedProfileTest`
flipped from asserting a sede exceeds to asserting every sede sits within.

**Harness:** all four remaining rows green — closed-till recompute, zero orphaned petty-cash, both sede ceilings.
Overall **31 passed / 0 failed** — the acceptance gate target for prompts 103–114 is met. Owner-authorised merge.
862 tests, 859 passed, 3 pre-existing skips, PHPStan 0.

## Prompt 119 — Member discounts are chosen from templates, not typed by hand

The member "Asignar descuento" form let an owner type a `mode` + a percentage/amount, but offered no way to
pick one of the org's pre-made discounts — so every member discount the UI could produce was a global,
unnamed one-off, and (the owner's second complaint) the "Tipo" dropdown promised a discount list but showed
units of measurement. The link path already existed end to end (`member_discounts.discount_id`,
`MemberDiscount::discount()`, `AssignMemberDiscount` accepting `discount_id`, `ResolvePrice` resolving it); only
the form never collected a `discount_id`.

**Chosen, not described.** The assign form is now a single `Select` over the organisation's ACTIVE discounts
(`discountOptions()`, scoped by the Discount model's org global scope), each shown as "Name — value (scope)"
e.g. "Staff — 10 % (todo)". It passes `discount_id` to `AssignMemberDiscount`. The free-value fields
(`mode`/`value_pct`/`value_eur`) are gone.

**Why it's a correctness fix, not just tidiness.** A linked discount keeps its own `applies_to`: `ResolvePrice`
and `ResolveArticleDiscount` both honour GENETIC/ARTICLE/BOTH, so a genetics-only therapeutic rate does not
touch the bar. A hand-typed "15 %" is global by construction and silently applied everywhere. A test asserts
the same assigned therapeutic template discounts a genetic line but contributes 0 bp at the bar.

**Decisions recorded:**
- **No free-value escape hatch survives.** A new rate is created once as a named, auditable, reusable Discount
  and then assigned — the owner's instinct, and the right default. There is no permission-gated inline path.
- **Legacy inline rows are kept working and read-only-except-expiry.** They still price through `ResolvePrice`
  (regression-tested), show as `Personalizado`, and can have their expiry changed or be removed — not migrated
  (which would invent names) and never silently dropped. `UpdateMemberDiscount` now edits ONLY the expiry
  (preserving `discount_id` and any inline value); to change a rate you remove and reassign.
- **Naming.** "Tipo" no longer means three things: the form field and the member table column that name WHICH
  discount are both "Descuento"; the value column is "Valor"; the edit action is "Editar caducidad". "Tipo"
  survives only on the Descuentos templates list, where it means the discount's `kind`.
- **Date format.** The expiry picker is `native(false)` with `displayFormat('d/m/Y')` (dd/mm/aaaa), not the
  browser-default US mm/dd/yyyy.

**Deferred / owed:** the relation-manager tab row clips its last tab on narrow widths — the same can't-shrink
defect class as prompt 101; noted there rather than fixed blind here. Screenshots (assign modal before/after,
an assigned template discount + the resulting POS price, light/dark) are OWED — no browser in this environment.
New labels shipped in `lang/en` + `lang/es` (parity gated). `ResolvePrice`/`ResolveArticleDiscount` unchanged.

## Prompt 115 — Statutory identity on documents (one resolver, refuse without a legal name)

Every statutory document printed `config('app.name')` — the PRODUCT name — where it must carry the
association's own legal identity. Promoted `Rat::controller()` into `App\Support\OrganisationIdentity`, the one
place identity resolves: `current()`/`for()` return name, legal_name, **display_name** (legal name, or trading
name only as a last resort), tax_id, address, contact_email/phone, and a base64 **logo** data URI (dompdf
cannot fetch a path; the logo degrades to null on any error so a document still generates). `Rat::controller()`
now delegates here.

Wired into the libro de socios, registro de dispensación, convocatoria, actas, the report PDFs, the member
document and the RAT — via a shared `documents/partials/identity.blade.php` header (logo + legal name + CIF/NIF
+ address). Register PDF member dates now render **d/m/Y** (were ISO `Y-m-d`).

**Refuse without a legal name.** `App\Filament\Concerns\GuardsStatutoryDocuments` (`hasStatutoryIdentity()`)
blocks the libro de socios, registro de dispensación, convocatoria and acta exports when the org has no legal
name, sending a notification that says where to set it, rather than emitting a document that prints the trading
name as if it were the legal identity. Those export methods now return `?StreamedResponse` (and
`ReportPage::exportPdf` was widened to nullable so `RegistroDispensacion` can refuse). **Decision:** the generic
*informes* are NOT hard-blocked — they carry the identity header but a management report should not be
un-runnable for a missing legal name; `display_name` falls back to the trading name there.

**OVERNIGHT-DEFAULT — CONFIRM:** the demo seed's placeholder identity (`TBD-…`) is replaced with plausible
values — legal_name "Asociación Cannábica CSC Demo", CIF `G00000000` (G is the Spanish form for an asociación),
Madrid/Barcelona addresses. A real club sets its own in Ajustes; these exist only so the seeded club can
generate documents out of the box.

`composer check` green (873 tests, 870 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated.

## Prompt 117 — Go-live gaps: install seeding, env docs, CI (landed early so every later branch is CI-checked)

**Install seeds expense categories.** `csc:install` now calls `ExpenseCategorySeeder::seedFor($org->id, locale)`
inside the create transaction, so a freshly-installed club can record petty cash and overheads from day one
(previously only the demo seeder created them; a real install had none, and the till expense flow needs the
TILL category). Idempotent `firstOrCreate`, keyed on the active locale.

**Four production env vars documented in `.env.example`:** VAPID (`VAPID_SUBJECT`/`PUBLIC_KEY`/`PRIVATE_KEY` —
member push; no keypair ⇒ nothing sent; private key server-only), `TRUSTED_PROXIES` (default `*`; tighten to the
load balancer in prod), `CSP_ENFORCE`, and `AWS_DOCUMENTS_SSE` (+ `AWS_DOCUMENTS_SSE_KMS_KEY_ID`) for the
private ID-scan bucket's server-side encryption.

**OVERNIGHT-DEFAULT — CONFIRM: `CSP_ENFORCE=false`** in `.env.example` (report-only) — matching the code
default. Rationale: ship production report-only first, confirm the report endpoint shows no violations, THEN
flip to `true` to enforce. Turning enforcement on blind can break legitimate resources; report-only observes
without risk. A security-conscious club SHOULD enable it after verification.

**CI workflow (`.github/workflows/ci.yml`).** Two jobs on push-to-main and every PR: (1) `composer check` on
SQLite (Pint → Larastan → PHPUnit `:memory:`), (2) the full suite against a real MySQL 8 service via the
committed `phpunit.mysql.xml` (root / empty password / `csc_platform_test`) — so driver-divergence bugs (JSON,
booleans, strict types, string lengths) surface per-branch instead of at the end. PHP 8.3 (the composer floor).
Not added to `composer check` (that must stay runnable on an empty DB).

`composer check` green (874 tests, 871 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity unaffected (no new
user-facing copy).

## Prompt 102 — One till per sede is the default; terminal CRUD moves to admin

Most clubs run a single drawer, but the counter always demanded a terminal (a picker AND a free-text "new
terminal" box), so a one-till sede met an ambiguous form and could open a phantom till by typo.

**`multiple_tills_enabled` per-location setting (default `false`)** — added to `Settings::DEFAULTS` and the
LocationForm toggles (the `SETTING_TOGGLES` pattern, stored as a location-scoped Setting row). OFF (the default):
the counter presets the sede's single terminal and the open form asks ONLY for the float — no picker, nothing
to get wrong. ON: the operator picks from the sede's CONFIGURED terminals.

**`TillSession::$newTerminal` removed.** Terminals are no longer free-typed at the counter; they are managed in
admin — a `TagsInput` on `terminals` on the LocationForm (terminal CRUD moved there). `open()` uses the preset
default terminal (single-till) or the picked configured one (multi-till); `TillSession::multipleTills()` and
`defaultTerminal()` (first configured terminal, or `POS-1`) drive both the component and its view. OpenTill
still normalises + registers any terminal it is handed (prompt 84), so the two paths can't desync.

Tests: `SingleTillTest` (default is single-till; open asks only the float; the configured name is used; two
sedes honour their own setting) and `TillTerminalPickerTest` now enables multi-till (the picker is that path).
`composer check` green (877 tests, 874 passed, 3 pre-existing skips). Visual layout of the open form (float-only
vs picker) is behaviour-verified via Livewire; a screenshot pass is owed (no browser here). EN/ES parity gated.

## Prompt 122 — Separate "operate the counter" from "browse the whole archive"

Three `viewAny` policies used an OPERATIONAL permission (one staff hold) to authorise browsing everyone else's
historical record. Operating is not archival access.

**Before → after (STAFF actor):**

| Route | Before | After | Manager |
|---|---|---|---|
| `/dispensations` (Art-9 archive) | ALLOW (`pos.use`) | **DENY** | ALLOW |
| `/expenses` (rent, invoices) | ALLOW (`expenses.record`) | **DENY** | ALLOW |
| `/orders` (bar purchase history) | ALLOW (`pos.bar`) | **DENY** | ALLOW |
| `members/create` (direct enrol) | ALLOW (`members.create`) | **DENY** | ALLOW |
| single dispensation `view` (receipt/refund lookup) | ALLOW | ALLOW (unchanged) | ALLOW |

`DispensationPolicy::viewAny` and `OrderPolicy::viewAny` now key off `reports.view`; `ExpensePolicy::viewAny`
off `expenses.overheads`/`expenses.approve` (dropping `expenses.record`). The single-row `view` abilities keep
`pos.use`/`pos.bar`/`expenses.record` as before, so the counter's legitimate single-member reads (the receipt
controller, the refund lookup, the POS member card) are untouched — verified: the counter uses `pos.use`/
`pos.bar` DIRECTLY and the single-row `view`, never `viewAny`, and the full staff-shift suite stays green.

**`members.create` — OVERNIGHT-DEFAULT — CONFIRM:** removed from the STAFF role. Admitting a member is a
board/assembly act in a Spanish asociación, and application review is already manager-gated
(`applications.review`); the direct enrol route should not be more open than the reviewed one. MANAGER keeps
both, so enrolment stays possible at manager level. A club that wants on-the-spot staff enrolment grants
`members.create` back to STAFF.

**viewAny sweep (all policies):**
- Same shape, FIXED: Dispensation (`pos.use`), Order (`pos.bar`), Expense (`expenses.record`).
- Same shape, LEFT + reported: `TillSessionPolicy::viewAny` = `till.open || till.close` lets staff browse every
  operator's Z-report. Left because a till session is operational CASH data (not member/Art-9), the design
  documented the intent (staff review their own drawer), and the branch scopes the fix to the member-consumption
  and expense archives. A club could tighten it to `till.close`; flagged here rather than changed blind.
- Correctly scoped (no change): Purchase (`purchases.manage`), Batch (`stock.manage`), Genetic
  (`genetics.manage`), Article (`articles.manage`) — management perms STAFF do not hold; Member (`members.view`)
  is the deliberate counter identity read, with the statutory register gated separately at `register.view`.

`composer check` green (882 tests, 879 passed, 3 pre-existing skips). Anonymous surface + member-PWA isolation
unchanged (existing security-sweep tests still green).

## Prompt 118 — One visit, one payment, two records (combined dispensary + bar settle)

A member who takes cannabis AND buys at the bar in one visit now settles once, but the two stay on SEPARATE
ledgers — a Dispensation and an Order, never a merged row — because they are legally different (a shared-cost
aportación vs a bar sale) and bar spend must never touch the gram cap.

**`App\Actions\Counter\CommitCombinedSettle`** orchestrates the two UNCHANGED single writers
(`CommitDispensation`, `CommitOrder`) so the pair is ATOMIC — an outer `DB::transaction` wraps both (each nests
via a savepoint), so a failing order rolls the dispensation back with it; no "cannabis taken, bar not charged"
state is ever visible. The dispensation commits FIRST (it is the compliance boundary — eligibility, carencia,
gram limits), so a blocked visit stops before any bar stock or cash moves. It adds the ONE thing neither writer
can do alone: a **combined wallet-limit check** up front — each writer records its wallet spend with allow_debt
(its per-writer debt check is bypassed), so a member could otherwise wallet-pay each half within limit yet blow
it across the two; this validates the combined draw against balance + debt allowance before any write.

**Reachable entry:** `DispensaryPos::settleWithBar()` — a bar basket (`barBasket`, articles only) added alongside
the dispensation basket; one shared tender covers the combined total, allocated wallet-to-dispensation-first,
cash-remainder-each. Both receipts (`lastDispensationId` + `lastOrderId`) land. The plain dispensation `commit()`
path is untouched; the combined quick-settle stays for the clean case (an override-needed dispensation uses the
ordinary flow). Bar items never enter the gram cap — inherent in keeping them on the Order.

Tests (`CommitCombinedSettleTest`, 6): one dispensation + one order on separate ledgers; atomic rollback on a
failing order (stock restored); combined wallet gate refused up front AND a within-balance draw settles;
bar-items-never-in-the-gram-cap; and the end-to-end `settleWithBar` Livewire flow lands both receipts.

**## Verification gap** — the ONE combined-settle piece not verifiable here is the VISUAL rendering of the new
bar panel in the tablet POS (no browser):
- Required (unrun): screenshot the combined settle showing two receipts, light + dark, at 1024×768.
- What I changed that these exercise: `resources/views/livewire/counter/dispensary-pos.blade.php` (a contained
  "Barra y tienda (misma visita)" card in the basket column — bar quick-add pills, bar basket list, and the
  "Liquidar visita · <total>" button, all reusing existing card/button classes).
- Believed result: the panel sits below the aportación total in the right basket column and appears only where
  the sede runs a bar; behaviour (add/remove/settle, both receipts, combined total) is Livewire-tested green.

`composer check` green (888 tests, 885 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated.

## Prompt 123 — The idempotency guarantee is the unique index; the race loser returns the winner

Two genuinely concurrent commits of one basket charged the member once and moved exactly the right stock (the
defence in depth working) — but the request that LOST the race died with a raw
`UniqueConstraintViolationException` on the one screen where an operator must know whether a sale went through,
and their natural response was to try again.

`CommitDispensation` and `CommitOrder` now catch that violation and do what the pre-check would have: re-read the
row for the key and return it, so the caller cannot tell it won or lost (the whole point of an idempotency key).

- **The guarantee is the UNIQUE INDEX** on `idempotency_key`; the check-then-insert pre-check is only a fast path
  for the common non-concurrent retry (unchanged). Under true concurrency both requests miss the pre-check and
  both insert; the index refuses the second, and the catch turns that refusal into the return path.
- **Transaction boundary:** the catch wraps the `DB::transaction(...)` call, so the re-read runs on the now-HEALTHY
  connection AFTER the doomed transaction has rolled back — never inside it.
- **Scoped to the idempotency key, no driver string-matching:** Laravel raises the typed
  `UniqueConstraintViolationException`; the catch re-reads BY KEY and returns only if a row exists. A violation on
  any OTHER constraint finds no row for this key and rethrows as the real error it is.
- **Nothing written changed** — the data behaviour is byte-identical; this only changes the losing request's
  OUTCOME. The pre-check was extracted into an overridable `findByIdempotencyKey()` (fast path only; the catch
  keeps its own inline re-read) so the race is exercised DETERMINISTICALLY: a test forces a pre-check miss against
  a real pre-committed winner, on `:memory:`, without needing OS-level parallelism.

Tests (`IdempotencyRaceTest`): the unique index exists on both tables; a sequential retry returns the original and
moves stock once (pre-check path); a race-loser returns the winner without throwing and rolls its own stock move
back (catch path) — for both a dispensation and an order. `composer check` green (892 tests, 889 passed, 3
pre-existing skips, PHPStan 0). No user-facing copy (the fix is silent by design).

## Prompt 101 — Dashboard stat cards clip their own content

The stat card (`.csc-card`) has `overflow: hidden` (for its rounded corners + spark), and its header is a flex row
`icon · label · delta` where `.csc-card-label` had no `min-width: 0`. A flex item defaults to `min-width: auto`
(won't shrink below its content), so a long label pushed the delta out and was clipped by the card rather than
truncating. Fixed in `resources/views/filament/pages/partials/dashboard-styles.blade.php`:
- `.csc-card-label` → `flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;`
  (takes the free space and truncates with an ellipsis; the delta stays visible).
- `.csc-card-head` → `min-width: 0` (so the head can shrink within the card).
- `.csc-card-body > div` → `min-width: 0` and `.csc-card-value` → `overflow-wrap: anywhere` (a long figure wraps
  beside the ring instead of clipping).

## Verification gap

Required tests not run here (no browser):
- Screenshot the dashboard stat cards across 1440 / 1280 / 1024 / 390 and a short laptop height, light AND dark,
  asserting no stat card clips its label or value (long labels show an ellipsis; long values wrap).

What I changed that these would exercise:
- `dashboard-styles.blade.php` — the `.csc-card-label` / `.csc-card-head` / `.csc-card-body > div` /
  `.csc-card-value` rules above (the flex `min-width:0` + `text-overflow` truncation mechanism).

What I believe the result should be:
- No stat card clips content at any width: the label truncates to an ellipsis when it can't fit (delta still
  visible), and a long value wraps rather than being cut by the card's `overflow:hidden`.

Also noted (deferred): the relation-manager TAB row clipping flagged in prompt 119 is a Filament resource-tabs
component, not a dashboard card — the same `min-width:0` defect class but a different surface; left for a
Filament-theme pass rather than fixed blind here. Separately observed a PRE-EXISTING flake (not from this branch):
`TemporaryMemberTest::test_enrolling_a_temporary_member_computes_the_expiry_from_the_window` intermittently fails a
`diffInDays == 30` assertion under full-suite timing — worth hardening now that CI (prompt 117) runs the suite.

`composer check` green (892 tests, 889 passed, 3 pre-existing skips, PHPStan 0). Pure CSS/Blade — no logic tests.

## Prompt 116 — Counter touch / keyboard

Concrete, mechanically-correct fixes applied:
- **Bar POS autofocus.** The bar POS member-search input now carries `autofocus`, matching the dispensary's scan
  input, so the operator can type/scan the moment the screen loads — no click first.
- **Portrait nav labels.** The counter top-bar nav labels were `hidden lg:inline` (shown only ≥1024px), so a
  tablet in PORTRAIT (768–834px) saw icons only. Changed to `hidden md:inline` (≥768px) so labels show in
  portrait.
- **44px touch targets.** Bumped every sub-44px PRIMARY interactive element on the two POS screens to ≥44px:
  the bar POS quantity steppers (`h-8 w-8` → `h-11 w-11`, 32→44px) and all `h-10` (40px) buttons/links —
  "Ir a la caja", "Anular", the signature Borrar/Guardar — to `h-11` (44px).

## Verification gap

Required tests not run here (no browser):
- At 1024×768 (tablet) light AND dark: assert NO interactive element on any counter screen (dispensary, bar,
  check-in) is under 44×44 CSS px.
- Bar POS keyboard: focus lands on the member search on load, and Tab traverses search → article grid → tender
  → commit in that order.
- Portrait tablet (≈768–834 wide): the counter nav shows text labels, not icons only.
- Dispensary "vertical budget": at a short laptop height / tablet portrait the dispensary column fits without the
  primary commit action being pushed below the fold.

What I changed that these exercise:
- `resources/views/livewire/counter/bar-pos.blade.php` (autofocus on `#member-search`; `h-8 w-8`→`h-11 w-11`
  steppers; `h-10`→`h-11` buttons), `resources/views/livewire/counter/dispensary-pos.blade.php` (`h-10`→`h-11`),
  `resources/views/components/counter/top-bar.blade.php` (nav label `lg:inline`→`md:inline`).

What I believe the result should be:
- No interactive element under 44×44 on the two POS screens; the bar POS focuses its search on load; portrait
  tablets show nav labels. The check-in screen's audit and the dispensary vertical-budget check still need a
  browser to confirm/measure.

`composer check` green (892 tests, 889 passed, 3 pre-existing skips, PHPStan 0). No new copy.

## Prompt 124 — Survive a Redis outage (permission cache off Redis)

A stopped Redis 500'd **every** authenticated screen — the counter and even the System Health page — because
`spatie/laravel-permission` loads its permission set from the configured cache store (`CACHE_STORE=redis`) on
every `$user->can()`, and Filament + `SetLocale` check permissions on every request. Sessions were never the
cause (`SESSION_DRIVER=database` already); authorization alone was.

**Where the permission cache now lives.** `config/permission.php` `cache.store` is
`env('PERMISSION_CACHE_STORE', 'database')` — deliberately NOT the default (Redis) store. `database` survives a
Redis outage (the DB is already a hard dependency — the register cannot be written without it) AND stays SHARED
across workers, so a role edit + `php artisan permission:cache-reset` still propagates everywhere (a per-process
`file`/`array` store would not). Smallest change, largest effect — the package's own supported knob. Trade
recorded: one extra DB read on a permission-cache miss, negligible against the many DB reads a request already
makes. `PERMISSION_CACHE_STORE` documented in `.env.example` + `SETUP.md`.

**What the counter does during an outage.** It keeps trading: authenticated screens render (they no longer touch
Redis for authz; sessions are database; `Settings::get` already degrades). The paths that STILL touch Redis — the
login throttle, an explicit cache/queue call — now hit a `render` handler in `bootstrap/app.php` that returns a
stated **"infrastructure degraded"** 503 (`errors/degraded.blade.php`, self-contained — no cache/auth/asset
dependency) instead of a stack-trace 500 or a silent bounce. Scoped to Redis by exception type
(`Predis\PredisException` / `RedisException`) or the `:6379` in its message, so a DB outage is untouched.

**System Health survives what it reports on.** New `SystemHealth::cache()` does a trivial round-trip against the
default store and reports reachable/unreachable, NEVER throwing; the page renders and shows the cache as *No
accesible*. **Login** says something true (the 503 message) rather than silently returning to an empty form.

**Queue side (verified, documented).** `QUEUE_CONNECTION=redis`, so jobs stop with Redis — expected and much
less severe: jobs already enqueued survive (Redis persistence) and resume on recovery; a NEW dispatch during the
outage fails loudly (the request gets the degraded message), so nothing is silently lost. The scheduler
heartbeat is DB-based (`HeartbeatLog`), so it stays fresh through a Redis outage and correctly reports a gap only
when the CRON is actually down, not when Redis is.

**Recovery stays automatic** — Redis returning restores everything with no restart (tested). No data behaviour
changed; caching was relocated, not removed.

Tests (`RedisDegradationTest`, 5): counter + dashboard render 200 with the default cache unreachable (the
branch); System Health renders + reports degraded; a Redis failure surfaces the 503 message; permission changes
apply after `cache-reset` on the new store; recovery. `composer check` green (897 tests, 894 passed, 3
pre-existing skips, PHPStan 0). EN/ES parity gated.

## Prompt 125 — Harden the temporary-member date assertion (before CI teaches everyone to ignore it)

`TemporaryMemberTest::test_enrolling_a_temporary_member_…` asserted `(int) $joined_at->diffInDays($expires) === 30`
— fragile twice over: `diffInDays` returns a float and `(int)` truncates (any sub-day drift makes 30→29), and it
hard-coded the window. Flaky before CI existed; now that prompt 117 runs the suite per push it would go red at
random, and an intermittently-red build trains everyone to shrug at CI — exactly when 120/121 need the signal.

**What it now asserts.** The RULE, as timestamps: `temporary_expires_at` equals `joined_at + temporary_window_days`
(`->equalTo($member->joined_at->copy()->addDays($window))`), with the window read from `Settings` not hard-coded,
and **time frozen** (`freezeTime()`) so the enrolment's internal `now()` cannot race the clock. It still fails if
the code computes from the wrong base or the wrong window; a new test proves changing `temporary_window_days`
changes the expectation rather than breaking the test.

**Root cause: truncation, not order-dependence.** Four consecutive `--order-by=random` full runs were green (895
passed each), plus the default-order `composer check` run — so the ~20 `travelTo(...)`-without-`travelBack()`
files (incl. `BusinessDayPeriodTest`'s DST dates) are correctly reset by Laravel's `tearDown` and leak no time.
The assertion fix is the whole answer.

**`diffIn*` truncation sweep.** Only `TemporaryMemberTest:93` was unsafe. `BusinessDayPeriodTest:92/97`
(`(int) diffInSeconds === 23h/25h`) are SAFE — both sides are computed from a frozen `travelTo` DST date, so the
values are exact multiples of 3600 and the truncation is lossless. No other `(int) diffIn*` exact-integer
assertions exist.

**CI asset build (load-bearing).** Prompt 100's Filament theme makes every panel-page test resolve the Vite
manifest (`resources/css/filament/admin/theme.css`), and `public/build` is gitignored — so CI would fail EVERY
run with `ViteException` without a build. Added `actions/setup-node` + `npm ci && npm run build` to both CI jobs
before the test step, commented as required-not-optional. (This is a hard dependency the earlier 117 workflow
missed, surfaced by 125's clean-checkout note.)

No production code changed. `composer check` green (898 tests, 895 passed, 3 pre-existing skips, PHPStan 0) plus
four green randomised runs.

## Prompt 126 — Surface the bar manual line (discoverability + friction)

The manual bar line already existed (`BarPos::miscDescription/miscAmount/miscReference`, `CommitOrder`
description/unit_price line, distinguishable snapshot + receipt) but sat as a section BELOW the article grid —
below the fold on a 768–1024px tablet — and asked for three fields with a mandatory free-text reference.

**Where it lives now.** A **"＋ Importe manual" button in the article header** (always visible, beside the
search) opens an Alpine **modal**. It is no longer a section you scroll past a full catalogue to reach. The modal
closes only on SUCCESS (a `misc-added` Livewire event), so a validation refusal keeps the operator's input.

**The reference requirement.** KEPT (a free-text line has nothing to reconcile against — operator + till are
already recorded, so the reference carries the *why*, not the *who/when*), but made ONE TAP: reason chips
(*Artículo sin dar de alta / Precio especial / Evento*) set the field, with free text as the fallback and helper
text that says what it is for. So the categorised, analysable field is faster than the old blank box — removing
the "type x / take cash off book" failure mode rather than the auditability.

**Identifiable in the report.** `BarSalesReport` already grouped manual lines separately (article_id null); added
an explicit `manual` flag on the row so an owner can pick out exactly the off-catalogue lines.

**Below-the-fold sweep.** This is the third built-but-unfindable counter feature — the per-sede signature toggle
(now on LocationForm) and the member-discount picker (prompt 119) were the others. A full visual sweep of every
counter control at 1024×768 needs a browser (owed); known candidates flagged.

## Verification gap
- Required (unrun, no browser): at 1024×768 with a full catalogue, the manual-line control is visible without
  scrolling. Its PRESENCE + header placement is asserted (`BarMiscLineTest`); the pixel measurement is the
  owed screenshot.

Tests (`BarMiscLineTest`, 5): control present; a manual line commits with its description + reason and moves no
stock; an empty reason is refused; the line is flagged in the bar report; a genetic still cannot reach an order.
`composer check` green (903 tests, 900 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated.

---

## Prompt 127 — Collecting a membership fee, wherever the member is standing

**Problem.** The only way to take a membership fee was buried inside the till screen, so a member who
came specifically to pay, or who was flagged *cuota pendiente* at the door or the POS, forced the
operator off their current screen to collect it. Fee collection needed to live where the member is.

**One writer, one concern — never a second path.** Extracted the till screen's fee logic into a shared
`App\Livewire\Counter\Concerns\CollectsMembershipFees` trait (search/outstanding/owed/parse state +
`collectFeeThrough()`), and refactored `TillSession` onto it with byte-identical behaviour (it still
passes its own resolved session, so a fee there is unchanged). `RecordFeePayment` stays the SINGLE
writer; the trait only holds the shared validation (amount ≤ owed, method, the CASH-needs-an-open-till
invariant) and returns a result the host flashes. Collecting a fee remains the ONLY thing that clears
the `unpaid_fee` verdict.

**Part 1 — the Socios tab.** New `MembershipCounter` component + `counter.members` route + a fifth nav
entry (`Socios`), gated on the existing `membership.fee.collect` (unchanged — not widened). A THIN shell:
find a member, see tier/expiry/owed, collect a fee (CASH or WALLET, full or partial). Deliberately SMALL
— renewals, tier changes and suspensions stay in the admin panel where they carry authorisation weight.
The tab can take a WALLET fee with no till open, but refuses a CASH fee without one (drawer
reconciliation), exactly as the till screen does.

**Part 2 — the action follows the verdict.** Added `collectInlineFeeFor(member, session, location, user)`
to the same trait (points the shared state at an already-held member; a blank amount defaults to the FULL
owed balance) and a shared `partials/inline-fee.blade.php`. Wired it into `CheckInScreen` (the door) and
`DispensaryPos` (the POS member card): wherever the `unpaid_fee` verdict is rendered and the operator
holds `membership.fee.collect`, the collect affordance renders beside it. `feesPaid()` (the resolver) and
`outstandingMembership()`/`owedCents()` (the trait) share one definition, so the affordance appears
exactly when the door/counter flags the fee — and on success the verdict re-resolves and the flag clears
itself, unblocking entry/dispensation without leaving the screen.

**Nav coordination.** The Socios tab is the fifth counter destination; this makes the portrait-tablet nav
overlap (my prompt 116 `md:inline`) worse — prompt 130 owns the fix and now has the full 5-destination
list to fit.

Tests (`MembershipCounterTest` 6; `CheckInScreenTest` +2; `DispensaryPosUnitTest` +1): a cash fee from the
tab records the payment and moves the drawer by exactly the amount; a cash fee with no till is refused; a
wallet fee needs no till; a partial reports what remains; collecting clears the door `unpaid_fee` verdict;
the door and the POS card each collect an outstanding fee inline (blank → full) and the door's inline cash
fee with no till is refused; a wrong-permission user is 403'd from the tab. `composer check` green (912
tests, 909 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated (7 new keys).

---

## Prompt 129 — Dashboard stat-card labels must survive over the delta chip

**Problem.** Prompt 101 stopped the stat cards clipping by making `.csc-card-label` a single truncating line
(`flex:1; white-space:nowrap; text-overflow:ellipsis`). That fixed the overflow but over-corrected: on a narrow
card the label lost its fight with the delta chip beside it and ellipsised to a stub. The three cards that carry
a delta — **Aportaciones, Dispensado, Transacciones** — were left with no readable label at the 2-up and 4-up
breakpoints.

**Fix (option A — wrap the header to two lines).** `.csc-card-label` now clamps to TWO lines
(`display:-webkit-box; -webkit-line-clamp:2; overflow:hidden; overflow-wrap:anywhere`) instead of one, and the
delta chip is `flex:none` so it keeps its size and the label wraps beside/under it rather than being squeezed.
The header aligns to `flex-start` so the chip pins to the top of a two-line label. This KEEPS prompt 101's
no-overflow win — a label longer than two lines still ellipsises, and it never pushes the chip out or clips the
card. The grid is `display:grid` with the default `align-items:stretch`, so a two-line-label card and a
one-line-label card in the same row stay equal height (no new unevenness).

**Verification gap (owed — no browser here).** The full proof is a screenshot at **768 / 800 / 1024 / 1280**,
light and dark, confirming each of the six metrics shows a readable title. What is asserted without a browser:
the three delta-bearing labels render in full in the DOM, AND a stylesheet regression guard that
`.csc-card-label` uses `-webkit-line-clamp: 2` and NO LONGER uses `white-space: nowrap` (so 101's truncation
cannot silently return). This is the same defect class as prompts 101/116 — structural test now, pixel proof
owed.

Tests (`DashboardScreenTest::test_delta_bearing_stat_cards_keep_a_readable_label`). `composer check` green.

---

## Prompt 130 — The counter nav overlapped itself on a portrait tablet

**Problem.** Prompt 116 changed the counter screen-switcher labels from `lg:inline` to `md:inline` so labels
would appear sooner. But prompt 127 then added a fifth destination (the Socios tab), and five icon+label items
at the `md` breakpoint (768px) do not fit beside the brand/sede block and the right-hand actions on a portrait
tablet — the three flex regions collided. (116's own code comment still said "labels from lg up", so the `md`
change had already drifted from its stated intent.)

**Fix — a scrollable strip that fits before it is labelled.** The `<nav>` is now `flex-1 min-w-0
overflow-x-auto`: it takes only the space between the brand/sede block and the right actions and scrolls
horizontally within it, so it can NEVER overlap either neighbour. The brand/title block is `min-w-0` and
truncates (kept rendered at every width so the counter's single `<h1>` is never dropped from the a11y tree);
the right-hand actions are `shrink-0`. Labels are shown UNIFORMLY only from `lg` up (reverting 116's `md`),
where a labelled five-item strip actually fits; below that every item is an equal **44px** (`h-11`) icon-only
target, and the strip scrolls if it still runs out. So labelling is all-or-nothing and only when it fits — never
a half-labelled or overflowing row.

**Dispensary filter chips.** The Categoría / Tipo / Variedad chips were `px-3 py-1` (~28px) — below the 44px
touch target the rest of the counter now uses (prompt 116). Raised to `min-h-11` (44px) with `inline-flex
items-center px-4`; they still wrap (`flex-wrap`), so more chips never overlap.

**Verification gap (owed — no browser here).** The full proof is a bounding-box screenshot at **768 / 800 /
1024 / 1280**, light and dark, confirming no two interactive elements overlap and the labels appear only where
they fit. What is asserted without a browser (`CounterScreenSwitcherTest`, +2): the rendered nav is a
`flex-1` + `overflow-x-auto` strip carrying all five destinations, its items are `h-11`, labels are `hidden
lg:inline` and `hidden md:inline` is gone, and the dispensary chips are `min-h-11` with the old sub-44px chip
class removed. Same defect class as 101/116/129 — structural guard now, pixel proof owed.

`composer check` green (915 tests, 912 passed, 3 pre-existing skips, PHPStan 0).
## Prompt 131 — The member import carries the paper register's two most important facts

**Problem.** `ImportMembers` stamped `now()` as everyone's *alta* and allocated fresh member numbers, so it
discarded the two facts a paper *libro de socios* exists to record — the real join date and the member's own
number — and the statutory register the system then printed contradicted the book. It also created no
membership (so every imported member was unservable — *"Sin membresía activa en esta sede"*) and no consent
record (so the club had no lawful-basis evidence for exactly the members it holds the longest history on).

**Extended column contract (every new column OPTIONAL; today's behaviour is the fallback).** The header may now
carry `member_no, joined_at, left_at, status, location, tier, membership_start, consent_date,
consent_text_version` alongside the originals. A CSV of only the original columns imports exactly as before
(tested): number generated, `joined_at = now()`, no membership, consent-pending. Locations and tiers resolve by
name (case-insensitive) within the org; an unknown name, an unknown status, an invalid date, or half a
location/tier pair is a per-row error the **preview** surfaces.

**Member-number sequence.** `MemberNumber::next()` stays the ONLY allocator. Added `MemberNumber::advanceAtLeast()`
(same row lock, monotonic — only ever raises) and `MemberNumber::parseSequence()` (trailing digits → int). The
atomic import first fast-forwards the org counter past the highest imported number, THEN allocates `next()` for
any blank-`member_no` rows — so a generated number can never collide with one the same import placed, and the
sequence is never left below an imported number (tested: counter ends ≥ highest imported; the next enrolment
gets a free number). A clash — the same number twice in the file, or one already held — is a **preview
validation error**, never a runtime unique-index failure mid-import (tested).

**Consent is never fabricated.** A row is only given a `ConsentRecord` when the CSV carries BOTH `consent_date`
and `consent_text_version` — the version the member actually signed on paper, its real date (`RecordMemberConsent`
gained an optional `$grantedAt`; it stays the single consent writer). It is **never** defaulted to the current
digital version — recording agreement to a text the member never saw is worse than recording nothing. A row
with no consent imports and is left **consent-pending**: a new derived `Member::hasConsent()` drives a
toggleable "Consentimiento → Pendiente" badge on the members table so the club can see exactly who to chase.
Half a consent pair (a date without a version, or vice-versa) is a row error, not a fabricated half.

**Servability.** The import now enrols a membership (via `EnrolMembership`, which gained an optional `status`)
when the row carries a location+tier, so an imported member is dispensable immediately — the end-to-end test
commits a real dispensation with no carencia block and no "sin membresía" refusal. Carencia stays served for
imported members (`carencia_ends_at = now()->subDay()`), unchanged.

**Stock ceiling — the number that stops manufactured headroom.** Since prompt 110 the on-site ceiling derives
from ACTIVE members holding an ACTIVE membership at that sede. So the membership status mirrors the member
status (ACTIVE member → ACTIVE membership; anything else → LAPSED), and the **preview reports the resulting
active membership per sede and the ceiling it implies** (`StockCeiling::forLocation` reused, so the arithmetic
is never forked), writing nothing. Importing members inactive does not raise the ceiling; importing them active
raises it by exactly `added × daily_limit_cg × ceiling_days` (both tested). The Filament import is now a genuine
**preview-then-confirm**: step 1 stages the file and runs `preview()`; the confirm modal shows the per-sede
ceiling consequence before step 2 writes.

**Atomicity (not resumability).** `import()` writes the whole run inside ONE transaction — a partial failure
rolls back rather than leaving half a club imported. Idempotency on re-run is preserved (the duplicate guard
skips anyone already present), and the run stays audited (`members.imported`).

**Sample.** `database/samples/members-import-sample.csv` + its generated `…-preview.txt` (4 to create, 3 errors
— an under-age row and an in-file `M-00020` clash on both rows — 3 consent-pending, ceiling 0 → 35.00 g).

Tests (`MemberImportPaperRegisterTest`, 9; existing import tests unchanged and green). `composer check` green
(921 tests, 918 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated (11 new keys). Pushed for review —
**not merged** (the prompt's explicit instruction; a whole-club data migration warrants a human look first).

---

## Prompt 120 — Counter idle lock + PIN-throttle hardening

Scope decision (owner, via AskUserQuestion): **counter only, PIN re-entry** — the idle lock covers the five
counter screens; the Filament admin panel keeps its normal session timeout (a separate concern).

**Idle lock reuses the operator gate (the key design choice).** Locking the counter simply signs the operator
OUT (`CounterOperator::clear()` in `IdentifiesOperator::lockCounter`, a `#[On('counter-lock')]` listener). That
reuses the gate that already fronts every commit: `requireOperator()` now finds no operator, so a
dispensation, order, fee, check-in, cash movement — and now a **void** (added `requireOperator()` to
`DispensaryPos::voidLast`/`BarPos::voidLast`, previously unguarded) — is refused SERVER-SIDE, not merely hidden
behind the overlay. No new lock table or flag; no change to `requireOperator()` itself. Unlocking is the SAME
PIN pad + throttle as identifying an operator, so on success the trait dispatches `counter-unlocked` and the
overlay lifts with the basket and session untouched (a client overlay never clears component state).

**The overlay + timer (client).** The shared `counter` Alpine store (counter layout) gained `locked` + an idle
timer that resets ONLY on real input (`pointerdown`/`keydown`/`touchstart`, capture phase) — Livewire polling
and re-renders never reset it. After `counter_idle_lock_minutes` it flips `locked` and dispatches
`counter-lock`. A shared `partials/lock-overlay.blade.php` (included by every screen, like the operator strip)
paints an OPAQUE full-viewport surface so an unattended tablet stops showing member data, with its own PIN pad.
A 44px "lock now" button in the top bar locks on demand. The window is the per-location
`counter_idle_lock_minutes` setting (default 5, **0 disables**), edited on `LocationForm` via a new
`SETTING_INTEGERS` reconciliation (parallel to the boolean `SETTING_TOGGLES`).

**PIN throttle hardening.** The bucket is now **location-wide** (`counter-pin:{locationId}` — dropped the
`:sessionId`, since a shared counter has many devices and a browser session is trivial to rotate), and the
lockout **escalates**: `UnlockOperator` was reworked off `RateLimiter` onto cache keys with an escalating
window schedule `[60, 300, 900, 3600]` — each successive lockout at a sede is longer, a correct PIN clears
everything, and strikes decay after an hour of calm. **Graceful degradation (prompt 124):** the overlay's
lockout check runs on every counter render, so every cache touch is wrapped to swallow a backend outage and
fail the throttle OPEN (a Redis blip must never 503 the counter; a correct PIN is still required, so access
stays gated). `RedisDegradationTest` proves the counter still renders when the default cache explodes.

**Verification gap (owed — no browser here).** Fully tested server-side (`CounterPinTest` +2 escalation/clear,
`OperatorUnlockTest` +3 idle-lock: lock signs the operator out and blocks a commit while the basket survives,
unlock dispatches the lift event, every screen carries the overlay). NOT verifiable without a browser: the
idle timer firing, the overlay actually obscuring the screen, "only real input resets", the lock-now button,
and the countdown. Given prompt 130 showed an unverified front-end fix can ship incomplete, this branch is
**pushed, not merged** — the client overlay wants a real browser pass first.

`composer check` green (920 tests, 917 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated (6 keys).

---

## Prompt 132 — The counter nav overlap remainder (worst at 1024, the width a tablet runs on)

**Problem.** Prompt 130 widened the primary group (four → five destinations, labels from lg) but left the
secondary group — Ayuda (36 px), Panel (87 px), Cerrar sesión (114 px) — as a ~240 px fixed block at
x-positions the widened row now ran into. A real browser measured a **70 px `Caja`∩`Panel` collision at
1024×768** (the till button over the leave-the-counter button), plus smaller ones at 768/800; 1280 was clean.
130's structural test passed while this was live — the defect was purely positional, so only a bounding-box
measurement catches it.

**Fix — one flow, secondary behind a single overflow control.** Help, Panel and Log out (the three bar items
that are NOT a counter destination) are collapsed into ONE 44 px overflow (`⋯`) dropdown; the help content is
folded in and the standalone `x-counter.help` component retired. The trailing fixed width drops from ~240 px to
one 44 px control, so the header is now brand/title (shrinks + truncates) · sede chip (44 px, shrink-0) · nav
(the ONLY flex-1, min-w-0, scrollable element) · overflow (44 px, shrink-0). There is no wide fixed group left
for the nav to intersect, at any width — the property, not a pixel budget. Every control is ≥44 px, including
the sede chip (was 164×32) and every item inside the overflow menu. Uniform labelling (lg gate) and 130's other
wins are untouched.

**Verified in a real browser (the thing 130 lacked).** `tests/Browser/measure-topbar.mjs` (Playwright/chromium)
measured the real, authed top-bar at **768 / 800 / 1024 / 1280**: **ALL PASS** — 7 controls (five destinations +
sede chip + overflow trigger), **zero overlaps, none under 44×44, no horizontal page scroll**. Screenshots at
1024×768 (light + dark) and 768×1024 confirm the single clean flow. Playwright is deliberately NOT a CI
dependency (~100 MB browser); `tests/Browser/TopbarHarnessTest` runs in `composer check` as the STRUCTURAL guard
(one overflow control; Help/Panel/Log out folded in; five destinations reachable; 44 px trigger; lg-gated
labels) and writes the harness the `.mjs` measures — see `tests/Browser/README.md`.

**Coordination note.** Prompt 120 (idle lock, branch `feat/counter-idle-lock`, unmerged) adds a 44 px "lock now"
button to this same trailing group. On merge, keep it as a 44 px control beside the overflow (or as a top item
inside it); re-run `measure-topbar.mjs` after reconciling, since it changes the trailing width.

`composer check` green (915 tests, 912 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated (1 key).
Pushed; **not merged** (the prompt's instruction; a human should eyeball the four screens).

---

## Prompt 121 — Panic lockdown (org-wide), reactivation and the runbook

Built on prompt 120's lock surface, greenfield otherwise. The mechanism is the smaller half; the ways back and
the runbook are the point.

**State — append-only, not a boolean.** `organisation_lockdowns` is one row per event, closed by
`reactivated_at`; the org is locked iff `OrganisationLockdown::active()` returns a row (the Action enforces one
open per org). Keeping the history is what evidences "locked at HH:MM by whom, reactivated by which path".

**The gate is one globally-appended middleware** (`EnforceOrgLockdown`, beside `SecurityHeaders`), so it reaches
the panel, the counter and the member PWA at once; org resolved via `ActiveScope::organisationId()`. It
**degrades OPEN** on any DB error (the app can't serve anything without a DB anyway — fail-closed would take the
app down on a blip; same philosophy as prompt 124).

**Ordinary-looking, deliberately.** A locked org gets a mundane 503 "temporarily unavailable" page — never a
"SITE LOCKED" banner. Announcing the lockdown to whoever is in the room, while they are still in the room with
staff, is the dangerous outcome; a glitch is the safer one.

**Audit BEFORE the lock** (`InitiateLockdown`): the `org.lockdown.initiated` audit lands before the row is
created, so who/when survives even if the rest fails. Owners are then emailed. Idempotent — a second press
re-opens nothing.

**Signed-doc URLs invalidated for free.** The gate runs before the `signed` middleware, so every sensitive-doc
endpoint (ID scans, photos, signatures) is dead while locked — outstanding short-lived URLs cannot be replayed,
and the 300s TTL means any captured before the lock has expired by reactivation. No per-org signing secret was
needed (tested: `/members/photo/{id}` → 503 while locked).

**Three ways back, at different trust levels (the prompt's core ask):**
- **Owner link** — a single-use token emailed to each owner (hash-only stored), consumed at `/reactivar/{token}`
  under a row lock. Reactivate from the owner's OWN inbox, off the (coerced) terminal. Not reversible at the
  counter.
- **Auto-delay** — `lockdown:auto-reactivate` (scheduled every 5 min) reopens any org locked longer than
  `lockdown_auto_reactivate_minutes` (**OVERNIGHT-DEFAULT — CONFIRM: 24h**), so a locked-out club — the data
  controller — always regains its own statutory register without depending on us.
- **Break-glass** — `php artisan lockdown:reactivate {org}`, which needs server access (highest trust).

**Drill mode.** A drill trips the identical machinery (staff/members see the real ordinary screen) but the gate
lets an authenticated owner through to observe and end it in-app (`drill_ended`); the email says "[Simulacro]".

**Trigger + permissions (OVERNIGHT-DEFAULT — CONFIRM):** `lockdown.initiate` is granted to **STAFF** — they are
the ones in a robbery — via a discreet, confirm-first item in the counter overflow menu, plus a Filament
`Seguridad` page for managers (initiate/drill/history). `lockdown.manage` (MANAGER/OWNER) gates the page,
drills and the runbook. A REAL lockdown has **no in-app reactivation permission by design** — off-premises only.

**Runbook** lives in the Manual (`Help::GUIDES['lockdown-runbook']`, gated on `lockdown.manage`) and a `Seguridad`
page topic — what to do, and the three ways back, in the club's own words.

**Verification gap (owed — no browser here):** the counter panic button, the Filament Seguridad page and the
ordinary 503 screen are unverified visually; the *mechanism* is fully tested server-side (`PanicLockdownTest`,
11: audit-before-lock + idempotent; gate blocks counter/PWA with an ordinary screen; owner-link single-use;
auto-delay after the window and not before; break-glass; drill blocks staff but passes an owner; doc URL refused
while locked; staff can trip, a no-role user is 403'd). Owed browser assertions: (a) the counter overflow shows
"Bloqueo de seguridad" only for `lockdown.initiate` holders and confirms before posting; (b) the Seguridad page
renders the panic/drill actions and history; (c) the 503 page reads as a mundane outage in light and dark.

`composer check` green (940 tests, 937 passed, 3 pre-existing skips, PHPStan 0). EN/ES parity gated (50 keys).
Pushed; **do not merge**.
## Prompt 134 — Legal stock headroom on the dashboard

Presentation only — `StockCeiling::forLocation()` already computes it and prompt 110 already enforces it at
intake; this surfaces the number, unchanged.

**Where.** A "Techo legal de existencias" section on the dashboard, in the non-staff block beside stock levels,
**per sede** (the ceiling is per premises — a combined figure would be meaningless). Scope respected: it walks
`ceilingHeadroom()` over the actor's `scopeLocations()`, so a manager sees their sede and an owner sees all.
`ViewModels\Dashboard::ceilingHeadroom()` is the one new method; weight stays centigrams, grams only at the edge.

**Plain language + the three inputs.** Each sede reads *"Puedes dar de alta 340 g más antes de superar el
límite."*, then the arithmetic that makes it auditable and actionable — *":n socios activos × 3,5 g × 5 días =
techo :ceiling"* and *"En sede ahora: :g"* — so an owner sees both why it moved and what would change it.

**Over-limit shows the magnitude,** not just a state: *"Supera el techo en :g."* in red, with headroom clamped
to 0. (An alarm with no magnitude is ignored within a week.)

**One number.** `ceilingHeadroom()` reads the exact `StockCeiling::forLocation()` result the intake guard uses —
tested that headroom = ceiling − on-site and every input matches StockCeiling, that two sedes differ, that an
over sede shows the overage, and that adding one active member raises only that sede's headroom by
`daily_limit_cg × ceiling_days`.

**Verification gap (owed — no browser here):** stat cards were not touched, so prompts 129 (labels readable at
768/800/1024/1280) and 101 (no overflow) still hold via `DashboardScreenTest`'s stylesheet guard; the new
section is plain flow below them. Owed screenshots: the dashboard for a sede with comfortable headroom and one
over the limit, light and dark. A trend sparkline was considered and deferred (OVERNIGHT-DEFAULT — CONFIRM:
left out of v1; the per-sede figure + inputs is the actionable core, and a sparkline needs a stored series the
live-only dashboard does not keep).

Tests (`CeilingHeadroomTest`, 4). `composer check` green (933 tests, 930 passed, 3 pre-existing skips, PHPStan 0).
## Prompt 133 — One- or two-tap dispensations (weight presets + "their usual")

**Weight presets.** A row of one-tap gram amounts in the dispensary POS weight panel, **default `[1, 2, 3.5, 5]`**,
per-location via a new `LocationForm::SETTING_ARRAYS` reconciliation (`pos_weight_presets_g`, stored as a JSON
Setting row alongside the boolean toggles and prompt-120's integers) — so two sedes can differ (tested). Each
button shows its **resulting price**, and 3.5 g (= `ResolvePrice::EIGHTH_CG`) shows the **eighth break** — the
price is computed through the exact `ResolvePrice::applyEighthBreaks` path the basket uses, so the button shows
what the line will actually be charged (tested: the 3.5 g button shows the €30 eighth, not 3.5 × rate).

**Presets are an input, never a fast path.** `applyWeightPreset()` only FILLS the same `weightInput` a typed
amount would, so eligibility, carencia and the daily/monthly limits are enforced identically at `addLine`
(tested: tapping 3,5 g == typing 3,50). A preset over the member's remaining allowance is shown **unavailable**
(disabled), not refused after the tap (tested at a 2 g daily cap: 1 g/2 g available, 3.5 g/5 g disabled).

**"Their usual."** On identification, the member's most recent DISTINCT genetics, filtered to what is sellable
at this sede right now via the SAME `Genetic::sellableAt()` scope the POS grid and `PwaController::menu()` share
(prompt 95 — so the three cannot drift): an unpriced/out-of-stock/soft-deleted strain never appears (tested).
**One query on identification** (the recent-genetic ids), then an in-memory intersect — never a query per
suggestion (tested: the query count does not scale from one usual to three). A member with no history sees
nothing and the screen is unbroken (tested).

`CommitDispensation` untouched — this is entry, not committing. The typed path is unchanged; presets are an
addition. All new controls are 44px.

**Verification gap (owed — no browser here):** the required 1024×768 assertion (a genetic still above the fold
with the preset row + suggestions visible, light and dark) is a screenshot I cannot run. The preset row sits
inside the weight panel and "their usual" is a single wrap row above the grid filters, both below existing
content — the layout budget from prompts 116/130 is not spent on a new persistent row (presets show only when a
genetic is active). Owed: the 1024×768 screenshot confirming a genetic card is still visible without scrolling.

Tests (`PosQuickEntryTest`, 6). `composer check` green (935 tests, 932 passed, 3 pre-existing skips, PHPStan 0).
EN/ES parity gated. Pushed; **do not merge**.
## Prompt 128 — ID/MRZ prefill: the measurement gate (read rate reported BEFORE the build)

**The gate, honoured.** The prompt requires measuring the MRZ read rate on REAL photos and reporting the number
BEFORE building the prefill, because a feature that reads "four times in ten" is worse than none. So the
prefill UI/storage is **deliberately NOT built**. What ships is the measurement harness the gate demands, and
the deterministic parser it needs.

**Measured read rate: UNMEASURED in this environment — and that is the honest answer, not a skipped step.**
Measuring it here is impossible for three reasons, each of which is itself a finding:
1. **No corpus.** There is no set of real ID/NIE photos in the repo, and I will not fabricate or handle real
   Article-9 ID scans to create one — that is a club-side, on-real-hardware task.
2. **No local OCR.** `tesseract` is not installed and there is no MRZ/OCR package; the harness needs one.
3. **Cloud OCR is forbidden** (the prompt is explicit): it would add a processor, an international transfer and
   a RAT entry for the most sensitive data the club holds — not a decision to make to save a fortnight.

**What is delivered (the "first, measure" tooling, tested):**
- `App\Support\Mrz\MrzParser` — a pure, offline TD1 (DNI 3.0 / NIE / residence) + TD3 (passport) MRZ parser that
  validates every ICAO 9303 check digit and is **correct-or-invalid**: a broken check digit is flagged
  `valid=false` so a mis-read can never silently prefill a wrong document number. Proven on the canonical ICAO
  worked examples (`MrzParserTest`, 5) — no photos or OCR needed for that proof.
- `php artisan id:mrz-read-rate {dir}` — points at a folder of real photos, runs local `tesseract`, isolates the
  MRZ lines, parses them, and prints `read/total (rate%)`. It refuses to run without `tesseract` and never
  reaches for a cloud API. This is the "one day of measuring" tool, ready to run when a club provides a corpus
  on real hardware.

**The decision rule (OVERNIGHT-DEFAULT — CONFIRM):** run `id:mrz-read-rate` on a real corpus. If the valid-read
rate is **≥ ~90%**, build the prefill (parse → populate `document_number`/DOB/names on the member form, operator
confirms before save, nothing stored from an invalid read). If it is poor, the answer is **capture UX** — a
framing guide and autocapture-when-sharp — NOT a better parser and NOT a cloud API. Prefill was left unbuilt
precisely because that number does not yet exist.

Tests (`MrzParserTest`, 5). `composer check` green (934 tests, 931 passed, 3 pre-existing skips, PHPStan 0).
Pushed; **do not merge**.
## Prompt 135 — When someone is refused, say why and offer the fix

Pure presentation over the verdicts `ResolveMemberEligibility` / `ResolveMemberLimits` already return — both
unchanged. A new `App\Support\VerdictRemedy::describe()` turns each fired rule into `{detail, remedy}`: the rule
named in the member's own terms, plus the fix where one exists or a plain "who/when" where nothing can be done
at the counter. Both the door (`CheckInScreen`) and the dispensary POS render it in their existing verdict loop,
so the two tell the same story about the same member.

**The rule-to-remedy map:**
| Rule | Named as | Remedy shown |
|---|---|---|
| unpaid_fee | "Cuota de socio pendiente: 25,00 €" | the inline collect-fee action (already there, prompt 127) |
| debt | "Deuda de 12,50 € por encima del límite del monedero" | "Debe saldar el monedero para poder continuar" |
| carencia | "En carencia hasta el 14/08/2026" | none — but the DATE is named |
| sanction | (generic) | "No se resuelve en el mostrador: consulta con un responsable" |
| aforo | "Aforo completo (3/10)" | none — the occupancy is named |
| membership | "Sin membresía activa en esta sede" | "Renueva su cuota desde su ficha…" |
| age | (generic) | none |

**Kept intact:** the enforcement matrix (WARN reads as a warning that can be proceeded past, BLOCK as a stop —
the row's error/warning styling and the `Bloquea`/`Aviso` chip are unchanged, and the commit boundary still
honours the mode); an override still needs its permission, reason and audit entry (untouched); no rule becomes
easier to pass. No sensitive detail beyond what the operator needs — a sanction's existence and "ask a manager",
never its conduct notes.

**Verification gap (owed — no browser here):** the door + POS both render `VerdictRemedy` in the same loop
(asserted structurally by the passing counter tests); owed is the screenshot of the door and the POS for a
member failing two rules at once, and the same member after the fee is collected, light and dark. The
WARN-proceeds / BLOCK-stops behaviour at the action boundary is the existing enforcement (prompt 34), unchanged
here.

Tests (`VerdictRemediesTest`, 6). `composer check` green (935 tests, 932 passed, 3 pre-existing skips, PHPStan
0). EN/ES parity gated. Pushed; **do not merge**.
## Prompt 146 — Email is the username, and its case is only handled by accident

Email is the login identifier for staff and members. Nothing normalised it on write and almost nothing on
lookup; production only worked because MySQL's `utf8mb4_unicode_ci` collation is case-insensitive, while
SQLite (and the whole test suite) compares `=` case-sensitively — so behaviour differed by driver and no test
could catch a regression. `DB_COLLATION` is one env var from breaking it. Correctness now belongs to the
application, not a collation nobody asserted.

**Normalise on write, at the model boundary.** `App\Support\Email::normalise()` (lowercase + trim; empty →
null) behind a `App\Casts\NormalisedEmail` cast on `User::email`, `Member::email` and
`MemberApplication::applicant_email`. Every write path inherits it — Filament forms, `csc:install`,
`ImportMembers` (goes through `Member::create`), the application-approval `new Member`, and the invite — so a
normalisation applied at six call sites is not missed at the seventh.

**Normalise on lookup too, driver-independently:**
- Staff login: a custom `App\Filament\Pages\Auth\Login` normalises the submitted email in
  `getCredentialsFromFormData()` before `retrieveByCredentials` (Filament's stock page passed it through raw).
- Staff password reset: matching custom `RequestPasswordReset` (the broker also keys the token by email).
- Member login-link: `IssueMemberLoginLink` already compared `LOWER(email)` against a lowercased needle — the
  one place someone had done it right; kept.
- Duplicate detection: `FindDuplicateMembers` now matches `LOWER(email) = ?` against the normalised needle, so
  a case-only difference is caught on any driver (it did an exact `orWhere` before → missed on SQLite).
- `csc:install`: normalises `owner_email` BEFORE the `unique:users,email` rule, so the duplicate-owner refusal
  is reliable (the rule queries the raw value, ahead of the cast).
- Filament User/Member email inputs normalise on blur, so the pre-save `unique()` check and the stored value
  agree regardless of driver.

**RFC 5321** makes the local part technically case-sensitive, but no mainstream provider treats it that way
and every practical system stores email lowercase — that is the choice here, recorded so it is not reopened.
Only the email is normalised; names, document numbers and everything else keep their case exactly.

**`members.email` did NOT become unique — a decision, not a default.** Two members legitimately sharing one
address is plausible (a couple, or a member whose email is their carer's); a unique index would refuse that
outright and a club would discover it at the counter. Integrity is instead held by reliable duplicate
DETECTION (now normalised), which surfaces the clash rather than refusing it. `users.email` stays unique — one
staff login per address — and the backfill protects that index (below).

**Backfill (`2026_08_13_000000_normalise_existing_emails`).** Lowercases existing `users` and `members` rows.
Because `users.email` is unique, lowercasing could turn two rows into a collision, so it DETECTS any case-only
pair FIRST — in PHP, so the behaviour is identical on SQLite and MySQL regardless of collation — and aborts
with a clear report of exactly which addresses clash, rather than throwing a constraint violation halfway
through and leaving the table half-converted. `members.email` is not unique, so it needs no such guard.
**Deliberately ONE-WAY:** the original casing is not recoverable, so `down()` cannot restore it — every other
migration in this project is reversible; this is the stated exception.

**Unchanged:** what login *does* (rate limits, throttles, MFA, the login-link flow) — only how the identifier
is matched.

Tests (`EmailNormalisationTest`, 9): stored-lowercase, login in all three cases (end-to-end via the Login
page), CSV import lowercased, duplicate detection on a case-only difference, login-link in any case, invite
normalised, backfill converts, backfill reports a collision instead of throwing, install refuses a case-only
duplicate owner. **They pass on SQLite AND the MySQL parity job** — the half that matters, since that job
exists for exactly this driver-difference class of bug. The one exception is the pre-existing-collision test,
which is SQLite-only by nature: a `users.email` case-collision cannot exist under a case-insensitive collation
(MySQL refuses it at insert), so the scenario is only constructible on SQLite (skipped on MySQL with that
reason). `composer check` green (PHPStan 0). Pushed; **do not merge** — but see the merge note: the owner asked
for 146 to land on main ahead of the register import.
## Prompt 136 — Give a member a way to reach the club, and make it evidence

A member↔club threaded messaging channel, built so the conversation is EVIDENCE (kept, attributable,
erasable) and can escalate into a formal RGPD request. It is deliberately NOT an ordering channel.

**Data model — two tables.** `message_threads` (belongs to a member; subject, status OPEN|CLOSED,
last_message_at, closed_by, `data_request_id` evidence link) + `messages` (hang off the thread; an `author`
discriminator MEMBER|STAFF, `user_id` for the staff author, free-text `body`, `read_at`). Messages carry NO
member_id — member/org scope derives from the thread. There is no product/quantity column anywhere: a message
is free text, so this can never become a back-door sales channel.

**Member side (PWA).** `MessageController` is scoped entirely to the authenticated socio on the `member`
guard; a thread is resolved by its ULID AND a `member_id` ownership check (`findOrFail` → 404), so there is no
id in any URL and one member can never reach another's thread (denial tests: other member → 404, other org →
404). New "Mensajes" tab (the bottom nav went `grid-cols-4` → `grid-cols-5`). A member writing again REOPENS a
closed thread — the club considered it done, the member did not.

**Club side (admin).** `MessageThreadResource` in "Comunicaciones", read + act, never raw-edit: staff REPLY,
CLOSE or CONVERT, each through a domain Action re-checking `comms.manage` (OWNER + MANAGER; STAFF excluded).
The nav badge is a LIVE count of threads with an unread member message (never cached). A reply marks the
member's outstanding messages read, appends a STAFF message, optionally closes, and pushes the member.

**DataRequest bridge.** `ConvertThreadToDataRequest` turns "please delete my data" in words into a logged
row in the subject-rights register (`data_request_id` linked, thread closed). It only RECORDS the request;
FULFILLING it stays the owner-gated DataRequest flow (`data.request.handle` / `data.erase`), untouched.
**OVERNIGHT-DEFAULT — CONFIRM:** conversion is gated on `comms.manage` (a manager can log a member's request)
rather than owner-only. Rationale: logging an obligation is less sensitive than discharging it. Confirm.

**Push.** New `new_message` channel added to `Member::PUSH_CHANNELS`; `ReplyToThread` notifies the member
(best-effort — the existing per-channel opt-out + VAPID gate apply). `MemberPushTest`'s every-channel
completeness assertion is updated so the channel can't ship without a render case.

**Retention.** `message_retention_days` (default 730 = 2 years) on the settings form; `messages:prune-retention`
REDACTS message bodies past retention while keeping the thread, authorship and timestamps as evidence of
contact — the same redact-not-delete discipline and per-job heartbeat + *Salud del sistema* row as the
audit-retention sweep (prompt 112). Idempotent. **OVERNIGHT-DEFAULT — CONFIRM:** the 730-day default, and that
the sweep redacts message BODIES only — the short subject label is left until the member's own erasure, exactly
as the audit sweep keeps a row's identity while redacting its payload.

**RGPD erasure.** `message_threads` added to `AnonymiseMember::COVERED_MEMBER_TABLES`; erasure redacts the
member-authored subject to `[borrado]` and scrubs the member's OWN message bodies, while STAFF replies (the
club's record) and the thread skeleton survive as evidence. `messages` holds no member_id so the schema-driven
guard does not flag it — it is handled explicitly and asserted (a staff reply is kept, a member body is gone).

**Verification gap (owed — no browser here):** the PWA messages list + thread view (chat bubbles, compose /
reply, the 5-tab nav) and the admin thread view + reply/convert modals are screenshot-owed across
1440/1280/1024/390 + a short height, light AND dark. All LOGIC is proven headlessly: `MemberMessagesTest` (7,
ownership + denial + unauth redirect) and `MessagingTest` (9, reply/close/convert/reopen + RGPD scrub +
retention sweep + admin access).

Tests (`MemberMessagesTest` 7, `MessagingTest` 9, `MemberPushTest` +1 channel). `composer check` green
(PHPStan 0). EN/ES parity gated (35 new keys, both files). Pushed; **do not merge**.
## Prompt 137 — The assembly, end to end

Convening already existed (`IssueConvocatoria` freezes the roll + notifies; `CreateMinute`/`SignMinute` draft
and file an acta). What was missing was the MEETING between them: recording who attended, watching quorum,
recording each agenda item's outcome, and drafting the acta FROM that instead of retyping it into the acta
form. This branch adds exactly that middle, reusing the existing writers unchanged.

**Two new tables as the live working state, snapshotted into the acta.** `assembly_attendances`
(convocatoria_id, member_id, name snapshot, mode PRESENT|PROXY, proxy_holder, recorded_by; unique per member)
and `assembly_resolutions` (position, title, result APPROVED|REJECTED|DEFERRED, votes_for/against/abstain).
These are the *working* record during the meeting. `DraftAssemblyMinute` SNAPSHOTS them into `CreateMinute`'s
existing JSON columns (`attendees`, `resolutions`) — so the acta stands alone as the immutable record and
`CreateMinute` is **unchanged**. The alternative (make CreateMinute read the tables) was rejected: it would
couple the one acta writer to this feature and break the acta's self-containment.

**Entry point is a custom Filament Page (`Asamblea`), not a relation manager.** There is no writable-relation-
manager precedent that routes through a domain Action, and the page is the natural home for the "end to end"
flow the prompt asks for. It is a THIN shell: every write goes through `RecordAttendance` / `RecordResolution`
/ `DraftAssemblyMinute` (single-writer preserved), and `AsambleaPageTest` proves each button reaches its
Action (guarding against the unreachable-Action defect). Gated on `minutes.manage`.

**Both PRESENT and PROXY count toward quorum** — representation is valid presence in a Spanish asociación.
Roll and quorum use the SAME temporal predicate (`joined_at`/`left_at` as-at) as `IssueConvocatoria` /
`CreateMinute`, so a member who joins or leaves after issue is excluded, matching the frozen roll.

**New setting `assembly_second_call_quorum_bp`** (on the org settings form, entered as %, stored as bp).
Second-call quorum did not exist — only a single first-call fraction + a `second_call_at` timestamp.
`AssemblyQuorum` computes first-call (frozen `quorum_required`) and second-call thresholds live and reports
`isConstituted()`.

**OVERNIGHT-DEFAULT — CONFIRM:**
- `assembly_second_call_quorum_bp` default **0** = the assembly is validly constituted on second call whatever
  the attendance (common Spanish asociación statute practice). Configurable; confirm this is the club's rule.
- **No proxy-per-holder LIMIT** is enforced (e.g. "max 2 proxies per attendee"). Statute-driven and varies;
  deferred as a later configurable cap rather than guessed. Confirm whether a cap is needed.
- **Drafting is NOT hard-blocked on quorum.** The acta records present vs required and states the constitution
  in its body ("quórum alcanzado…/no alcanzado"); whether to hold or adjourn is the club's judgment, not the
  software's. Confirm this is right vs. blocking a sub-quorum acta.

**Immutability / corrections unchanged.** `DraftAssemblyMinute` refuses a second draft while an unsigned one
exists; a signed acta's correction still supersedes via `CreateMinute` directly (`supersedes_id`), untouched.

**Locale-stable acta content.** An acta is a Spanish legal document, so its STORED text must not shift with the
drafting user's UI locale: `ResolutionResult::actaTerm()`, the fixed "Asamblea general ordinaria/extraordinaria"
type, and the body preamble are deliberately not `__()`. The acta's resolutions JSON gained a `resultado` key,
surfaced in `MinuteInfolist` + `documents.minute` (both degrade if absent).

**RGPD.** `assembly_attendances` added to `AnonymiseMember::COVERED_MEMBER_TABLES`; the `name` snapshot is
redacted to `[borrado]` while the row + mode/proxy survive as attendance evidence (mirrors
`convocatoria_recipients`). `assembly_resolutions` holds no member PII (recorded_by = user), so it is not
member-linked. The schema-driven `RgpdCompletenessTest` guard is satisfied.

**AGM pack fed.** `AgmPackReport` gained an "Asambleas del período" table (convocados / asistentes / quórum /
acta nº) drawn straight from what this feature records — evidence, never retyped.

**Verification gap (owed — no browser here):** the `Asamblea` page (roll table with present/proxy/clear
controls, live quorum card, agenda→resolution rows, draft button) is screenshot-owed across 1440/1280/1024/390
+ a short laptop height, light AND dark, motion reduced AND allowed. All LOGIC is proven headlessly:
`AssemblyTest` (13, the Actions + quorum + RGPD) and `AsambleaPageTest` (4, page access + each button reaches
its Action via Livewire method calls). What a browser would add is layout/contrast confirmation, not behaviour.

Tests (`AssemblyTest` 13, `AsambleaPageTest` 4). `composer check` green (PHPStan 0). EN/ES parity gated
(37 new keys, both files). Pushed; **do not merge**.
## Prompt 142 — An abandoned import leaves the club's whole register on disk

`ListMembers::importAction()` copies the uploaded CSV to `storage/app/member-imports/{ulid}.csv` so it
survives from the preview request into the confirm request. `resetImport()` deletes it on commit, on cancel,
and when the stash is missing — but NOT when the operator walks away (closes the tab, navigates elsewhere).
That file is the club's entire paper register in plaintext — names, emails, phones, **dates of birth and
document numbers**. The directory is `0700` and outside the webroot, so this is not an exposure; it is
**undeclared, unbounded retention of personal data**, in a product whose distinguishing claim is that its
retention periods are applied rather than merely configured.

**The fix is a scheduled sweep — the guarantee — exactly as the unique index (not a check-then-insert) is what
makes idempotency hold elsewhere.** `imports:prune-staging` (hourly) deletes staged files older than
`import_staging_retention_hours`, and ONLY those: a stash for an import currently mid-flow was just written, so
it is newer than the cutoff and is left untouched (worth an explicit test — it is the one way this fix could
cause harm). Idempotent, safe when the directory is empty or absent, heartbeat stamped so a silently stopped
sweep goes red on *Salud del sistema* ("Barrido de importaciones"), following prompt 112's pattern.

**Window = 4 hours.** This is scratch space for a multi-step form, not a record: long enough that a slow
operator who leaves the preview open over lunch does not lose their work (and even if a stash is swept
mid-review, `confirmImport` already degrades gracefully to "la previsualización caducó, vuelve a subir" — no
crash, no data loss), short enough that nothing lingers overnight. It is a **Setting**
(`import_staging_retention_hours`) alongside the other retention periods, but a system constant (a TTL for
scratch space), so it is read via `Settings::get` and excluded from the org settings form (documented in the
settings-coverage test).

**Navigate-away immediate catch — deliberately none added.** There is no reliable server-side hook for a
closed tab or a navigation: the browser sends nothing. `resetImport()` already catches the two cases it can —
commit and explicit cancel — immediately. A `beforeunload` beacon is unreliable and is exactly the "elaborate
lifecycle dance" the brief warns against; the scheduled sweep is what makes the guarantee. So the walk-away
case is closed by the sweep, not by a speculative hook.

**Encryption via DocumentVault — deferred, as a stated decision, not an oversight.** `ImportMembers::preview()`
and `import()` read a plaintext filesystem PATH, so routing the stash through `DocumentVault` (encrypt at rest)
would mean decrypting to a plaintext temp file in BOTH the preview and the confirm request — reintroducing a
plaintext copy at read time regardless — and moving the stash onto the `documents` disk (S3 in production),
complicating a preview→confirm handoff whose behaviour must not change. The directory is already `0700` and
off the webroot, and the sweep now bounds the file's lifetime to hours; the actual defect was retention, which
the sweep closes. If register-in-transit at-rest encryption is later judged necessary, the path is
`DocumentVault::put`/`get` with a decrypt-to-temp shim — but it is not added speculatively against the
behaviour-preservation rule.

**RAT.** RAT-01 (Gestión de socios y membresías) now names the member-import staging directory, its retention
window and its automatic deletion — the half of this branch that is not code, and the half that matters if
anyone ever asks.

**Verification gap (owed — no browser here):** the *Salud del sistema* panel showing the new "Barrido de
importaciones" sweep, light and dark.

Tests (`ImportStagingRetentionTest`, 8): a stale file is swept; an in-flight file is left; idempotent; safe
when the directory is absent; heartbeat stamped and health goes stale without it; a successful import and an
explicit cancel each still delete their own stash immediately; the preview carries the ceiling + consent counts
intact. `composer check` green (PHPStan 0). Pushed; **do not merge**.

## Prompt 143 — The admin topbar's top-right read as one word ("DGESEN")

**Symptom.** Avatar initials, the language toggle and the help icon ran together with no separation:
`DG` + `ES`/`EN` + `?` → "DGESEN". Three defects compounded:

1. **No separation.** The language and help controls were injected via the `TOPBAR_END` render hook, which
   renders as a *sibling of* Filament's `.fi-topbar-end` div — and `.fi-topbar` (the nav) has NO gap, while the
   16px `column-gap` that spaces the topbar lives on `.fi-topbar-end` (which holds the avatar). So the two
   injected controls butted against the avatar and each other with zero space.
2. **The toggle was two loose letters.** Inactive locale = plain grey text, no boundary — so "EN" visually
   merged into the avatar initials beside it.
3. **The active locale was invisible.** It used `bg-primary-600 text-white` — but `.bg-primary-*` is NOT a
   compiled utility in this Filament build (Filament colours its own components from the `--primary-*` CSS
   vars; it does not emit generic `bg-primary` utilities). So the active segment had white text on *no* fill.

**Fix.**
- **Relocate** the language + help hooks from `TOPBAR_END` to `GLOBAL_SEARCH_AFTER`, which renders INSIDE
  `.fi-topbar-end`, before the user menu. All three controls become flex children of that container and inherit
  its 16px `column-gap`. This also returns the account avatar to the **far-right corner** — the web-wide
  convention for a user menu (the "decide the avatar order" call: it was accidentally mid-cluster because the
  old hook fired after the avatar; rightmost is correct).
- **Segmented pill.** The toggle is now a bounded grey track holding the two segments, `role="group"` +
  `aria-label` (Idioma/Language), each segment `aria-pressed`, keeping the ≥24×24 target (`min-h-[1.5rem]
  min-w-[1.75rem]`). The track edge makes it read as one control, clearly separate from the avatar. Group gap
  (16px between controls) ≫ inner gap (segments sit tight in the track).
- **Active fill** = `bg-[var(--primary-600)]` — Filament's panel-primary via its CSS var, so it also honours a
  per-location accent override (prompt 03); white-on-blue passes AA. NOT `bg-primary-600` (a no-op here).

**Discovered.** `bg-primary-600` silently does nothing in Filament-panel Blade — custom panel markup must
consume `--primary-600` (or, on the member PWA, `bg-brand`, which the socio switcher already does correctly).
Only the admin switcher was affected.

**Verified.** `AdminTopbarHarnessTest` renders the REAL authed dashboard topbar and writes it (built CSS
inlined) to `storage/app/admin-topbar-harness.html`; it also guards the structure (language + help now precede
the user menu — the ordering flip — as a labelled, pressed-state group). `node tests/Browser/measure-admin-topbar.mjs`
confirms **16px between every adjacent control, order language → help → avatar, no overlap, none < 24px, at
1280 / 1024 / 800 × light/dark**. The pill screenshot confirms the blue active segment reads (AA) in both
themes. `composer check` green (Pint, PHPStan 0, 967 passed / 3 skipped). **Verification gap:** Playwright is
not a CI dependency (see the README) — the structural guard test + the local measurement stand in for it.

## Prompt 144 — Dashboard stat-card labels broke mid-word ("Contri‑butions")

**Symptom.** At four-up (the desktop content column), the three delta-bearing cards showed a label
snapped mid-word: `Contri‑butions`, `Transac‑ciones`. This is the residue prompt 129 could not reach.

**Why 129's one property wasn't the whole fix.** 129 correctly moved `.csc-card-label` from a single-line
ellipsis to a two-line wrap and changed `overflow-wrap: anywhere` → `break-word` (kept — `anywhere` also
counts a mid-word break-point when computing the element's MIN-CONTENT width, collapsing it to one character,
so the flex algorithm then hands the label the remainder and it shatters). But `break-word` only stops the
label breaking a word *when there is room for the word*. In a four-up row the card was ~154px, and the label
was sharing that row with the icon AND the delta chip — so the space left for the word was smaller than the
word, and even `break-word` had to break it. **Three parts, each proven load-bearing by a Playwright
range-rects harness** (renders a card at exactly the min card width — the worst case under `auto-fit`, since
`1fr` only ever makes a card wider — and flags any single word whose client-rects span two lines; a 44px
negative control confirms the detector fires):

| Part | What | Proven necessary because |
| --- | --- | --- |
| 1 | `overflow-wrap: break-word` on the label (and value), NOT `anywhere` | 129's reasoning; `anywhere` re-collapses min-content and re-shatters the word |
| 2 | Delta chip moves BELOW the label — `.csc-card-headmain` column wraps label + chip, label gets the FULL header width | With the chip beside it, a 13rem (208px) card STILL breaks "Contributions"/"Transacciones"; clean only at ≥232px — which would force two-up at 1280 |
| 3 | `.csc-cards` → `repeat(auto-fit, minmax(13rem, 1fr))` instead of a fixed four-up | With the chip below but the old four-up (~154px cards), the same two labels STILL break; clean from ~176px, floor set at 13rem/208px for margin |

**Column count: four-up → up to three-up in the content column.** `auto-fit` fills as many ≥13rem cards as
the row holds (three where four won't fit, two in the narrower column, one on a phone). This is the trade the
prompt sanctioned ("the row that held four can hold three and wrap, or the cards can be a touch wider").
Chip-below (part 2) was chosen over the alternative — keeping the chip beside and widening the floor to 14.5rem
— precisely because it keeps the denser three-up rather than dropping to two-up.

**129's guarantees still hold (strengthened).** The two-line clamp stays (`-webkit-line-clamp: 2`, ellipsis on
a genuinely over-long third line); the chip can no longer be pushed out (it is below, not beside); the card
still cannot clip (`overflow: hidden`, no horizontal overflow). Giving the label the full width makes its
priority over the chip absolute, not merely "wins the squeeze".

**Verified.** All 8 labels × EN/ES × light/dark render with zero mid-word breaks at and above the 13rem floor
(harness clean from 176px; shipped floor 208px). Screenshots at the min width, light and dark, confirm the
chip reads cleanly beneath the label. `DashboardScreenTest::test_stat_card_labels_never_break_mid_word` pins
all three parts structurally (source-regex guard, since CI has no browser). `composer check` green (Pint,
PHPStan 0, 968 passed / 3 skipped). **Verification gap:** the range-rects harness ran locally; a logged-in
Playwright screenshot of the real dashboard at 1280/1024/800 is owed, as for the other presentation prompts.
Pushed; **do not merge** (human review).
## Prompt 145 — Production mail cannot work: the Resend SDK is absent and the key is read from the wrong variable

Two independent defects made `MAIL_MAILER=resend` unusable, one of them silently.

**Defect 1 — the SDK was only suggested.** `laravel/framework` ships a FIRST-PARTY `resend` transport but only
*suggests* `resend/resend-php`, so it was not in `composer.lock` and the transport could not be constructed.
Added **`resend/resend-php` (^1.7) to `require`** (not `require-dev`). Deliberately NOT the community
`resend/resend-laravel` wrapper: the framework already provides the transport, so the wrapper would add a
service provider + facade this project does not use and a second path to the same transport. The plain SDK is
exactly what Laravel's own transport consumes.

**Defect 2 — the key was read from a variable nothing set (the silent one).** `config/services.php` reads
`RESEND_API_KEY` — Laravel's own convention — which is CODE and therefore correct. But `.env.example` and
`SETUP.md` told operators to set `RESEND_KEY`. Following the docs put a valid key into a variable nothing
reads, leaving a null key: Resend rejects every request, queued mail lands in failed jobs, synchronous paths
throw at the counter, and nothing says the key is empty. **Fixed the DOCS to match the code, not the reverse**
— `RESEND_API_KEY` is canonical and a future developer expects it. Also corrected the `.env.example` + SETUP.md
comment that named the wrong package. `config/services.php` was left untouched.

**Guard so it cannot recur silently.** `SystemHealth::mailer()` reports the configured transport and whether
its required credential is present, surfaced in *Salud del sistema* beside the scheduler / queue / sweep
checks (silence is this system's characteristic failure mode). It is a CONFIGURATION check only — a small map
of credential-needing transports (`resend`→`services.resend.key`, plus `ses`/`postmark`) checked non-empty;
`log`/`array`/`smtp` need no API credential here and never false-alarm. It deliberately does **not** send a
probe email: that would spend real quota and put a synchronous network call inside a health panel. Checking the
selected mailer's credential is non-empty is enough to have caught this.

**Unchanged:** `MAIL_MAILER` stays `log` in `.env.example` (local dev uses the log mailer + `/dev/mail`); no
mailable/notification/send-site touched; the `log` and `array` mailers the whole suite depends on are
unaffected.

**Verification gap (owed — no browser here):** screenshot the health panel in both states — mailer configured
and mailer missing its key — light and dark.

Tests (`MailerHealthTest`, 4 — transport resolves with a key; health flags resend without a key; resend with a
key reports configured; log needs no credential). `composer check` green (PHPStan 0). EN/ES parity gated
(4 new keys). Pushed; **do not merge**.
## Prompt 145 (expanded) — Two production drivers this app documents are not installable

The earlier 145 fixed the Resend half on this same branch (`fix/resend-transport`). This expands it with the S3
half: `DOCUMENTS_DRIVER=s3`, which `.env.example`/`SETUP.md` tell operators to set for production, also had no
installable package — its Flysystem adapter was only a Composer *suggestion* of `laravel/framework`. So the
entire object-storage path for the Article 9 material (member ID scans, medical certificates, photos, POS
signatures) had **never once been exercised**: every test, audit and local run used `DOCUMENTS_DRIVER=local`.

**Continued on the existing unmerged branch** rather than a parallel one (per the prompt).

**Seven-item state (verdict each):**
| # | Item | Verdict |
|---|---|---|
| 1 | `resend/resend-php` in require | **done already** (this branch — `composer.json:20`) |
| 2 | `league/flysystem-aws-s3-v3` in require | **done in this branch** (`composer.json`, `^3.35`) |
| 3 | `.env.example` says `RESEND_API_KEY` | **done already** (`.env.example:82`) |
| 4 | `SETUP.md` `RESEND_API_KEY`, no `resend/resend-laravel` instruction | **done already** (SETUP.md — the only `resend/resend-laravel` mention left is the "do **not** add" warning) |
| 5 | `.env.example` documents `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT` | **partly already / completed here**: `AWS_USE_PATH_STYLE_ENDPOINT` was present (`:91`); `AWS_ENDPOINT` was MISSING and is added here with a note (the `documents` disk reads both — `config/filesystems.php:73-74`) |
| 6 | *Salud del sistema* reports a missing mail credential | **done already** (`SystemHealth::mailer()`) |
| 7 | *Salud del sistema* reports an unavailable `documents` adapter | **done in this branch** (`SystemHealth::documentsDisk()` + panel row) |

**S3 adapter added** as `league/flysystem-aws-s3-v3` (`require`, not `require-dev`) — the framework's own S3
driver needs the plain Flysystem adapter, exactly as the Resend half needed the plain SDK, not a wrapper.

**Health check** `SystemHealth::documentsDisk()` reports the configured `documents` driver and whether its
Flysystem adapter class is present (`s3` → `League\Flysystem\AwsS3V3\AwsS3V3Adapter`; `local` needs none). A
CONFIGURATION check only — it confirms the adapter class exists, never writes a probe object on a page load,
just as `mailer()` never sends a probe email. Surfaced on the panel beside the mailer / scheduler / sweep
checks, because silence is this system's characteristic failure mode.

**`DOCUMENTS_DRIVER=s3` had never been exercised** before this branch — so once a club is on S3, the object-
storage path (encrypt via DocumentVault → write → signed-URL stream → access log) must be verified end-to-end
against a real bucket, not assumed from the local disk. `DocumentVault`'s app-key encryption is unchanged and
stays true on S3 (files are ciphertext before they reach any disk).

**The general rule (for the next person):** anything `.env.example` or `SETUP.md` tells an operator to switch
on must be an INSTALLED dependency, never a Composer suggestion. The next person to document SFTP, a scoped
disk or a read-only disk will hit this again — a documented production driver is a required package.

Tests (`DocumentsDiskHealthTest`, 4, + the existing `MailerHealthTest`, 4): the S3 documents disk resolves and
its adapter class exists (fails against `main`); the health check flags a documents driver whose adapter is
absent (tested with `sftp`, genuinely uninstalled) and is quiet on `local`; the mailer resolves with a key and
is flagged without one. Existing document tests still pass on the `local` disk with DocumentVault encryption
intact. `composer check` green (PHPStan 0). `MAIL_MAILER`/`DOCUMENTS_DRIVER` stay `log`/`local` in
`.env.example`. Pushed; **do not merge**.

**Verification gap (owed — no browser here):** the health panel in all four states (mailer fine / missing key,
documents disk fine / adapter missing), light and dark.
## Prompt 147 — A sede cannot be created: three free-text fields over `time` columns

**Cause (one line):** a form field that can be submitted empty into a column that will not take an empty
value — a `time` column rejects both `null` (for the NOT NULL cut-off) and `''` (for any of the three), and
the DB default only fires when the column is OMITTED from the INSERT, which Eloquent never does. So three plain
`TextInput`s over `time` columns 500'd the whole sede-create on MySQL — and SQLite silently stored `''` into
`business_day_cutoff` (the day-boundary the gram cap, till and Z-report are measured against), which is why the
suite was green on a form that could not be submitted.

**Fix:** three `TimePicker`s (v5, consistent with the `DateTimePicker`s already in ConvocatoriaForm/EventForm),
24-hour (`displayFormat('H:i')` + `format('H:i')`), no seconds, `native(false)` so the 24-hour format is
guaranteed and typed entry stays available on a tablet. `business_day_cutoff` is `required()` with the schema
default pre-filled (`default('06:00')`), so it arrives filled and cannot be emptied. `opening_time` /
`closing_time` stay optional and a blank picker dehydrates to `null`, NOT `''` — asserted at the raw DB level,
because that is the difference between the form working and 500ing and it is invisible in the markup.

**Sweep — every non-nullable column with a DB default, cross-referenced against the forms (verdict per group):**
- `locations.business_day_cutoff` (time) — **the bug. FIXED** (required TimePicker + default).
- `locations.opening_time` / `closing_time` (time, nullable) — same `''` risk. **FIXED** (TimePicker → null).
- Non-nullable date/time set by the system, NOT on any form — **safe, not reachable:** `check_ins.checked_in_at`,
  `document_access_logs.viewed_at`, `convocatorias.held_at` (also on ConvocatoriaForm, but as a REQUIRED
  DateTimePicker → never `''`), `failed_jobs.failed_at`, `lockdown_reactivation_tokens.expires_at`,
  `member_login_tokens.expires_at`, `organisation_lockdowns.locked_at`.
- Booleans with a default (`active`, `published`, `is_therapeutic`, `auto_checked_out`, `is_drill`,
  `not_counted`, …) — **safe:** exposed as Toggles, which dehydrate `0`/`1`, never `''`.
- Integer/cents with a default (`price_cents`, `stock`, `*_cents`, `version`, `member_no_sequence`,
  `fee_cents`, `float_cents`, `grams_cg`, …) — **safe:** set by domain actions/casts or entered via the
  `*_eur`/`*_g`/`*_pct` edge fields; none is a raw empty text field over the column.
- Enum varchar with a default (`status`, `kind`, `method`, `applies_to`, `paid_from`, `default_kind`,
  `purpose`, `product_type`, `unit_type`, `default_period`, `type`, `timezone`, …) — **safe:** exposed as
  Selects (or not on a form), which submit a valid value, never `''`.
- **Nullable date/time columns on forms** were also checked (the `''` problem applies to them too): a grep of
  every Filament form for a `TextInput` over any date/time column is CLEAN — `date_of_birth`, `held_at`,
  `second_call_at`, `starts_at`, `held_on`, etc. all use DatePicker/DateTimePicker, which dehydrate `null`.

**Kept deliberately:** the DB default (protects rows created outside the form); `business_day_cutoff` stays
NOT NULL (not made nullable to dodge the bug — half the domain depends on it, and a nullable column would push
an invisible fallback into `BusinessDay`); the timezone default; no data migration (existing rows untouched).

**Friendlier global save-failure surface — its own branch.** With `APP_DEBUG=false` a constraint violation
reaches the user as Filament's generic *"Error while loading page"*, which tells nobody anything. Changing that
globally (a Filament exception-render override that turns an unhandled save failure into a readable message) is
out of scope here and deserves its own branch — noted. This branch removes THIS class at the source: a picker
cannot submit an invalid time, so the failure never occurs.

Tests (`LocationTimeFieldsTest`, 5, **run on MySQL** — SQLite would hide the `''` case): cut-off untouched
stores `06:00`; blank opening/closing store `null` (asserted raw, not `''`); an emptied cut-off is refused with
a form-validation error, not a 500; a form-default location resolves correctly in `BusinessDay`; editing without
touching the times preserves them. `composer check` green (PHPStan 0), and the suite green on the MySQL parity
profile.

**Verification gap (owed — no browser here):** the Locations create form with the pre-filled cut-off + the
picker open, and the emptied-cut-off validation message, light and dark at tablet width. The functional fix —
the form saving, blank→null, emptied→validation-not-500 — is proven headlessly on MySQL.
## Prompt 148 — "All locations" when there is one location, and a batch that guesses which sede it is at

Three faces of one problem: the active sede must never be ambiguous, and stock must always show where it is.

**1. A single-sede org is no longer offered a rollup.** `LocationSwitcher::canSwitchToAll()` was
`hasRole(OWNER)`, unconditionally — so a one-sede club's owner got an "All locations" rollup of a single row,
AND (because the session starts with no `scope.location_id`) that rollup was the DEFAULT state. It now also
requires `available()->count() > 1`. A new `defaultLocationId()` returns the single reachable sede when the
user cannot roll up (a one-sede owner, or a manager with one assigned sede), and the topbar switcher's `mount()`
applies it when no choice has been made — so the sede is named and the scope IS that sede (what is shown
matches what is scoped). A genuine multi-sede owner still defaults to the rollup, unchanged. Zero locations
stays graceful (no default, no error — the owner's first act is to create one).

**2. `CreateBatch` refuses instead of guessing.** It was
`ActiveScope::locationId() ?? Location::query()->value('id')` — in the rollup, `locationId()` is null, so it
took the first row the database happened to return. **This is a compliance failure, not cosmetic:**
`batches.location_id` drives `StockCeiling::forLocation()` (the per-premises legal ceiling) and the registro de
dispensación's truth about where material is; an arbitrary attribution breaches one ceiling and understates the
other. It now uses `locationId()` with no fallback and, when null, REFUSES with a clear message telling the
operator to pick a sede. Confirmed the `?? value('id')` guess appears nowhere else in the codebase.

**3. The batches list shows the sede when it is meaningful.** `BatchesTable` gained a `location.name` column,
`->visible()` only when the org has more than one active location — a column that reads the same on every row
in a one-sede club is noise; it is essential the moment there are two.

**Unchanged:** the owner rollup + org-wide member search for genuine multi-sede owners (why this project does
not use Filament tenancy) stays exactly as it is; managers/staff still switch only among their assignments;
the active location is still persisted in the session — this branch changes what the DEFAULT is and what is
OFFERED, not the mechanism.

Tests (`ActiveSedeTest`, 8, on MySQL): one-sede owner defaults to the sede with no rollup; two-sede owner keeps
the rollup and defaults to it; deactivating a location collapses the switcher to the one remaining; a manager
with one assigned sede sees it named and cannot roll up; **creating a batch with no active sede is refused, not
guessed**; a batch is created at the ACTIVE sede, asserted against a second location existing (the regression
that matters); the batches list shows the location column when more than one exists; zero locations — the
switcher does not error and the owner can reach the Locations create form. `composer check` green (PHPStan 0).

**Verification gap (owed — no browser here):** the topbar with one location and with two, and the batches list
in both cases, light and dark.
## Prompt 149 — Generating an invitation fails, and the invitation is created anyway

`inviteAction()` created the `MemberApplication` FIRST, then did a synchronous, unguarded `Mail::to()->send()`.
When the send threw (with `resend/resend-php` absent, `Class "Resend" not found` — prompt 145), the invitation
row was already committed, the persistent notification with the link was never reached, and the operator saw
Livewire's generic *"Error while loading page"*. Every failed attempt left an orphaned PENDING application with
a live token nobody had seen — the database's model of reality ("you have created four invitations") and the
operator's ("that didn't work") allowed to disagree.

**Generating an invitation is now one thing, and mail is never what decides.** A new atomic writer
`App\Actions\Members\IssueApplicationInvite` creates the row; the caller then QUEUES the mail best-effort
(`Mail::to()->queue()`, wrapped in try/catch), so a delivery problem belongs in Horizon's failed jobs and can
never orphan an invitation or hide its link. The link is shown UNCONDITIONALLY (persistent notification),
whether mail was requested, succeeded, failed or was never attempted; if the queue push itself failed it says
so IN ADDITION to the link, never instead of it.

**Two explicit invitation paths (a Radio), because "optional email" left the choice implicit.** EMAIL the
invitation (email required), or generate a LINK to hand over (a required `applicant_reference` — a name, or the
referring member — new nullable column). Email stays OPTIONAL because a prospective socio is usually introduced
in person by an avalador at the door and the no-email path is the common CSC flow — making email mandatory
would break it. But an anonymous invitation is a real weakness (you cannot tell who a circulating link was
for), so the operator must now CHOOSE, and the hand-over path is attributable. `applicant_email` is *not* made
unconditionally required — see above for why.

**Every `Mail::to()->send()` site reviewed (verdict each):**
- `ListMemberApplications::inviteAction` — **REFACTORED**: atomic create + queued best-effort mail + link shown
  unconditionally.
- `MemberApplicationsTable::resendAction` — **queued + guarded**: a resend failure is a readable message, not a
  500.
- `DispensaryPos::emailReceipt` (the counter) — **queued + guarded**: a mail failure is a readable counter flash
  mid-service, never an error screen.
- `SweepMembershipExpiry` (nightly) — **queued + guarded**: the quiet one — a throw mid-loop used to abort the
  whole run, so one bad address stopped every remaining member's reminder and turned the heartbeat red for
  reasons nobody would connect to email. Now one address cannot abort the run; queue-push failures are counted
  and reported, and a real delivery failure surfaces in Horizon's failed jobs.
- `SendMemberCard` — already `->queue()` (locale-pinned); it is the pattern this branch generalises. Unchanged.

**Inherited / unchanged:** `applicant_email` is normalised by the model cast (prompt 146, on main). The token
stays 48 random chars, hashed for lookup and encrypted at rest so it is re-copyable; `invite_expiry_days`
unchanged; existing PENDING applications keep working (the new column is nullable) — including the orphans the
live install already has.

**Friendlier global save-failure surface — its own branch** (shared finding with 147): other unhandled save
failures still reach the user as Filament's generic error with `APP_DEBUG=false`. A global report-and-friendly-
message surface deserves its own branch; this one removes the invite / receipt / sweep failures at the source.

Tests (`ApplicationInviteTest` 5 + `ReceiptMailFailureTest` 1, **on MySQL**): with mail hard-failing the
invitation still succeeds and the link is still shown (the regression that fails against `main`); the email path
QUEUES; the hand-over path requires its identifier and produces a link; an invitation is redeemable through the
public application form; the membership sweep completes when a member's mail throws and reports it; the counter
receipt reports a mail failure instead of 500ing. `composer check` green (PHPStan 0), suite green on MySQL.

**Verification gap (owed — no browser here):** the invitation dialog in both modes and the resulting link
notification, light and dark.

Pushed; **do not merge**.

## Prompt 150 — Every member email was branded with OUR name, not the club's

**Symptom.** All 10 mailables were 10 near-duplicate standalone HTML views, each hard-coding the PRODUCT
header (`$message->embed(resource_path('mail/logo.png'))`, whose PNG literally reads "CSC platform · asociación
cannabica") and signing off with `config('app.name')`. A member of *Asociación X* received email branded
"CSC platform" — and there was no plain-text alternative part at all.

**Fix — one shared shell, club identity, both MIME parts.**
- **`<x-mail.shell>`** (`resources/views/components/mail/shell.blade.php`): the single mail layout — doctype,
  table chrome, header, sign-off and footer, defined once. All 10 views are now body-only (the near-copies are
  gone). Each keeps its own content **verbatim** — the receipt's "…nunca una venta" disclaimer, the QR carné,
  the convocatoria's agenda; the convocatoria's legal footer is preserved via an overridable `footer` slot.
- **The header is the CLUB, resolved through `OrganisationIdentity`:** the club's uploaded logo, CID-embedded
  (new `OrganisationIdentity::mailLogo()` returns RAW bytes + mime — the existing `logo` accessor is a `data:`
  URI, which is right for dompdf but stripped by email clients), OR — until a club can upload one — the club's
  NAME as a text wordmark. It **never** falls back to `mail/logo.png`: that image IS our wordmark, so rendering
  it is exactly the defect this prompt fixes. "Fallback to product" is honoured through the NAME
  (`OrganisationIdentity['name']` → `config('app.name')` only for a genuinely unconfigured org), not the logo
  image. The sign-off and footer ("Este mensaje te lo envía :club, tu asociación.") are the club too.
- **Plain-text alternative for every mailable (multipart/alternative):** 10 `mail/text/*` views, each mailable's
  `content()` now carries `text:` beside `view:`. The text part is club-branded too.
- **`LockdownReactivationMail` added to `DevMail`** — it was the 10th mailable and was registered NOWHERE, so it
  rendered in no preview and no test (the exact gap `DevMail`'s docblock warns about). Now previewed + tested.

**FLAGGED GAP — a club cannot yet upload its logo.** No Filament field writes `organisations.logo_path`, so in
practice every club sees the NAME wordmark, not a logo. The plumbing is complete and proven: `mailLogo()`
resolves it, the shell embeds it by CID, and `MailRenderTest::test_an_uploaded_club_logo_is_embedded_by_cid…`
asserts an uploaded logo is CID-embedded (never hot-linked). The missing piece is a single logo-upload field on
the organisation settings form — the owed follow-up, called out here so it is not mistaken for done.

**Verified.** `MailRenderTest` now sends every registered mailable through the array transport (so BOTH parts
are built as a recipient gets them) and asserts each is club-branded in HTML **and** text, carries a non-empty
plain-text part, never leaks "CSC platform", and never hot-links an image. Screenshots of the receipt, carné and
convocatoria show the club wordmark header, the verbatim bodies, and the convocatoria's legal footer override;
the plain-text parts render cleanly (no leaked Blade directives). `composer check` green (Pint, PHPStan 0, 968
passed / 3 skipped; +46 assertions). Pushed; **do not merge** (human review).

## Prompt 151 — 143's topbar styling never reached the browser: utilities compiled into a stylesheet the panel doesn't load

**143 was right about the layer, blocked underneath it.** `--primary-600` resolves, Filament's theme uses it,
and `bg-[var(--primary-600)]` was the correct replacement for the non-existent `bg-primary-600`. But NONE of
the switchers' hand-written utilities applied in the panel, because they compiled only into **`app.css`** — which
the panel never loads. The Filament panel serves Filament's `index.css` + the compiled **`theme.css`**; `app.css`
is for the counter and the member PWA. And `resources/css/filament/admin/theme.css` scanned only
`app/Filament/**` and `resources/views/filament/**` — **not `resources/views/livewire/`**, where
`locale-switcher.blade.php` and `location-switcher.blade.php` live (both rendered *inside* the panel by render
hooks). So `text-xs`/`font-semibold`/`transition` (which Filament's own theme emits anyway) applied, and
everything else — `min-h-[1.5rem]`, `min-w-[1.75rem]`, `bg-[var(--primary-600)]`, `pl-3` — silently did not. The
theme file's own comment already recorded this bug being fixed once (the help pages); two dirs were added then,
this one missed. **Third occurrence of one bug class.**

**Why 143's own verification missed it.** The 143 harness inlined ALL of `public/build/assets/*.css` — including
`app.css` — so the utilities were present in the harness and absent in the real panel. A render-to-HTML harness
is structurally blind to this: the markup was always correct; only the *served* stylesheet was wrong.

**Fix — one line, in the scan list, not the component.** Added to `theme.css`:
`@source '.../resources/views/livewire/**/*'` + `@source not '.../resources/views/livewire/counter/**/*'`.
**Scope chosen deliberately:** scan the whole `livewire/` dir (so any *future* panel-rendered Livewire view is
covered too — that is what closes the bug class) but EXCLUDE `counter/`, which runs on its own layout served by
`app.css` and never renders in the panel, so its large utility set has no business bloating the theme loaded on
every admin page. Verified in the rebuilt theme (+~2 KB, not the whole of counter/): `bg-[var(--primary-600)]`
compiles to `{background-color:var(--primary-600)}`; the switcher utilities are present; the counter class
`grid-cols-[minmax(0,1fr)_22rem]` is absent.

**Nothing from 143 reverted** — the `GLOBAL_SEARCH_AFTER` relocation, segmented-pill markup, `aria-pressed`, and
16 px group gaps were all correct and remain.

**Every render-hook view in `AdminPanelProvider`, with a verdict:**
| Hook | View | Directory | Verdict |
| --- | --- | --- | --- |
| `TOPBAR_START` → `LocationSwitcher` | `resources/views/livewire/location-switcher.blade.php` | `resources/views/livewire/` | was UNSCANNED → **fixed** |
| `GLOBAL_SEARCH_AFTER` → `LocaleSwitcher` | `resources/views/livewire/locale-switcher.blade.php` | `resources/views/livewire/` | was UNSCANNED → **fixed** |
| `GLOBAL_SEARCH_AFTER` → `@include('filament.help-menu')` | `resources/views/filament/help-menu.blade.php` | `resources/views/filament/` | covered ✓ |

The 14 custom Page `$view` are all `filament.pages.*` (covered); the dashboard chart widgets extend Filament's
`ChartWidget` (vendor view, covered by the theme's `@import`); `filament.batch-recall` + the partials are under
`resources/views/filament/` (covered). The only gap was the two switchers.

**Guard (`PanelThemeSourceTest`).** Source-level: each panel-rendered view resolves under a theme `@source`
include and not a `@source not` exclude (no build needed — catches a missing scan). Built-CSS: the built theme
contains `min-h-\[1\.5rem\]`, `min-w-\[1\.75rem\]`, `bg-\[var\(--primary-600\)\]`, `pl-3` — utilities Filament's
own theme does not emit, so their presence proves the scan reached the switchers. CI runs `npm run build` before
both jobs, so the built-CSS assertion actually guards. This would have caught all three occurrences.

**Verified LOGGED IN against the running app** (not a harness — the whole lesson): a real owner session, `/` at
1280/1024/800 × light/dark × es/en. Computed styles: both locale segments **31–32 × 24 px** (was 15×16/16×16
against a 24×24 floor); active-segment `background-color` = `oklch(0.598 0.169 262.881)`, non-transparent and ≠
the inactive segment (was transparent — the very defect 143 targeted); location `<select>` `padding-left: 12px`
(was 0); inner ES/EN gap ~2 px, group gaps 16–18 px, no overlap. `composer check` green; MySQL green.
Pushed; **do not merge** — (owner subsequently authorised the merge).

## Prompt 152 — Approval requires a SUBMISSION, not a completeness audit

**The bug.** A generated invitation is a `MemberApplication` with `status = PENDING`, `payload = []`,
`submitted_at = null` — created the instant staff press *Generar invitación*, before the applicant types
anything. `approveAction()` gated visibility on `PENDING && applications.review` only, so **every unredeemed
invitation showed Approve**. Pressing it ran `ApproveApplication` on an empty payload, whose first line is the
age gate; `isOldEnough(null)` is false, so the operator was told **"el solicitante es menor de la edad
mínima"** — a factual claim about a person who does not exist yet. Reachable today: prompt 149's mail failures
leave orphaned PENDING invitations in the list.

**Fix — require a submission, in two places.**
- **UI:** all three review decisions (approve / reject / waiting-list) now share `isDecidable()` =
  `PENDING && submitted_at !== null && can('applications.review')`. An unredeemed invitation offers only invite
  actions (Copiar enlace / Reenviar / Anular); a submitted application offers the decisions. The available
  actions now distinguish the two row types — *chase the first, decide the second*.
- **Action (defence in depth):** `ApproveApplication` refuses an unsubmitted record with a plain "no se ha
  enviado" message, placed **before** the age gate so the empty-payload age check is never the reason surfaced.

**Reject and waiting-list on an unsubmitted invitation — removed, deliberately.** Revoking an unredeemed
invite is a real need and already exists (Anular, on the invite). "Rejecting" or "waiting-listing" a person who
never applied is meaningless — there is no applicant to reject. So all three decisions require a submission;
ending an invitation is Anular, not Rechazar.

**`?? ''` name defaults made strict.** `ApproveApplication` built the member with `first_name`/`last_name`
`?? ''`. A partially-populated payload arriving by any route therefore enrolled a **blank name against a valid
member number** into the libro de socios — a statutory register — silently. Not reachable via the public form
(`SubmitApplicationRequest` requires both), but `?? ''` is precisely how you get a nameless row, and a nameless
row in a statutory register is a compliance problem. Now it fails loudly, naming the missing field, and no
member is created.

**What was deliberately NOT built — a completeness gate at approval.** The prompting request was "you shouldn't
be able to approve unless they've filled out all the info needed." Rejected, for the reasons the prompt lays
out: (1) it is already enforced where it belongs — `SubmitApplicationRequest` makes a public-form submission
complete by construction, so the gate would be inert on the normal path; (2) hard-blocking on completeness
produces **fabricated data** — staff who cannot proceed invent a value, and an invented-but-complete register
is worse than one with visible gaps (the trustworthiness of the register is the product); (3) it contradicts
this codebase's own idiom (`User::setupIncompleteReasons()`, the import's `consent_pending` count) — let the row
exist, surface the gap, make it actionable. **Where such a rule belongs if it is ever needed:** the enforcement
matrix (`ResolveMemberEligibility`), per-rule BLOCK/WARN/OVERRIDE — because the real risk is an incomplete
member being *dispensed to*, not an incomplete record existing, and the club decides whether a missing document
number blocks/warns/overrides at the counter. Not a hard gate on an admin button. Only the specific
nameless-register defect above was fixed, and only by failing loudly, not by auditing completeness.

**Unchanged (prompt rule):** the age gate + its message (correct once a real submission carries a real DOB),
the duplicate search, prompt 97's versioned-consent stamping, `MemberEnrolment::defaults()`, the audit entry
and the automatic QR card. `applications.review` still gates every decision — no permission changes. The list's
invitation-vs-submission distinction (the `invite` badge: Sin abrir / Abierta / Enviada, plus the lifecycle
filter) already existed from prompt 45/149; this branch adds the behavioural half so the two need not be told
apart by badge alone. Tests: `ApproveRequiresSubmissionTest` (5) + updated onboarding/card/cleanup/duplicate
suites (fixtures now `->submitted()`). `composer check` green; full MySQL suite green (1045 passed).

## Prompt 153 — Consent text is per-locale, versioned as a set, with the locale read recorded

**The tension.** An English applicant saw English labels but was asked to tick two boxes under Spanish consent
paragraphs — arguably not *informed* consent (Art. 7(2): clear and plain language), and these are Article 9
special-category purposes. But consent must also be *reproducible*: the club must show exactly what a member
agreed to on the day (why `consent_text_version` exists, and why prompt 97 stamps the version the applicant
SAW). Naively wrapping the texts in `__()` fixes the first and destroys the second — two texts, no record of
which was read, and a translation free to drift from the Spanish without the version changing.

**Resolution — author both languages, version them together, record which was read.**
- The two texts (`consent_privacy_text`, `consent_statutes_text`) are now **per-locale arrays** `{es, en}` in
  `Settings::DEFAULTS`, stored as JSON. One `consent_text_version` covers the whole SET, so the es and en of a
  version are by construction the same declaration. Read only through **`App\Support\ConsentText`** (es
  fallback, legacy-single-string safe — a missing locale degrades to the Spanish, never to a blank declaration).
- **`consent_records.locale`** records the language the applicant was READING at submit — the same class of
  fact as the version, captured in the payload by `ApplicationController` (`app()->getLocale()`) and stamped by
  `RecordMemberConsent` at approval. Prompt 97's guarantee extends unchanged: both the version AND the locale
  stamped are the ones seen at submit, never a later revision or an admin-side language switch.
- **Both facts are needed:** the locale answers "in what language was this consent informed?", the version
  answers "to which text?" — together they make an inspection's "what did this member agree to, and could they
  read it?" answerable.

**Spanish is authoritative.** The club is a Spanish asociación with Spanish estatutos; the English is a
translation of a specific version. The form says so, on the form, in non-es locales ("La versión auténtica … está
en español; esta es una traducción de la versión :v."). The fallback is to the Spanish, never blank.

**The second gap: no club could edit these.** They had a Spanish default and no editor — every club was
presenting a summary of statutes it did not write and could not correct. This is CLUB-AUTHORED legal content,
not product copy, which is exactly why `__()` was the wrong mechanism. New page **`ManageConsentText`** edits
both languages of both texts + the version. Gated on a NEW **`settings.consent`** permission (OWNER only), held
SEPARATELY from the routine `settings.manage` thresholds — editing the text everyone consents to is not routine.
**A changed text under the same version is refused** (persistent error, no save): a silent edit would leave
already-consented members recorded against a version whose text no longer says what it said. Bumping the version
is the whole point of `consent_text_version`, so the edit forces it.

**Consent records predating this change.** `locale` is nullable and left NULL for existing rows — members who
consented under 1.0 did so before this was recorded, and **absent means absent**; the migration does not invent a
Spanish that was never observed. The string→array settings conversion (folding a club's existing Spanish wording
into `{es: <theirs>, en: <shipped default>}`) is deliberately **one-way** — any English a club later authors has
no single-string home, so `down()` does not reverse it (same stance as prompt 146).

**RAT — decided YES, a brief note.** The RAT's consent legal-basis asserts *valid* consent; that the declaration
is provided in the applicant's language (Spanish authoritative) and the version + language read are recorded
directly evidences that, so RAT-01's legal basis now says so. **AnonymiseMember** is unchanged in behaviour —
`locale` is not PII (no name/DNI), the `consent_records` coverage note just adds it, and the
`COVERED_MEMBER_TABLES` guard stays green. Locale resolution on the form is untouched (SetLocale → ResolveLocale,
session override honoured); only the two consent texts moved to per-locale storage — no other setting did.
Tests: `ConsentTextPerLocaleTest` (7, incl. the reported switch bug, locale recorded, prompt-97-extended-to-locale,
no-locale-not-rewritten, editor + version-bump + denial) + the prompt-97 version test updated to the resolver.
`composer check` green; full MySQL suite green (1052 passed).

## Prompt 154 — The View page of an unredeemed invitation now reads as an invitation, not a broken form

Completes prompt 152's presentation half. 152 gated the *actions* (approve requires `submitted_at`, covering the
View page via `recordActions()`), but never touched `MemberApplicationInfolist`, so `/member-applications/{id}`
on an unredeemed invitation still showed **Status: Pending** and an empty *Formulario* key-value panel that reads
as a table that failed to load.

**What the page now shows.** When `submitted_at === null` the infolist renders an **Invitación** section instead
of the payload panel — the three lifecycle facts the work order named, because they are three different operator
actions: **Enviada** (`created_at`, when the link went out → *is it worth chasing*), **Abierta por el
solicitante** (`opened_at`, "Todavía no" until they open it → *they opened it and stalled vs never looked*), and
**Caduca** (`invite_expires_at` → *the link is dying/dead*). The empty payload section is `->visible(submitted_at
!== null)`, so it simply is not rendered for an invitation. A **submitted** application's page is unchanged — the
payload KeyValueEntry renders exactly as before (pinned by a test).

**Actions carried, not copied.** `copyLinkAction` / `resendAction` / `revokeAction` and `inviteLabel()` moved
from `MemberApplicationsTable` to `MemberApplicationResource` as shared statics (`inviteActions()`), so the list
and the View page use the *same* actions — the next surface inherits them. `inviteUrl()` remains the one and only
way the link is derived (from the retained encrypted `invite_token`); no second derivation was written.

**Tightened to an OUTSTANDING invitation.** The invite actions now gate on `isInviteLive() && submitted_at ===
null` (new `isOutstandingInvite()`), not `isInviteLive()` alone — a *submitted* application is still PENDING and
therefore still `isInviteLive()`, but copying/resending an invite to someone who already applied is meaningless.
So a submitted application's page offers no invite actions (only the review decisions), and the list does the
same. `copyLink` also gained the `members.create` gate the other two already had — revealing the invite link is
invite management, so the denial test ("no permission → neither action") is now true of all three.

**The expired case is its own dead end.** All invite actions gate on `isInviteLive()`, which excludes expired, so
no copyable *dead* link is ever offered; the Invitación section adds a danger note telling the operator the link
has expired and to **generate a new invitation from the list**. Deliberately NOT an "extend expiry / reissue"
capability — the invite never had one, and adding it is new behaviour, not the presentation fix this prompt is.
A fresh invitation is the existing, correct path.

Approval gating (152) is untouched and its tests still pass. Tests: `InvitationViewPageTest` (5). `composer
check` green; full MySQL suite green (1057 passed). New copy in both lang files.
