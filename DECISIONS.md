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
