# CLAUDE.md — CSC platform working agreement

## What this is

A management platform for a Spanish non-profit **cannabis social club** (*asociación cannábica* /
CSC) operating from **multiple premises**. Private, members-only. Cannabis is dispensed **by weight**
to registered adult members at cost, as a **shared-cost contribution (*aportación*)** — never a
sale. Each location runs as its own club (own members, stock, cash); the **owner** sees an org-wide
rollup. Built single-organisation but **keyed for future multi-organisation SaaS**.

Stack: Laravel (latest) + Blade/Livewire, Filament admin, Tailwind, Alpine + Motion One, Resend,
Redis + Horizon, **MySQL in production** (SQLite for local dev). **No Stripe / no payment provider** —
money is cash + member wallet (integer cents); a payment layer can sit on top of the ledger later.

Read `NOTES-decisions-and-compliance.md` (in `prompts/`) for the legal model (§A), the security
requirements (§B), and the client decisions (§C). Read `DECISIONS.md` before each task.

## Architecture rules (enforced — do not drift)

- No repository pattern. Business logic lives in fat models (methods/scopes) or single-purpose
  Action classes in `App\Actions`; never in controllers.
- Controllers/Livewire components are thin: resolve + return only. No query building or branching on
  request input inline — extract to a model scope or an `App\ViewModels` page class.
- Page/dashboard data assembly → `App\ViewModels`. Genuine framework helpers → `App\Support`. Don't
  mix them.
