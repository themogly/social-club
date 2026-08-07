# Accessibility audit — CSC platform

**Branch** `a11y/audit-pass` · **from** `9ef638c` (main, after prompt 194) · **date** 2026-08-07

## How this was run

Two passes, and the second is the one that counts.

**Automated.** `tests/Browser/axe-sweep.mjs` — axe-core (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`,
`best-practice`) over 28 page states × 1440 and 1024 × light and dark, authenticated as the seeded owner.
The member PWA was swept separately at 390px against a minted magic-link session, because it lives behind
its own guard and would otherwise have been audited only at its login screen.

**The sweep had to be fixed twice before it measured anything real**, and both failures are worth recording
because they are the shape a green audit takes when it is wrong:

- `/login` was audited while signed in, so it redirected to `/` and the dashboard was audited twice while
  the login screen was never audited once. Signed-out pages now get a clean browser context.
- All six counter screens reported 18 controls and no violations. That was prompt 175's chain: an owner with
  two sedes lands on the **sede chooser**, so the sweep photographed the same blocking state six times. It
  now chooses a sede, enters a PIN and drives the member lookup, and the working screens — where the density
  actually is — appear: the POS went from 18 controls to 51.

Every render now records what it landed on (URL, title, h1, control count, blocker state) and that table is
printed with the results, so "no violations" can never again mean "nothing was audited".

**By hand.** Every automated finding verified in the browser before it was written down, plus the checks axe
cannot make: landmark and heading structure, page titles, form-label association, keyboard reachability,
focus-indicator replacement, live regions, and error association. One axe finding was **dismissed** on
inspection (below), and several of the findings below are hand-only.

Raw output: `storage/app/axe-sweep.json`.

## Summary

| | count |
|---|---|
| PHASE 1 — blockers | 7 |
| PHASE 2 — important | 6 |
| PHASE 3 — polish | 2 |
| Dismissed on inspection | 3 |

The product is in better shape than the raw axe count suggests. Prompt 98 already did a per-scheme WCAG pass
on the semantic tokens, so `success` / `warning` / `error` are correct in both schemes; there is no bare
`focus:outline-none` anywhere (every one is paired with a replacement ring); every page has exactly one
`<main>`; `lang` is set and correct (the PWA renders `lang="es"` for a Spanish member); no image is missing
`alt`; and the counter's 44px touch floor holds. What is left is concentrated in three places: **the Filament
primary shade ramp**, **muted text in dark mode**, and **the dashboard's heading structure**.

---

### PHASE 1 — Blockers (fails WCAG AA / breaks assistive tech)

- **The panel's primary button fails AA, in the one place `CLAUDE.md` says it must not**: `AdminPanelProvider`
  sets `'primary' => Color::hex('#2563eb')`, but `Color::hex()` generates a whole ramp and its **600 step is
  not the colour given** — it resolves to `oklch(0.5978 …)` ≈ `#477ae3`, and white on that is **4.06:1**. →
  Pin the ramp so 600 is the brand blue (`#2563eb`, white on it 5.12:1) or darker. → Every primary action in
  the admin panel, the `Entrar` login button and the locale switcher's active state are below AA. CLAUDE.md's
  design rules name this exactly: *"Button-text contrast passes AA."* The intent was written down; the
  generated ramp quietly defeated it.

- **`--color-ink-muted` has no dark-scheme value**, so any control that uses `text-ink-muted` without also
  carrying a `dark:` override renders at **2.35:1** on a dark counter. Two real controls do: `Cerrar sesión`
  on the counter home (`counter-home.blade.php:103`) and `Borrar` on the PIN pad
  (`partials/counter-surface.blade.php:158`). → Give the token a dark value in `tokens.css` beside the
  prompt-98 semantic set, so a missing override degrades to readable instead of invisible. → Club interiors
  are dim and dark mode is a first-class requirement here; these are the two controls a staff member reaches
  for at the end of a shift.

- **`dark:text-slate-500` is below AA wherever it carries text** — `#62748e` is **3.74:1** on `slate-900` and
  **4.23:1** on `slate-950`, against a 4.5:1 requirement. 30 occurrences across 11 view files. →
  `dark:text-slate-400` (`#90a1b9`) clears it at ~6.5:1 with no visual re-design. → This is the default
  "secondary text" treatment across the counter, so it is most of the muted copy an operator reads in the dark.

- **`opacity-80` on token-coloured text undoes the prompt-98 contrast pass**: the verdict remedy line
  (*"Renew their fee from their record…"*) computes to `#c64545` on `#f8e8e8` = **4.07:1**, where the
  underlying `--color-error` was deliberately darkened to `#b91c1c` (5.24:1) precisely to pass. → Drop the
  opacity and use the token as-is (or a genuinely lighter token). → An opacity modifier on a token silently
  re-breaks a fix that was made once, deliberately, and there is no test that would catch it.

- **The member card's sub-label fails AA**: `text-white/80` on the brand blue = **3.89:1** on `/socio`. →
  `text-white` (or `text-white/90`). → It is the label on the QR card, the single most-used screen in the
  member PWA.

- **The dashboard alert strip bypasses the token pass**: `.csc-alert-warning` uses its own `#b45309` on
  `#ffedd5` = **4.38:1**, rather than `--color-warning` (`#92400e`, 5.80:1). → Use the semantic tokens in
  `dashboard-styles.blade.php`. → It is the panel that says *"1 till open and unreconciled"* — an alert
  nobody should have to squint at.

- **Ten unlabelled links per table page**: Filament renders `<a class="fi-ta-col">` for a clickable cell even
  when the cell is EMPTY, so `members` (the `kind` column) and `batches` (`expires_on`) each put ten links
  with no accessible text into the tab order. → Give those columns a `->placeholder()` so the link has text,
  or stop making an empty cell a link. → A screen-reader user hears ten consecutive "link" announcements with
  nothing to distinguish them, on the two tables staff use most.

**Review:** all seven are real and verified in the browser. Five are one-line or token-level changes that land
everywhere at once; the table-link one is per-column and touches two resources.

---

### PHASE 2 — Important

- **All six counter screens share one `<title>`: "Counter · CSC platform"** — Recepción, Dispensario, Barra,
  Caja and Socios are indistinguishable in the tab bar, in browser history and to a screen reader announcing
  the page. → Each full-page Livewire counter component should pass its own title into the layout (the layout
  already accepts `$title`; only the components never set it). → WCAG 2.4.2. The counter is the one surface
  routinely open in several tabs on one tablet.

- **The dashboard renders every chart heading twice, in the wrong order.** `<x-dashboard.section :title="…">`
  emits an `<h3>` and the Filament chart widget inside it emits its own `<h2>` with the identical text, giving
  `h1 → h3 → h2 → h3 → h2 …`. → Suppress the widget's heading and promote `csc-section-title` to `<h2>`, so
  the sequence becomes `h1 → h2` and each section is announced once. → Heading navigation is how a screen-
  reader user skims a dashboard; today every section is announced twice and the level order is invalid. Same
  defect on `/informes/financiero` and `/informes/consumo`.

- **`/counter/members` has three `<h1>`s** — the layout's *"Mostrador"* plus *"Alta de socio/a"* (line 55) and
  *"Cobro de cuota"* (line 154). → Both panel headings are sections of the page, not the page: make them
  `<h2>`. → One `<h1>` per page; three tells assistive tech the page restarts twice.

- **`/socio` has no `<h1>` at all** (`page-has-heading-one`). → Give the carné page a heading, visually hidden
  if the design does not want one on screen. → It is the PWA's home screen.

- **The bar POS's `Efectivo entregado` field has no label** — a placeholder and a nearby `<p>`, neither of
  which is programmatically associated (`bar-pos.blade.php`). Every other money field on the counter has a
  real `<label for>`. → Add one. → A placeholder disappears on focus and is not a label; this is the field
  that decides how much cash goes in the drawer.

- **Form errors are not associated with their fields.** `socio/login.blade.php` renders `@error('email')` as
  a loose paragraph, and `socio/application.blade.php` — the longest form in the product, filled in by an
  applicant on a handed-over tablet — has **zero** `aria-invalid` / `aria-describedby`. → Wire errors to
  their inputs. → A screen-reader user tabbing to an invalid field is told nothing is wrong with it.

**Review:** none of these break a flow for a sighted mouse user, which is why they survived; all six are
squarely WCAG A/AA and all are small, contained changes.

---

### PHASE 3 — Polish

- **The counter's full-screen overlays do not trap focus.** `[data-counter-surface]` (the PIN / handover
  surface) and the camera-scan overlay are `role="dialog" aria-modal="true"` and cover the viewport, but the
  page behind stays in the tab order, so Tab walks into content the operator cannot see. → Trap focus while
  open and restore it on close. → `aria-modal` tells assistive tech the rest is inert; today that is a promise
  the markup does not keep.

