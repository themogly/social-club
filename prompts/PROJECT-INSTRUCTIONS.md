# Cannabis Social Club platform — Claude Project instructions

Paste this into the **Custom instructions** box of a new Claude Project named **"CSC platform"**,
and upload every `.md` file in this folder as **project knowledge**.

---

## What this project is

A complete management platform for a Spanish non-profit **cannabis social club** (*asociación
cannábica* / CSC) operating from **multiple premises**. It is a private, members-only association.
Cannabis is dispensed **by weight** to registered adult members at cost, as a **shared-cost
contribution (*aportación*)** — never a sale. Each location runs as its own club; the **owner** sees
an org-wide rollup. Built single-organisation but keyed for future multi-organisation SaaS.

**Stack:** Laravel (latest), Blade + Livewire, Filament admin, Tailwind, Alpine + Motion One, Resend,
Redis + Horizon, MySQL in production. **No Stripe / no payment provider** — cash + member wallet only.

**Surfaces:** the Filament panel is mounted at **`/`** and lands on the dashboard. Livewire counter
apps (dispensary POS, bar POS, check-in) on their own tablet-first routes, PIN-identified operator.
A member **PWA** on a separate guard. **There is no public website** — Spanish CSCs may not
advertise, so there is no landing page, no public menu, and no search-indexable route. That is a
legal constraint, not a preference.

## How I want you to work

- The project knowledge holds a **bootstrap brief (00)** and **phased prompts (01–18)**, plus
  `README.md`, `SETUP-step-by-step.md`, and `NOTES-decisions-and-compliance.md`.
  **Read `NOTES-decisions-and-compliance.md` and `README.md` first.**
- **One prompt = one branch = one focused task.** Never two at once. Order: **A** bootstrap →
  **B** features 01→17 in strict order (18 optional) → **C** quality passes → **D** launch gate.
- Read `CLAUDE.md` + `DECISIONS.md` in the repo before each task; CLAUDE.md wins over any skill.
- **Checkpoints are for architecture, not visual taste.** The prompt-01 checkpoint locks scope, identifiers, the
  money/weight wiring, the wallet shape, the pricing shape and the business-day cutoff — don't wave it through. For visual work, build it with
  tight constraints and let me look at the real output.
- **No repository pattern.** Fat models + single-purpose `App\Actions`; thin controllers; page data
  in `App\ViewModels`. No service layer by reflex. Simplest solution wins.
- Ship tests with every feature; run the full suite after each; `composer check` green before every
  commit. Never commit red; never delete a failing test to pass.
- Push the branch, **do not merge** — I review and merge.

## Non-negotiables

- **Money is integer cents (EUR). Weight is integer centigrams (0.01 g).** Euros and grams only at
  the input/display edge, via casts. A float in either is a bug. One shared `round_half_up`.
- **UUID/ULID primary keys on everything user-addressable.** No sequential integers in any route,
  API response, filename or QR payload. A competitor in this exact market leaked ~1M member records
  and ~1M ID scans through sequential-id IDOR — see NOTES section B.
- **Scope every query to organisation + active location.** Cross-location access is deliberate and
  permissioned. Write the denial tests.
- **Compliance blocks, it doesn't just document.** Daily and monthly gram limits are enforced inside
  the same transaction as the stock movement. Overrides are permissioned, reasoned and logged.
- **Never cache transactional data** — takings, stock, balances, occupancy and limits are queried live.
- **ID documents and member photos** are encrypted, on a private disk, behind short-lived signed
  URLs, access-logged on every view.
- **Every threshold is a setting**, never a constant. Spanish practice varies by region and the case
  law moves.
- Vocabulary: *socio*, *aportación*, *dispensación*, *superávit*. Never *cliente*, *venta*, *beneficio*.

## Design

```
--brand #2563eb  --brand-dark #1d4ed8  --brand-tint #eff6ff
--surface #ffffff  --surface-alt #f8fafc  --text #0f172a  --text-muted #475569
--border #e2e8f0  --success #16a34a  --warning #d97706  --error #dc2626
```

Filament primary set deliberately to the brand blue (never the default amber, never pure black or
white). Card-based, `rounded-xl`, generous whitespace. Inter, self-hosted, woff2 vendored.
Desktop-first admin; tablet-first counter apps. **Dark mode is a first-class requirement** — this is
a dim room at 10pm. Layout-affecting changes are screenshotted at 1440 / 1280 / 1024 / 390 and a
short laptop height, light and dark, motion reduced and allowed.

## Seeded dev credentials (pinned — same every rebuild)

| Role | Email | Password | PIN |
|---|---|---|---|
| Owner | `owner@club.test` | `password` | 1234 |
| Manager | `manager@club.test` | `password` | 2345 |
| Staff | `staff@club.test` | `password` | 3456 |

All seeded email-verified with roles assigned, behind a `local` environment guard.

## Honesty rule

Spanish CSCs operate under judicial tolerance, not authorisation, and the software's job is to make
the club *able* to evidence what it did — not to imply that recording an obligation discharges it.
Where the law is unsettled or regionally variable, say so and make it configurable. Nothing this
project produces is legal advice, and the UI must not imply otherwise.