- Validation: Form Requests when rules are reused/complex; inline `$request->validate()` for simple
  single-use rules (idiomatic — don't over-extract).
- Settings/thresholds are read through an accessor **method** with a sensible default fallback —
  NEVER a raw typed property access that throws on a stale cache. Bust/clear all caches on deploy.
  A stale or missing value must degrade gracefully, not throw (queued mail/jobs fail silently).
- **Never cache transactional data.** Takings, stock, balances, occupancy and limits are ALWAYS
  queried live, never cached. Only slow-changing reference/config content may be cached (plain
  arrays/primitives only — never Eloquent objects), busted via observers.
- Pages render as plain Blade by default. Livewire ONLY for server-driven interactivity (POS,
  check-in, forms, multi-step, live data). A static page MAY embed a Livewire island.
- No `declare(strict_types=1)` (enforced via pint.json). Type-hint all params and returns instead.
- **Larastan L6 requires relation/scope generics** (PHPStan 2.x removed the opt-out; `laravel/pao`
  silently swallows the config error). House style: relation methods carry
  `@return <Relation><RelatedModel, $this>` PHPDoc and scopes carry `@param`/`@return
  \Illuminate\Database\Eloquent\Builder<Model>`. Write them on every model from the start.
- **Money is stored as integer cents (EUR).** Euros only at the input/display edge via a cast.
  **Weight is stored as integer centigrams (1 g = 100 cg, 0.01 g precision).** Grams (2 dp) only at
  the edge via a matching cast. A float in either is a bug. One shared `round_half_up` helper.
- **UUID/ULID primary keys on every user-addressable model** — members, memberships, dispensations,
  orders, batches, documents, users. No sequential integers in any route, API response, filename or
  QR payload. This is a security requirement (see §Security), not a preference. Use ULIDs
  (`HasUlids` / `ulid` columns) — time-ordered, index-friendly.
- **Scope every domain query to `organisation_id` + active location.** Cross-location access is
  deliberate and permissioned (the owner rollup + org-wide member search cross locations). Use a
  custom location switcher + global scope, NOT Filament's built-in tenancy. Write the denial tests.
- **Compliance blocks, it doesn't just document.** Daily/monthly gram limits, carencia, age, active
  membership are enforced inside the same DB transaction as the stock movement — blocked, not warned.
  Overrides are permissioned (manager+), reasoned and logged.
- Use what the framework gives you (Str::, data_get, collections, scopes, casts, notifications)
  before writing helpers. Simplest solution wins; the best abstraction is often no abstraction.
  YAGNI — no code for hypothetical futures, no commented-out blocks, remove dead code.

## Security & privacy (non-negotiable — see NOTES §B)

A competitor in this exact market leaked ~1M member records and ~1M ID scans via sequential-id IDOR.
Because members carried a medicinal-usage flag, it was an Article 9 special-category health-data
breach. Therefore, as build requirements:

- ULID/UUID keys everywhere user-addressable (above). No sequential ids exposed anywhere.
- **ID documents and member photos:** encrypted at rest, on a **separate private disk** (never the
  general uploads disk), served only via **short-lived signed URLs**, never a guessable path, and
  **every view is access-logged**.
- **Authorization on every endpoint, including object-ownership checks** — not just authentication.
  Policies on every Filament resource and every Livewire component. **Write the denial tests.**
- Cannabis consumption/medicinal data is **Article 9 special-category data** — explicit versioned
  consent, documented lawful basis, heightened access control, DPIA.
- **No secrets in any client bundle, ever.** MFA available on admin accounts; session management;
  access logging on sensitive records; append-only audit log.
- `X-Robots-Tag: noindex` globally + `robots.txt` disallow-all. Everything behind auth.

## Legal / compliance constraints that shape the code (see NOTES §A)

- **No public / marketing surface at all.** Spanish CSCs may not advertise: no landing page, no
  public menu, no product pages, no sitemap, no search-indexable route. `/` is the dashboard. This
  is a **legal constraint, not a preference.**
- **Every threshold is a configurable Setting**, never a hardcoded constant (age, carencia, daily/
  monthly gram caps, forecast options, aforo, stock ceiling). Regional practice varies; case law moves.
- **Vocabulary.** UI and reports use *socio*, *aportación / contribución*, *dispensación*, *aval*,
  *avalador*, *carencia*, *arqueo*, *superávit*. **Never** *cliente*, *venta*, *precio de venta*,
  *beneficio*. Bar/merch income is a separate ledger so it never muddies non-profit accounting.
- **Honesty rule.** CSCs operate under judicial *tolerance*, not authorisation. The software makes
  the club *able* to evidence what it did — it does not imply that recording an obligation discharges
  it. Where the law is unsettled/regional, say so and make it configurable. Nothing here is legal
  advice and the UI must not imply otherwise.

## Language / i18n

Ships **multilingual. System default is now English** (`APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`);
Spanish is fully translated and first-class (it is what club staff use daily). All user-facing strings
go through `__()` / lang files — never hardcode UI copy. **Keys are the Spanish source string**;
`lang/es.json` maps each to itself and `lang/en.json` to English, with enforced key parity
(`tests/Feature/Localization`, in `composer check`; `php artisan lang:sync` regenerates es.json). A
missing `en.json` key would leak Spanish, so completeness is gated. **Locale resolves through one
place** — `App\Actions\ResolveLocale`: per-user `users.locale` → org `default_locale` Setting →
system `en`; applied in `SetLocale`, switched via the topbar `LocaleSwitcher` (persists to the user
row, effective next request, no re-login). **Every backed enum exposes a translated `label()`** — never
render a raw enum value. Domain vocabulary (socio/aportación/dispensación…) stays Spanish where it is a
term of art but IS translated in the English UI (Member/Contribution/Dispensing) — see the canonical
glossary in `DECISIONS.md`; never let "translate" slip into commercial framing (customer/sale/profit).

## Design rules

```
--brand #2563eb  --brand-dark #1d4ed8  --brand-tint #eff6ff
--surface #ffffff  --surface-alt #f8fafc  --text #0f172a  --text-muted #475569
--border #e2e8f0  --success #16a34a  --warning #d97706  --error #dc2626
```

- Colours come ONLY from this palette — never introduce new text/UI shades. Filament panel primary
  is the brand blue, set **deliberately** via `->colors(['primary' => ...])` (never Filament's
  default amber; never pure black/white — the shade ramp needs room). Button-text contrast passes AA.
- Card-based, `rounded-xl`, soft shadows, generous whitespace. **Desktop-first admin; tablet-first
  counter apps** (dispensary POS, bar POS, check-in).
- **Dark mode is a first-class requirement** — club interiors are dim and staff work in them all
  evening. Per-location accent may override the blue (prompt 03).
- Body font **Inter, self-hosted** via `@fontsource` with woff2 vendored into the repo,
  `font-display: swap`, explicit fallback stack, only the weights used. Motion ambition:
  **subtle-standard** (Motion One micro-interactions + light scroll reveals). Non-negotiables:
  above-the-fold text visible by default (never hide-until-JS), every effect gated behind
  `prefers-reduced-motion`, no LCP/scroll-perf regression.
- All buttons use shared variants — never a one-off. Reuse/consolidate into shared components; never
  near-copies. Native form controls replaced with branded components on desktop, native on touch.
- Empty states are INTENTIONAL (designed), never a broken/blank box.

## Reference implementations (imitate these — keep updated as the canon)

- Action class: `app/Actions/RecordAuditLog.php`
- Money/weight value objects + casts: `app/Support/Money.php`, `app/Support/Weight.php`,
  `app/Casts/MoneyCast.php`, `app/Casts/WeightCast.php`; one rounding rule `round_half_up()`
  (`app/Support/helpers.php`). Amount `*_cents` and weight-of-goods `*_cg` columns use the casts;
  per-gram RATE columns (`price_per_gram_cents`) and config/limit integers stay plain int. ONE
  documented carve-out (prompt 37): `Genetic::grams_per_unit_cg` is a `*_cg`-named column cast as plain
  `integer`, NOT `WeightCast` — it is a definitional per-genetic constant (the gram content of one unit,
  a config figure), not a live weight-of-goods figure, and every use is already explicit `(int)`. Do
  not "fix" it onto `WeightCast`; that would ripple through the unit-line arithmetic for no gain.
- Scope: `app/Support/ActiveScope.php` (session contract) + `app/Models/Scopes/{Organisation,Location}Scope.php`
  + traits `app/Models/Concerns/{BelongsToOrganisation,ScopedToLocation}.php`. Per-location models use
  both traits; org-wide models use `BelongsToOrganisation`; children derive scope from their parent.
- Settings accessor: `app/Support/Settings.php` (`Settings::get('key', $default)` — location → org →
  code default, never throws). `app/Support/BusinessDay.php` for day boundaries.
- Fat model with enum casts + scopes + relations: `app/Models/Member.php`, `app/Models/Dispensation.php`
  (the tender-split invariant lives in its `booted()`), `app/Models/AuditLog.php` / `Minute.php`
  (append-only / immutable-once-signed).
- Filament resource (scoped + policy): `app/Filament/Resources/Users/UserResource.php`,
  `app/Filament/Resources/Members/MemberResource.php` (form in `Schemas/`, table in `Tables/`,
  relation managers, gated by a matching `app/Policies/*Policy.php`).
- Stock: **one writer** `app/Actions/Stock/RecordStockMovement.php` (locks the batch/article row,
  signed delta, refuses negative, appends a movement — the POS and every UI action call it, nothing
  else touches stock columns). Batch selection at the counter: `app/Actions/Stock/SelectBatch.php`
  (FEFO — oldest open, non-expired, in-stock; expired refused). Transactional compliance boundary:
  `app/Actions/Dispensing/CommitDispensation.php`.
- Pricing: **one resolver** `app/Actions/Pricing/ResolvePrice.php` (tier price → best single discount →
  per-member custom; no stacking by default) returning `app/Support/PriceResult.php`; POS/PWA/reports/
  receipts all call it, and it is frozen into the dispensation line snapshot at commit.
- Livewire counter component (tablet-first, own auth route + `layouts/counter`, gated in `mount()`,
  all figures queried live): `app/Livewire/Counter/CheckInScreen.php` (door),
  `app/Livewire/Counter/TillSession.php` (till open + cash movements + BLIND close), and
  `app/Livewire/Counter/DispensaryPos.php` (member-first weight POS — a THIN shell that only resolves
  + calls the Actions; idempotency key per basket, fail-closed offline, no member ⇒ no commit).
- Void / correct (never a silent edit): `app/Actions/Dispensing/VoidDispensation.php` and
  `app/Actions/Bar/VoidOrder.php` — return stock to the originating batch/article and reverse the
  wallet (off-till); grams/cash release automatically (COMPLETED-only arithmetic). A correction is a
  void + a fresh row linked via `reversal_of_id`.
- Bar / merch (separate ledger, one drawer): `app/Actions/Bar/CommitOrder.php` writes an `Order` (own
  `items` snapshot, `SALE` unit stock, `PURCHASE` wallet spend, cash to the shared till) — articles
  only, so a genetic can never appear; bar cash stays out of `cash_contributions`. Screen
  `app/Livewire/Counter/BarPos.php`; sale-worded receipt `resources/views/receipts/bar-receipt.blade.php`.
- Expenses (till vs overhead kept apart): `app/Actions/Expenses/RecordTillExpense.php` posts a
  `PETTY_CASH` cash movement (drawer reconciles); `RecordOverhead.php` NEVER touches a till;
  `ApproveExpense.php` is a recorded approval above `Expense::requiresApproval()`. Purchases carry
  cost/gram onto the batch: `app/Actions/Purchases/RecordPurchase.php`.
- Scheduled idempotent job: `app/Console/Commands/MaterialiseRecurringExpenses.php` — a unique
  per-(template, period) marker (`RecurringExpenseRun`) makes a double-fire a no-op; wired in
  `routes/console.php`. Copy this shape for anything a scheduler/webhook can retry.
- Contribution receipt (worded aportación, never venta): `resources/views/receipts/receipt.blade.php`
  + `app/Http/Controllers/DispensationReceiptController.php` (ULID route, authorization-checked).
- Till / cash / arqueo: expected drawer cash is **derived from the ledger, never stored**
  (`app/Support/TillSummary.php`, cash-only — wallet excluded); one open session per terminal
  (`app/Actions/Till/OpenTill.php`), signed cash movements (`RecordCashMovement.php`), blind cierre
  (`CloseTill.php` — count submitted before expected revealed, note beyond tolerance), session Z-report
  (`app/Support/ZReport.php`). Read-only oversight `app/Filament/Resources/TillSessions/`.
- Member PWA (the SECOND guard): `config/auth.php` `member` guard + `Member implements Authenticatable`;
  passwordless magic link `app/Actions/MemberAuth/{IssueMemberLoginLink,ConsumeMemberLoginToken}.php`
  (hash-only, single-use, rate-limited); member-scoped controllers `app/Http/Controllers/Socio/*` (NO
  id in any URL); PWA shell `resources/views/components/layouts/socio.blade.php` + `public/sw.js`
  (offline QR card, /socio-only caching). Guests on `/socio*` → member login via `redirectGuestsTo`.
- Web Push: `Member` uses `HasPushSubscriptions` + `push_opt_outs` (per-channel opt-out via
  `wantsPush()`); notifications in `app/Notifications/*` extend a VAPID-gated base; VAPID private key is
  server-only (`config/webpush.php`).
- Spreadsheet export/import: [App\Support\Spreadsheet\ReportExport.php — CSV (league/csv) + PDF (dompdf)]
- PDF document: [App\Actions\Documents\* — prompt 16]
(Add the first real example of each remaining pattern here as it's built; future work copies these.)

## Testing & verification rules (non-negotiable)

- `composer check` (Pint --test → Larastan → full suite) must pass before EVERY commit. Never commit
  red. Never skip/delete a failing test to pass.
- Every feature ships with tests. Mock external services (Resend, Sentry, push) — tests never hit
  real APIs. After each feature, run the FULL suite (regression), not just new tests.
- Every mailable/notification joins a permanent render test AND the `/dev/mail` preview. Embed the
  mailer logo inline via CID (PNG, not SVG/WebP), never a hot-linked `asset()` URL.
- **Money paths get an end-to-end test asserting the REAL stored amount** (e.g. €12.50 entered →
  1250 cents stored/ledgered). **Weight paths assert centigrams** (3.5 g → 350 cg). Floats forbidden.
- **Authorization: write the denial tests.** Every policy/scope has a test that a wrong-location or
  wrong-role actor is BLOCKED, not just that the right one is allowed.
- Local test DB is SQLite in-memory (fast); a MySQL profile (`phpunit.mysql.xml`) runs in CI because
  production is MySQL — SQLite-only testing hides driver-difference bugs (JSON, strict types,
  booleans, string lengths). Verify money/weight/JSON on both.
- UI verified by LOOKING (Playwright screenshots) across **1440 / 1280 / 1024 / 390 and a short
  laptop height**, light AND dark, motion reduced AND allowed — never just two widths. Non-visual
  changes get no screenshot ceremony. Motion-gated UI screenshotted with motion both reduced and
  allowed (reduced-motion resets hide gated-visibility bugs).
- Migrations that touch existing data are tested against a seeded copy, not just a fresh DB.
- **Fixtures and seeds go through the domain action that owns the write — never hand-build a persisted
  shape a writer owns.** A seeder/factory that assembles a row by hand drifts from the real writer the
  moment either side changes, and the drift is INVISIBLE: a green test and a working-looking screen
  both certify a shape production never produces. This actually shipped — `DemoDataSeeder` wrote order
  `items` as `{name, qty, price_cents}` while `CommitOrder` writes `{article_id, unit_price_cents,
  line_total_cents}`, so the Bar sales report read €0,00 against 100+ seeded units and a hand-built
  test "passed" while disagreeing with both. Route the seed through the Action (`CommitOrder`,
  `CommitDispensation`, `RecordStockMovement`, …); reserve a raw `Model::create` for shapes no Action
  owns. Carve-out: a compliance-boundary writer that would reject demo data (e.g. `CommitDispensation`
  gating on fees/carencia/limits) may stay relational-with-full-snapshot IF every column the real
  writer sets is populated — but say so in `DECISIONS.md`, because it is exactly the drift risk above.
- Idempotency: anything triggered by schedulers/webhooks must not double-fire (tested under retry).
- **Tests prove CORRECTNESS, not COMPLETENESS.** No test catches a feature quietly shipped as a
  placeholder/stub. Periodically run the completeness check (grep TODO/FIXME/placeholder; walk pages
  asking "real or inert?"). **Verify state against code/git, not claims** — check commits/files/
  packages before trusting work is done.

## Workflow

- One prompt = one branch off latest main. `git checkout main && git pull` IMMEDIATELY before
  branching. **One prompt = one branch = one focused task** — never concatenate prompts.
- **Checkpoints are for ARCHITECTURE decisions, not visual taste.** Propose-then-pause when the
  choice is structural (data model, schema, wiring). For visual work: BUILD with tight constraints
  (reuse components, match a reference), then let the human LOOK and correct on the real output.
- Conventional commits, small and atomic. Log every judgment call in `DECISIONS.md`.
- Pixel/behaviour-preserving refactors: pin with a test FIRST, then refactor, then prove green.
- Prohibited without explicit human action: storing card details, irreversible destructive ops.
- Normal rule: push the branch, do NOT self-merge — a human reviews and merges. (The overnight
  autonomous run is an explicit, logged exception — see DECISIONS.md.)

## The craft skills & audits

The `skills/` folder (frontend-design, admin-design, laravel-craft, web-app-security) are the
transferable craft references — keep active while building so code lands close-to-right. This
CLAUDE.md and `DECISIONS.md` hold THIS project's concrete decisions and win where they differ. The
`audits/` folder are periodic VERIFY passes (design, a11y, admin, code, security — no SEO, no public
site) run on demand: skills make it right as you build, audits confirm nothing slipped.
