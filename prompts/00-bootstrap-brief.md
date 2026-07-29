# 00 — Bootstrap brief (use WITH the kit's `bootstrap.md`)

**Not a standalone prompt.** Run the starter kit's `bootstrap.md` (at **`/Sites/starter-kit`**)
exactly as written, choose the **Full app** profile, and feed it the facts below so they land in
`CLAUDE.md`, `.env.example`, `SETUP.md` and `DECISIONS.md`. Foundation only — no features.

> Two differences from the kit's default Full-app assumptions:
> 1. **No payment provider.** Skip the Stripe/Cashier install and the webhook scaffolding. Money is
>    still handled (cash + member wallet, integer cents) so the money-in-minor-units discipline and
>    money e2e tests still apply — there's just no external charge. A payment layer can be added on
>    top of the ledger later.
> 2. **No public site.** This app has no marketing pages, no public menu and no SEO surface (see
>    below). Skip the public-site scaffolding and the SEO parts of the kit; keep the admin, auth and
>    the Livewire layer.

## Project facts to fold into CLAUDE.md

**What this is:** a management platform for a Spanish non-profit **cannabis social club**
(*asociación cannábica* / CSC) operating from **multiple premises**. It is a private, members-only
association. Cannabis is dispensed by weight to registered adult members at cost, as a **shared-cost
contribution (*aportación*)**, never a sale. Each location runs as its own club (own members,
stock, cash); the **owner** sees an org-wide rollup. Built single-organisation but **keyed for
future multi-organisation SaaS**.

**Stack** (kit defaults, minus payments): Laravel (latest), Blade + Livewire, Filament admin,
Tailwind, Alpine + Motion One, Resend, Redis + Horizon, **MySQL in production** (SQLite fine for
local dev), tests also on MySQL in CI. No Stripe.

**Install these at bootstrap** — every one is a hard blocker for a later prompt, and discovering
that mid-build is a wasted branch:
- **QR generation** (e.g. `simplesoftwareio/simple-qrcode` or `bacon/bacon-qr-code`) — member cards,
  prompt 04.
- **PDF rendering** (e.g. `spatie/laravel-pdf` + Browsershot, or dompdf) — entry–exit sheets,
  Z-reports, the libro de socios and the actas, prompts 09/10/16.
- **Spreadsheet export** (e.g. `openspout/openspout` or `maatwebsite/excel`) — every report export,
  prompt 14, and the member CSV import, prompt 04.
- **Web Push** (e.g. `laravel-notification-channels/webpush`) — member notifications, prompt 15.
- **Image handling** (Intervention or Spatie media conversions) — crop-at-upload, WebP, metadata
  stripping, prompts 04/07.

Add a smoke test per library at bootstrap (render one PDF, one XLSX, one QR PNG) so a broken or
missing binary — Browsershot's Chromium especially — surfaces on day one, not on prompt 16.

**Surfaces (record in DECISIONS.md):**
- **Admin** — Filament panel for owner / managers / staff. **Mounted at `/`, not `/admin`** — there
  is no public site to occupy the root, and staff should land on the dashboard with no extra click.
  (Prompt 13 builds the dashboard; the bootstrap just needs the panel path to be `/`.)
- **Counter apps** — Livewire touch interfaces on their own routes (tablet-first), staff-
  authenticated, PIN-unlockable: dispensary POS, bar POS, and check-in.
- **Member PWA** — a separate member guard, built in prompt 15. Scaffold the auth layer so a second
  guard is clean to add; do not build member auth now.

**No public / marketing surface at all.** Spanish CSCs may not advertise. There must be no public
landing page, no public menu, no product pages, no sitemap, and no search-indexable route. Add
`X-Robots-Tag: noindex` globally and a `robots.txt` that disallows everything. Record this in
DECISIONS.md as a **legal constraint, not a preference**.

**Scope model (checkpoint in prompt 01 — just note intent here):**
- Every domain table carries `organisation_id` (one seeded org now).
- **Location is the working scope.** Staff work within assigned location(s); the owner can switch to
  "All locations". Implemented as a custom location switcher + global scope, **not** Filament's
  built-in tenancy (the owner rollup and org-wide member search must cross locations).

**Identifiers:** **UUID (or ULID) primary keys on every user-addressable model** — members,
memberships, dispensations, orders, batches, documents. No sequential integers in any route, API
response, filename or QR payload. This is a security requirement; see `NOTES` section B.

**Money:** integer **cents (EUR)** everywhere; euros only at the Filament input/display edge via a
cast. **Weight:** integer **centigrams** (1 g = 100 cg, 0.01 g precision) everywhere; grams to 2 dp
only at the edge, via a matching cast. Floats in either are a bug.

**Language:** the app ships **multilingual (Spanish default, English second)**; scaffold Laravel
localisation with `es` as the fallback locale from day one so strings aren't retrofitted later.
Domain vocabulary in the UI: *socio*, *aportación*, *dispensación*, *aval*, *carencia*, *arqueo*.

## Design rules for CLAUDE.md

```
--brand #2563eb  --brand-dark #1d4ed8  --brand-tint #eff6ff
--surface #ffffff  --surface-alt #f8fafc  --text #0f172a  --text-muted #475569
--border #e2e8f0  --success #16a34a  --warning #d97706  --error #dc2626
```

Filament panel primary → the brand blue, set **deliberately** via `->colors(['primary' => ...])`
(never ship on Filament's default amber; never pure black/white — the shade ramp needs room).
Button-text contrast must pass AA. Card-based, `rounded-xl`, soft shadows, generous whitespace.
Desktop-first admin; counter apps are tablet-first. **Dark mode is in scope** — club interiors are
dim and staff work in them all evening. Per-location accent may override the blue (prompt 03).

Body font **Inter, self-hosted** via `@fontsource` with the woff2 vendored into the repo,
`font-display: swap`, explicit fallback stack, only the weights actually used. Motion ambition:
**subtle-standard**.

## `.env.example` (placeholders only)

Kit set minus Stripe: `RESEND_KEY`, `MAIL_FROM_ADDRESS`, Redis vars, MySQL prod DB vars, `AWS_*` +
`AWS_BUCKET` + `FILESYSTEM_DISK`, `SENTRY_LARAVEL_DSN`, `APP_URL`, `APP_LOCALE=es`,
`SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`. Add a **separate private disk** for ID documents
and member photos (encrypted, non-public, signed-URL access only) — do not reuse the general
uploads disk.

> **Local-dev note to put in SETUP.md:** `SESSION_SECURE_COOKIE=true` must be `false` (or unset) for
> local `http://` development, or login silently fails — the session cookie is never sent and the
> user is bounced back to the form with no error. Same for `APP_URL` matching the scheme in use.
> Run `php artisan config:clear` after any `.env` change.

## Seeded dev credentials (pin these — do not invent per-run)

The local-only seeder must create exactly these, and `SETUP.md` must document them:

| Role | Email | Password | POS PIN |
|---|---|---|---|
| Owner | `owner@club.test` | `password` | `1234` |
| Manager | `manager@club.test` | `password` | `2345` |
| Staff | `staff@club.test` | `password` | `3456` |

All three seeded **email-verified** and with their role assigned, so they pass the panel gate
immediately. Guard the seeder behind `app()->environment('local')` and never run it in production.

## Finish

Kit's bootstrap finish: `composer check` green, Horizon + `/dev/mail` work locally, `CLAUDE.md`
complete, audits/gates/skills/ui-passes/checklist copied in, `BOOTSTRAP-SUMMARY` written. Confirm
you can log in as `owner@club.test`. Then stop — first feature is prompt 01.
