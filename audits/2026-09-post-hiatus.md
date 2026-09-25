# Post-hiatus close-out audit — 2026-09

**Run at:** `098636d` (main, immediately after prompt 239). **Report only — this branch changes NO production
code** (prompt 240). It is pushed for review and left unmerged; every finding that needs a fix is called out as
its own follow-up branch, not applied here.

**Environment note:** run locally on SQLite with the app dependencies installed. `composer audit` reached its
advisory data; `composer outdated` / `npm outdated` need network access this sandbox does not have, so
"is a newer version available" is answered from the advisory feed and the lockfile, not a live version diff.
MySQL parity is CI's job and was not run here (per the standing working rule).

---

## The finding, in one line

**The build is sound.** The launch gate is green (Pint clean, Larastan 0, **1883 tests, 1880 passing, 3
environment-gated skips**), there are no orphaned Actions/notifications/permissions, the two locale files are at
exact key parity, and the measurement harnesses are measuring real numbers. The **one** thing that needs
action is routine dependency maintenance: **7 published security advisories affect 3 installed packages**, all
cleared by a version bump. Nothing in the application's own code is implicated.

---

## Sweep 1 — dependencies & tooling

Runtime: **PHP 8.5.6**, **Laravel 13.23.0**, **Filament v5.7.4**, **Livewire v4.3.3**.

`composer audit` reports **7 advisories across 3 packages**. All are upstream, all have a fixed version:

| Package | Installed | Severity | Advisory | Fixed in |
|---|---|---|---|---|
| `filament/filament` | v5.7.4 | medium | MFA (app) codes reusable after a newer code is used (CVE-2026-84306) | ≥ 5.7.6 |
| `filament/filament` | v5.7.4 | low | Password-validity disclosure for panel-denied accounts on login (CVE-2026-84307) | ≥ 5.7.5 |
| `livewire/livewire` | v4.3.3 | medium | DOM-based XSS during client-side state handling (CVE-2026-81887) | > 4.3.3 |
| `league/commonmark` | (transitive) | high | DoS via distinctly-named attributes, Attributes extension | ≥ 2.10.0 |
| `league/commonmark` | (transitive) | high | DoS in the SmartPunct/Attributes extensions | ≥ 2.9.1 |

(The count is 7 because two of the packages carry more than one advisory line.) None is reachable in a way that
is worse for this app than for any Filament/Livewire install: MFA is available-but-optional here; the login
disclosure needs an attacker probing denied accounts; the commonmark DoS needs untrusted Markdown, and this
club renders no member-supplied Markdown. Still real, still trivially fixed.

**Follow-up (NOT done here):** a `chore(deps): security bump` branch — `composer update filament/filament
livewire/livewire league/commonmark`, then `composer check` and a screenshot pass on the panel + counter,
because a Filament minor can move admin markup. Report-only prompt, so it is named, not applied.

Tooling itself is current and healthy: Pint and Larastan (L6) both pass in the gate below.

## Sweep 2 — orphans & dead code

- **`UnreachableCodeGuardTest` — green.** Every `app/Actions` class (80 of them), every notification (7), and
  every declared permission is referenced from a non-test caller. The single most-repeated historical defect
  here — a complete, tested, permissioned Action with nothing that calls it — is mechanically absent.
- **No `TODO`/`FIXME`/`HACK`/`XXX`** in `app/` or `resources/views/` (0, case-sensitive; the case-insensitive
  hits are all the Spanish word *todo* = "all", in real copy).
- No `dd(`/`dump(` left in application code or views.

## Sweep 3 — the instruments measure real numbers

The recurring lesson of this programme has been harnesses that pass while measuring the wrong thing. The guards
that exist to stop that are present and healthy:

- **Font (prompt 233):** `tests/Browser/font-ready.mjs` exports `assertRealFont(page, …)`, which fails the
  snapshot if the page fell back off self-hosted Inter — the exact trap where a mac fallback happened to match
  and a Linux fallback silently didn't, and neither machine measured Inter.
- **Viewport height (prompt 237):** `CounterShellUsesStableViewportHeightTest` forbids `100vh`/`h-screen`/`lvh`
  on the counter shells, because a headless browser has no URL bar and so cannot SEE that bug — it must be
  caught structurally, and now is.
- **Real CSS, not a stub (prompt 176):** the screenshot harnesses inline the actually-built `app-*.css` via the
  `InlinesBuiltCss` trait, so a capture reflects the real cascade rather than an approximation, and they glob
  `app-*.css` only (never the Filament panel theme, which would corrupt the counter cascade).
- **x-cloak / hidden controls (228/231):** the harness assertions read post-render DOM, not source markup, so a
  control hidden behind `x-cloak` is not counted as present.

No instrument was found asserting a number it does not actually measure. The one standing caveat, already
documented in `shoot-pin-lockouts.mjs`, is correct: the Filament panel ships its own font stack, so the Inter
assertion is deliberately NOT applied to panel screenshots.

## Sweep 4 — matrices & config drift

All the drift guards pass (run together: **60 tests, green**):

- **Settings coverage** (`SettingsCoverageAuditTest`) — every configurable threshold has an accessor default
  and a form control; no setting is read that the admin cannot set.
- **Form completeness** (`FormCompletenessTest`) — every fillable field is either on its resource form or in the
  documented allowlist with a reason. (Prompt 238 removed `location_id` from the Batch allowlist as it joined
  the form — the allowlist is honest.)
- **Localisation parity** (`tests/Feature/Localization`) — **es.json and en.json both hold 2557 keys**, exact
  parity, no key that leaks Spanish into the English UI.
- **Permission matrix sync** (`PermissionsSyncOnDeployTest`) — the code-declared matrix is the source of truth
  and the deploy sync stays consistent with it.
- **Screenshot matrix** (`design-sweep.mjs`) — spans 1440 / 1280 / 1024 / a short laptop / 390, and since
  prompt 237 a real mobile DEVICE profile (390×664, touch, isMobile).

## Sweep 5 — the launch gate, mechanically

`composer check` (Pint --test → Larastan L6 → full suite):

- **Pint:** clean.
- **Larastan:** 0 errors.
- **PHPUnit:** 1883 tests, **1880 passing, 3 skipped, 0 failing**. The skips are environment-gated, not red:
  concurrency row-locking (needs MySQL; asserted in CI), and a couple gated on `npm run build` / a seeded dev
  database being present. None is a masked failure.
- **Migrations:** 58 files. `migrate:status` on the local dev SQLite shows the most recent
  (`2026_08_26_000000_consent_record_signature`) as **Pending** — a local-DB state only: it runs clean under
  `RefreshDatabase` in the suite and on a fresh database, so it is a `php artisan migrate` on the dev box, not a
  code defect. Flagged so it is not mistaken for done.

No public/marketing surface exists (legal constraint); nothing in this sweep contradicts that.

---

## What to do

1. **Dependency security bump** — the only real action. Its own `chore(deps)` branch: bump Filament (≥ 5.7.6),
   Livewire (> 4.3.3) and league/commonmark (≥ 2.10.0), run the gate, and screenshot the panel + counter
   because a Filament minor can move admin markup. Not applied here (240 is report-only).
2. **`php artisan migrate` on the dev box** — clear the one pending local migration. Housekeeping, not a fix.

Everything else is clean. On this codebase a short list is the right result, not a thin one: the guards that
would have caught orphans, drift, dead instruments and missing translations were all run and all held.
