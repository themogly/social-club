# Design audit — CSC platform

**Branch** `design/audit-pass` · **from** `9cd260b` (main, after the accessibility and admin audits) ·
**date** 2026-08-07

## How this was run

`tests/Browser/design-sweep.mjs` — viewport-sized captures (never full-page: a full-page shot hides exactly
the defect this is looking for) of 17 page states across **five viewports** — 1440×900, 1280×800, 1024×768,
**1440×560 (short laptop)** and 390×844 — in **light and dark**, authenticated, with the counter chain
cleared so the working screens are captured rather than six blocking states. 170 captures. Every one was
looked at; the ones quoted below are in `storage/app/screenshots/design/`.

Each render also reports its page height against the viewport, whether the page scrolls horizontally, any
element wider than the viewport, and any control under 24×24 — so "it looks fine" is backed by a number.

**Dark mode is NOT N/A here.** It is a first-class requirement in `CLAUDE.md` (club interiors are dim and
staff work in them all evening), so every finding below was checked in both schemes.

## Summary

| | count |
|---|---|
| PHASE 1 — critical | 2 |
| PHASE 2 — refinement | 3 |
| PHASE 3 — polish | 2 |
| Verified correct, no action | 6 |

**No page scrolls horizontally at any of the five widths, in either theme.** No layout breaks at the
in-between sizes; the short-laptop height — usually where overfitted layouts fail — is clean on every screen
that matters, including the dispensary POS, which keeps identity, the allowance gauge and `Registrar
aportación` all above the fold at 1440×560. Prompt 176's two-pane rebuild is doing its job.

What is left is drift rather than breakage, and it is concentrated in two places: **colour and buttons that
bypass the shared primitives**, and **two counter screens that do not use the width the other three do**.

---

### PHASE 1 — Critical

- **Off-palette colour: `red-*` and `amber-*` where `--color-error` and `--color-warning` exist.** Four
  usages, in `filament/pages/seguridad.blade.php:6-7` (the active-lockdown banner, drill vs real) and
  `filament/pages/dashboard.blade.php:87,92` (a health row). → Use the semantic tokens. → `CLAUDE.md`:
  *"Colours come ONLY from this palette — never introduce new text/UI shades."* These are not neutral greys
  from Filament's own ramp (which the panel legitimately uses); they are **new hues** standing in for
  states the palette already names, and prompt 98 tuned those tokens per-scheme for AA. A raw `red-600` is
  outside that work: it is the one place a contrast fix cannot reach.

- **Fourteen hand-rolled primary buttons against fifteen uses of the shared component.** `x-button` was
  extracted in prompt 36 *"to end the hand-rolled per-screen drift"*, and roughly half the brand-coloured
  buttons in the product never adopted it. Six are plain primaries the component already covers exactly as
  written — `socio/messages.blade.php:18`, `socio/message.blade.php:30`,
  `livewire/counter/membership-counter.blade.php:318`, `livewire/counter/partials/inline-fee.blade.php:22`,
  `livewire/counter/dispensary-pos.blade.php:273`, `livewire/counter/bar-pos.blade.php:296`. → Convert
  those six. → **Why it matters:** every one carries its own copy of the focus ring, the disabled state,
  the hover colour and the dark-mode treatment. The a11y audit had just moved the panel's primary shade to
  fix a contrast failure; a hand-rolled `bg-brand` is a button that a future palette change will silently
  miss. `CLAUDE.md`: *"All buttons use shared variants — never a one-off."*

  The remaining eight are **not** defects and are left alone: the PIN pad's twelve keys are a numeric
  keypad grid, not calls to action, and the genetic/article tiles are cards that happen to be buttons.
  Reported so a later pass does not re-derive them.

**Review:** both are drift from primitives this codebase already built and documented, which is what makes
them Phase 1 rather than taste — the rule exists, in writing, and the code walked around it.

---

### PHASE 2 — Refinement

- **The Caja is the only counter screen that does not use its width.** Measured: `max-w-2xl` (672px) in a
  1440px viewport, so the page is **1811px — 3.2 screens at the short-laptop height** and 2.0 even at
  1440×900. The door is `lg:grid-cols-[minmax(0,1fr)_22rem]`, the dispensary and the bar are two-pane; the
  till is a single narrow column of stacked sections. → Let the sections flow into two columns at `lg`. →
  **Why it matters:** it is the direct cause of a finding already carried over from prompt 194 — `Cobrar
  cuota` is the FOURTH stacked section and opens at **y=1413**, so collecting a fee means scrolling nearly
  two screens past the arqueo. Half the screen is empty while the operator scrolls.