- **No skip-to-content link on the counter layout** (the Filament panel has one). → Add one to
  `components/layouts/counter.blade.php`. → The counter top bar is ~7 controls to tab past on every screen.

**Review:** both are real but neither blocks a task today.

---

### Dismissed on inspection

- **`color-contrast` on the genetics tile's `Stock: …` label** (axe: `#90a1b9` on `#697080`, 1.88:1). Measured
  in the browser in dark mode, the computed pair is `oklch(0.704 …)` on `oklch(0.129 …)` — slate-400 on
  slate-950, roughly 7:1. axe resolved a transitional background. **Not a defect.**
- **`empty-table-header`** on six Filament tables: the actions header cell is `<th aria-label="Acciones">`
  with no visible text. It is a *best-practice* rule, the cell is correctly named for assistive tech, and it
  is vendor markup. **Deferred, not a defect.**
- **`div[x-on:click]` × 11 on every panel page**: all Filament's own sidebar internals (close overlay,
  collapsible group toggles), not this codebase. **Vendor; not fixed here.**

---

## What was checked and found correct

Worth recording so the next audit does not re-litigate it: one `<main>` per page on all 28 states; `lang`
present and correct per surface; no missing `alt`; no bare `focus:outline-none` (0 of 0 — every one carries a
replacement ring); flash messages on the door, both POS screens, the caja and Socios all carry
`role`/`aria-live` with `assertive` reserved for errors; the offline banner is `role="alert"`; camera and
photo-capture errors are `role="alert"`; every form control on the counter and in the PWA is labelled except
the one named in Phase 2; the counter's 44×44 touch floor holds (re-measured under prompt 194); and
`prefers-reduced-motion` is respected — the browser passes above were all run with `reducedMotion: 'reduce'`
and nothing depended on motion to become visible.
