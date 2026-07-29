# BOOTSTRAP-SUMMARY

Foundation for the CSC platform (Full-app profile, no payment provider, no public site). No feature
work — that begins at prompt 01. `composer check` is green on this skeleton.

## What is in place

**Standards & gate**
- `CLAUDE.md` — the working agreement (architecture, security, compliance, money/weight, i18n,
  design, testing, workflow).
- `composer check` = Pint (style) → Larastan level 6 (`--memory-limit=2G`) → full PHPUnit suite.
  Proven to fail red on a broken file and pass clean.
- `pint.json` (Laravel preset, `declare_strict_types` off), `phpstan.neon` (level 6 on `app/`).

**Stack installed** (Laravel 13, PHP 8.5): Filament v5 panel, Livewire v4, Horizon + predis,
dompdf (PDF), chillerlan/php-qrcode (QR PNG via gd), openspout (XLSX/CSV), Intervention Image v4
(gd), web-push, Sentry. Library choices and why in `DECISIONS.md`.

**App foundation**
- Filament admin panel mounted at **`/`**, brand-blue primary (`#2563eb`), brand name from config.
- `User implements FilamentUser` with a `canAccessPanel()` gate (verified staff; members are a
  separate guard, built in prompt 15).
- `DevAdminSeeder` — local-only owner/manager/staff logins (see SETUP.md), guarded off production.
- **Money/weight discipline:** `App\Support\Money` (integer cents) + `App\Support\Weight` (integer
  centigrams) value objects, `App\Casts\MoneyCast` / `WeightCast`, one shared `round_half_up()`
  helper. Euros/grams only at the edge.
- **i18n:** Spanish default + fallback (`es`), English second (`lang/en.json`); all UI copy via `__()`.
- **Security baseline:** `SecurityHeaders` middleware (global `X-Robots-Tag: noindex` + nosniff +
  frame + referrer policy), `robots.txt` disallow-all, private `documents` disk for ID scans/photos.
- **Caching reference:** `App\Support\SiteContent` (caches plain arrays, reads with fallback, flush).
- **Mail:** reference mailable with CID-embedded PNG logo, `DevMail` preview registry, `/dev/mail`
  (local-only), permanent `MailRenderTest`.
- `App\Actions`, `App\ViewModels`, `App\Support` conventions established.

**Testing/verification scaffolding**
- SQLite in-memory suite + runnable **MySQL parity profile** (`phpunit.mysql.xml`, verified green).
- 17 tests / 50 assertions green: money/weight e2e, panel access + denial, mail render, dev-route
  gating, security headers, cached-gateway, and **library smoke tests** (real PDF, XLSX, QR-PNG, WebP).
- Copied verbatim from the kit: `audits/` (design, a11y, admin, code-style, security, seo, README),
  `gates/` (completeness, cms-field-usage, pre-staging, README), `skills/` (frontend-design,
  admin-design, laravel-craft, web-app-security), `ui-passes/` (01–04 + README),
  `verification/CHECKLIST.md`.
- `SETUP.md` (env, local run, testing, deploy sequence), `DECISIONS.md` (architecture + library +
  overnight-run notes).

## Verified

`composer check` green · seeded `owner@club.test` reaches the dashboard · `/` → 302 `/login` with
security headers · `/login` renders the Filament form · `robots.txt` disallows all · `/dev/mail`
works locally · frontend builds with self-hosted Inter · suite passes on both SQLite and MySQL.

## First feature session — prompt 01 (schema + scope)

1. `git checkout main && git pull && git checkout -b feat/schema-and-scope`.
2. Read `CLAUDE.md` + `DECISIONS.md` first (CLAUDE.md wins over any skill).
3. Build the whole schema with **ULID keys** on every user-addressable model, `organisation_id` +
   active-location scope, the `Settings` and `BusinessDay` foundations, applying `MoneyCast` /
   `WeightCast` to money/weight columns. This is the architecture checkpoint that matters.
4. Ship tests (including scope **denial** tests). `composer check` green before commit.
5. Add the first real reference implementations to CLAUDE.md's reference section as they are built.