- **The Socios counter leaves two thirds of the viewport blank, with nothing designed in it.** At 1440×900
  the screen is two small cards and ~700px of empty background. The door already solves exactly this: an
  empty state reading *"Scan a card or search for a member — their details and the access verdict will
  appear here."* → Give Socios the same panel. → **Why it matters:** `CLAUDE.md` requires empty states to be
  *"INTENTIONAL (designed), never a broken/blank box"*, and the admin audit has just applied that rule to 26
  tables. The counter's own screens should not be the exception, and an operator who has never used the
  screen currently has nothing telling them what it is about to show.

- **The Manual and the Glossary are the only pages whose content does not share a left edge with their own
  heading.** Both wrap their body in `mx-auto w-full max-w-3xl` inside Filament's content area, so the
  column centres while the `<h1>` stays left — a 145px indent with nothing justifying it. → Drop `mx-auto`
  and keep `max-w-3xl`. → **Why it matters:** it is the one alignment inconsistency in the panel, and it
  reads as a mistake rather than a choice.

**Review:** three cluster-level issues, none of them taste. The first two are the same shape — a screen not
using the space it has — and both have an existing in-product pattern to copy rather than a new design to
invent.

---

### PHASE 3 — Polish

- **The counter's skip link does not collapse while hidden.** It measures **32×16** at every viewport
  instead of the 1×1 `sr-only` produces, because `px-4 py-2` (needed for the focused state) overrides
  `sr-only`'s `padding: 0`. It is still clipped and invisible, so this is tidiness rather than a bug. → Move
  the padding to the `focus:` state. → It is a stray 32×16 box in the layout of every counter screen, and it
  arrived in the accessibility pass three commits ago.

- **The generated PDFs use two greys that are not in the palette** — `#eef2f7` and `#cbd5e1`, as table
  hairlines in `documents/register`, `documents/dispensing-record`, `documents/minute` and `reports/pdf`. →
  Note only, deliberately: dompdf gets its own inline stylesheet and cannot read the Tailwind tokens, these
  are print hairlines rather than UI, and a print rule is a genuinely different medium. Recorded so the next
  audit does not raise it as palette drift.

**Review:** neither affects a task. Both are recorded so they are not re-found.

---

## Verified correct — no action

Reported so a later pass does not re-derive them.

- **No horizontal scroll at any width, either theme, on any of the 17 page states.** The elements measuring
  wider than the viewport are all `<table>`s inside their own scroll container, which is the intended
  behaviour and (since the accessibility pass) keyboard-reachable.
- **The dispensary POS holds at the short-laptop height.** At 1440×560 the socio card, the remaining-today
  gauge and `Registrar aportación` are all visible without scrolling — the thing prompt 176 rebuilt the
  screen for, still true two prompts later.
- **The counter at 390 collapses deliberately, not accidentally.** The two-pane layout stacks below `md`
  and the tab strip drops to brand + sede + overflow; both are documented decisions (prompts 176 and 130),
  and the counter is tablet-first by `CLAUDE.md`. The dispensary is 2.8 screens at 390 — that is the stack
  working as designed, not a break.
- **The admin panel at 390 is out of scope by the project's own rule** — *"Desktop-first admin; tablet-first
  counter apps."* It renders and is usable; it is not designed for that width and is not judged at it.
- **Filament's `gray-*` inside panel pages is not off-palette drift.** It is the framework's neutral ramp,
  applied consistently, on a panel whose primary is deliberately set to the brand. The palette rule bites
  where a NEW hue appears — which is the Phase 1 finding above, and only that.
- **Long pages are long on purpose where they say so.** The Manual is 7924px because every guide renders in
  full behind an anchor index, stated in the view: *"each guide in full so the page prints and any step is
  reachable by anchor."* Settings is 4135px of genuinely distinct sections. Neither is a defect; both would
  benefit from in-page navigation, which is a feature request rather than an audit finding.

## OWNER CONTENT tasks

None. There is no marketing surface (a Spanish CSC may not advertise), no hero imagery, no stock photography
and no placeholder media anywhere in the product — the only images are the club's own logo, member photos and
generated QR codes. Nothing here needs real copy or real photos to be judged.
