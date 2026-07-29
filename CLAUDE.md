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

Ships **multilingual: Spanish default, English second.** `APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`.
All user-facing strings go through `__()` / lang files from day one — never hardcode UI copy. Domain
vocabulary above is Spanish even in the English locale where it is a term of art.

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

- Action class: [App\Actions\Example — add first real one]
- View model / page class: [App\ViewModels\Example]
- Filament resource (scoped + policy): [add first real one]
- Livewire counter component: [add first real one]
- Money/weight cast usage: `app/Casts/MoneyCast.php`, `app/Casts/WeightCast.php`
- Spreadsheet export/import: [App\Support\Spreadsheet\* — add first real one]
- PDF document: [App\Actions\Documents\* — add first real one]
(Add the first real example of each pattern here as it's built; future work copies these.)

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
