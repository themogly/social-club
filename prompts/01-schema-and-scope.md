# 01 — Schema, identifiers, scope, money & weight

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`, and `NOTES-decisions-and-compliance.md`.

`git checkout main && git pull` → `git checkout -b feat/schema-and-scope`.

> **This prompt has an ARCHITECTURE CHECKPOINT.** Propose the scope mechanism, the identifier
> strategy, the money/weight wiring **and the wallet/pricing shape**, then **STOP and present the
> proposal for approval before writing migrations.** Section C of NOTES lists the client answers this
> needs. Everything else in the build sits on this — do not wave it through.
>
> **The whole schema is defined here.** Later prompts add behaviour, screens and rules, not tables.
> If a later prompt needs a column that isn't below, that's a bug in this prompt — come back and fix
> it here rather than scattering migrations across the build.

## Checkpoint — propose and pause

1. **Scope.** Recommend `organisation_id` on every table (one seeded org), with **Location as the
   operational scope** via a custom switcher + a `LocationScope` global scope — *not* Filament
   tenancy, because the owner's "All locations" rollup and org-wide member search must cross the
   boundary. State the trade-off; get sign-off.
2. **Identifiers.** UUID/ULID primary keys on every user-addressable model. Confirm ULID (sortable,
   shorter) vs UUIDv4. Internal-only pivots may stay integer.
3. **Money & weight.** `cents` (int) + `Money` cast; `centigrams` (int) + `Weight` cast. **If the
   client picks 0.001 g instead of 0.01 g, the column names below change — settle it here.**
4. **Wallet shape.** Per-location balances, or one pooled org-wide balance? Every downstream prompt
   assumes the answer, so it must be answered now. (v1 used per-location with an org-wide debt limit
   and ring-fencing. Carry it forward only if still wanted.)
5. **Pricing shape.** Confirm: price is **per gram, per genetic, per location**, with optional
   **per-tier** prices. Discounts resolve on top. This determines three tables below.
6. **Per-location vs org-wide.** Recommended: member *people* records and genetic *definitions* are
   org-wide; prices, batches, stock, tills, transactions, expenses and staff assignment are
   per-location; membership tiers and standard discounts are org-wide templates.
7. **Day boundary.** Every location has a timezone and a **business-day cutoff** (e.g. 06:00). The
   daily gram cap, the calendar-month reset, auto-checkout, the entry–exit sheet and every Z-report
   depend on it. Confirm the cutoff. A club whose legal defence is "the daily cap blocked it" cannot
   have an undefined day.

## Conventions for every table below

- ULID/UUID pk. `organisation_id` on everything. `location_id` where per-location.
- **Money columns end `_cents`. Weight columns end `_cg`. Rate columns say so** (`price_per_gram_cents`,
  `cost_per_gram_cents`). Percentages are stored as **integer basis points** (`_bp`; 17.5% = 1750) —
  never a float.
- Enums are PHP backed enums, **UPPERCASE**, and every status enum is enumerated here so no two
  prompts invent different words for the same state.
- Soft deletes on anything a human might delete by accident.

## Build — models & migrations

**Org, scope, config**
- **Organisation** — name, legal_name, tax_id (CIF/NIF), address, logo, contact, `settings` (JSON).
- **Location** — organisation_id, name, address, `capacity` (aforo), `timezone`,
  `business_day_cutoff` (time), opening/closing time, accent, `settings` (JSON), active.
- **Setting** — a proper keyed table (org- and location-scoped, typed value, default), **not** only
  the JSON blobs. Prompt 03 owns the UI; the table and the `Settings` accessor class live here.
- **User** — name, email, password, `pin` (hashed, nullable), `mfa_secret` (encrypted, nullable),
  `mfa_confirmed_at`, active, soft deletes. Pivot `location_user`.

**Members**
- **Member** — organisation_id, `member_no`, first/last name, email, phone, `date_of_birth`, address,
  photo_path, `document_type` (DNI|NIE|PASSPORT), `document_number` (**encrypted**),
  `document_scan_path` (private disk), `status`
  (APPLICANT|ACTIVE|INACTIVE|EXPIRED|SUSPENDED|EXPELLED), `is_therapeutic`, `avalador_member_id`
  (self-FK, nullable), `joined_at`, `left_at`, `carencia_ends_at`, `declared_monthly_cg`,
  `daily_limit_cg` / `monthly_limit_cg` (nullable overrides), `sole_association_declared_at`,
  `anonymised_at`, soft deletes.
- **MemberToken** — member_id, `token_hash`, purpose (QR_CARD), `issued_at`, `revoked_at`. The QR
  card must be **revocable and regenerable**, so the token is a row, never a derivation of the id.
- **MemberApplication** — organisation_id, location_id, `invite_token_hash`, submitted payload
  (JSON), `status` (PENDING|APPROVED|REJECTED|WAITING_LIST), reject_reason, reviewed_by/at,
  resulting member_id.
- **ConsentRecord** — member_id, purpose, `consent_text_version`, granted/withdrawn at, ip. **A row
  per consent per version** — consent history cannot live in a scalar column.
- **MemberDocument** — member_id, `type` (ID|CONSENT|DECLARATION|MEDICAL|REGISTRATION_FORM|
  SANCTION_ACT|OTHER), private path, uploaded_by, `signed_at`, `version`, `generated_from` (nullable
  template id).
- **MemberSanction** — member_id, `type` (WARNING|SUSPENSION|EXPULSION), reason, from/until, recorded_by.
- **DocumentAccessLog** — actor, member_document_id, viewed_at, ip. Reads of sensitive documents are
  logged separately from the general audit trail so "who opened whose passport scan" is one query.

**Membership**
- **MembershipTier** — organisation_id, name, `default_fee_cents`, `default_period`
  (MONTHLY|YEARLY|LIFETIME|CUSTOM — CUSTOM means an explicit end date is set per membership),
  benefits, active.
- **Membership** — member_id, location_id, tier_id, starts_at, `expires_at` (null = lifetime),
  `fee_cents`, `fee_override_by`, `status` (**ACTIVE|EXPIRING_SOON|LAPSED|CANCELLED** — derived and
  persisted by the nightly sweep so it can be indexed and filtered).
- **MembershipFeePayment** — membership_id, `amount_cents`, `method` (CASH|WALLET|BANK|CARD),
  till_session_id (nullable), paid_at, recorded_by, `instalment_of` (nullable). **Fee income is a
  first-class income type** — without this row it cannot be reported separately from contributions.

**Attendance**
- **CheckIn** — member_id, location_id, `checked_in_at`, `checked_out_at`, `auto_checked_out`,
  operator_id, `method` (QR|MANUAL).

**Catalogue, pricing, stock**
- **Category** — organisation_id, name, `applies_to` (GENETIC|ARTICLE). One category model for both
  catalogues, so filters and reports behave the same way.
- **Genetic** (org-wide definition) — name, description, category_id, `thc_bp` / `cbd_bp` (basis
  points), terpenes (JSON), `cultivation_type` (INDOOR|OUTDOOR|GREENHOUSE), images, `published`, active.
- **GeneticPrice** (**per location**) — genetic_id, location_id, `tier_id` (**nullable = the base
  price**), `price_per_gram_cents`, `low_stock_threshold_cg`, active. This is the table prompt 11's
  tier pricing resolves against; the genetic itself holds no price.
- **Batch** (per location) — genetic_id, location_id, `batch_no`, acquired_or_harvested_on,
  `expires_on`, `initial_cg`, `remaining_cg`, `cost_per_gram_cents`, lab_report_path, notes,
  `status` (OPEN|CLOSED|QUARANTINED).
- **Article** (bar/food/merch, per location) — name, category_id, `price_cents`, `stock`,
  `low_stock_threshold`, images, active.
- **StockMovement** — polymorphic over Batch/Article, location_id, `qty_cg` (nullable, batches) and
  `qty_units` (nullable, articles) — **two explicitly-named columns, not one dual-meaning `qty`** —
  `type` (INTAKE|DISPENSE|SALE|ADJUSTMENT|MERMA|TRANSFER_IN|TRANSFER_OUT), reason, operator_id,
  reference, stock_take_id (nullable).
- **StockTake** — location_id, opened_by, opened_at, committed_by/at, `status` (OPEN|COMMITTED),
  notes. **StockTakeLine** — stock_take_id, polymorphic item, `counted_cg` / `counted_units`,
  `expected_cg` / `expected_units`, variance.

**Discounts**
- **Discount** — organisation_id, name, `kind` (STAFF|LOCAL|CONCESSION|THERAPEUTIC|CUSTOM),
  `mode` (PERCENT|FIXED), `value_bp` or `value_cents`, `applies_to` (GENETIC|ARTICLE|BOTH),
  category_id (nullable), active. Per-location enablement via a pivot.
- **MemberDiscount** — member_id, discount_id (or an inline value), assigned_by, `expires_at`.

**Transactions**
- **Dispensation** (the **basket header** — a multi-line withdrawal is one document, one payment,
  one void) — member_id, location_id, operator_id, till_session_id, `total_cents`,
  `cash_cents` + `wallet_cents` (**the tender split — the till reconciliation is derived from these,
  never inferred**), `status` (COMPLETED|VOIDED|CORRECTED), `reversal_of_id` (nullable, self-FK),
  void reason/by/at, `signature_path`, `idempotency_key` (unique), `reference`, dispensed_at.
- **DispensationLine** — dispensation_id, genetic_id, batch_id, `grams_cg`,
  `price_per_gram_cents` (frozen), `discount_cents` (frozen), `line_total_cents`, snapshot of
  genetic name and batch_no.
- **Order** (bar/merch) — location_id, member_id (**nullable**), operator_id, till_session_id,
  `items` (JSON snapshot), `total_cents`, `cash_cents` + `wallet_cents`, `status`
  (COMPLETED|VOIDED), `reversal_of_id`, void reason/by/at, `idempotency_key` (unique), `reference`.
- **WalletTransaction** — member_id, location_id (per the checkpoint), `amount_cents` (signed),
  `type` (TOPUP|CONTRIBUTION|FEE|REFUND|ADJUSTMENT|TRANSFER_IN|TRANSFER_OUT),
  `balance_after_cents`, operator_id, till_session_id (nullable), reason, polymorphic source,
  `transfer_pair_id` (nullable — the two halves of a credit transfer between locations).

**Cash**
- **TillSession** — location_id, `terminal`, opened_by, opened_at, `float_cents`, closed_by,
  closed_at, `counted_cents`, `expected_cents`, `variance_cents`, `status` (OPEN|CLOSED), notes.
- **CashMovement** — till_session_id, `amount_cents` (signed), `type` (IN|OUT|BANKED|PETTY_CASH),
  reason, operator_id.

**Money out**
- **ExpenseCategory** — organisation_id, name, `default_kind` (TILL|OVERHEAD), active. Seeded:
  Stock, Consumables, Staff payment, Repairs & maintenance, Rent, Utilities, Other.
- **Expense** — location_id (nullable), category_id, `amount_cents`, `paid_from`
  (TILL_CASH|BANK|CARD|OTHER), `kind` (TILL|OVERHEAD), till_session_id (nullable),
  `recurrence` (JSON), receipt_path, recorded_by, approved_by/at, incurred_on.
- **RecurringExpenseRun** — expense template id, `period_key` (e.g. `2026-07`), created_expense_id.
  **The idempotency marker for the scheduler** — unique on (template, period_key).
- **Supplier** / **Purchase** — supplier, location, `amount_cents`, items, invoice_path, paid/owing,
  purchased_on, linked batch_id (nullable).

**Documents & governance**
- **DocumentTemplate** — organisation_id, `type`, body, `version`, active.
- **Minute** (*acta*) — organisation_id, `book` (ASSEMBLY|BOARD), `number` (sequential per book,
  unique), `type`, held_on, location_id, agenda (JSON), resolutions (JSON), attendee member ids
  (JSON), quorum figures, body, `signed_at`, `supersedes_id`. **No update or delete once signed.**

**Communications**
- **Announcement** — organisation_id, location_id (nullable = all), title, body, published_at,
  expires_at, author_id.
- **Event** — organisation_id, location_id, title, description, starts_at, capacity.
  **EventRsvp** — event_id, member_id, status, responded_at.
- **PushSubscription** — member_id, endpoint, keys, created_at.

**Audit**
- **AuditLog** — **append-only** (no update/delete path, enforced in the model): actor, action,
  auditable (polymorphic), `before`/`after` JSON, ip, user_agent, created_at.
- **BreachLog** — incident description, discovered_at, scope, `aepd_notified_at`, status.
- **DataRequest** — member_id, `type` (ACCESS|RECTIFY|ERASE|PORTABILITY|OBJECT|RESTRICT),
  requested_at, completed_at, handled_by. Evidences that the club answered in time.

## Support classes to create here

- `Money` cast (cents ↔ euros) and `Weight` cast (centigrams ↔ grams, 2 dp).
- `round_half_up` — the **one** rounding helper. Line total =
  `round_half_up(price_per_gram_cents * grams_cg / 100)`. Percentage discounts:
  `round_half_up(subtotal_cents * value_bp / 10_000)`, applied **before** rounding the line, once.
- `LocationScope` global scope + the location switcher's session contract.
- **`Settings`** — the accessor class every later prompt refers to. `Settings::get('carencia_days')`
  resolves **location → organisation → hardcoded default**, always returns a value, **never throws
  on a missing or stale entry**. This is the most-referenced class in the build; it exists from here.
- `BusinessDay` — resolves "today" for a location from its timezone and cutoff. Every daily
  aggregate and daily limit uses it; nothing computes a day boundary inline.
- `RecordAuditLog` Action.

## Rules

- Fat models: relationships, `casts()` (not `protected $casts`), scopes (`active()`, `lapsed()`,
  `lowStock()`, `forLocation()`, `insideNow()`). No logic in controllers. List `$fillable`.
- Index every `organisation_id` / `location_id` / `member_id` and the columns the dashboard
  aggregates by (dates, status, type).
- Factories + a **local-only seeder**: one organisation, two locations, tiers, the three pinned users
  from prompt 00 (verified, roles, PINs), ~20 members across every status, genetics with prices and
  batches, articles, discounts, and **a fortnight of dispensations, orders, check-ins, expenses and
  closed till sessions** so prompt 15's dashboard has real data to draw on day one.
- **Opening-balance import path**: the seeder is for dev, but the schema must support a real go-live —
  opening stock as INTAKE movements, opening wallet balances as ADJUSTMENT transactions with a
  reason, and an opening till float. No balance is ever free-typed. Note this in DECISIONS.md.

## Tests (required)

- Location scope: a per-location record created under A is invisible when B is active; owner
  "All locations" sees both; `location_id`/`organisation_id` auto-fill.
- Org-wide member search crosses locations regardless of the active location.
- Money cast round-trips (€0, €12,50, large); signed/negative balances persist.
- Weight cast round-trips: 3.50 g ↔ 350 cg; 0.01 g ↔ 1 cg; no drift over 1,000 additions.
- `round_half_up`: 1.33 g at €7,49/g → pin the exact cent value; a 17.5% (1750 bp) discount on it →
  pin the exact cent value.
- `Settings::get()` returns the default for a missing key and does not throw inside a queued job.
- `BusinessDay` puts 02:00 with a 06:00 cutoff on the previous business day, across a DST change.
- Route/model binding uses ULIDs — an integer id in a URL 404s.
- `AuditLog` and a signed `Minute` cannot be updated or deleted through the model.
- `Dispensation.cash_cents + wallet_cents == total_cents` is enforced (DB check or model invariant).

## Finish

`composer check` green. Add `Money`, `Weight`, `round_half_up`, `LocationScope`, `Settings`,
`BusinessDay` and `RecordAuditLog` to CLAUDE.md reference implementations. Record every checkpoint
answer in DECISIONS.md. Push the branch; **do not merge**.
