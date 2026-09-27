# Design audit — pre-live (2026-09)

**Commit** `2d98aed` (branch `audit/pre-live`, identical to `main`, after prompt 269) · **date** 2026-09-27 ·
**REPORT ONLY.** No code changed; nothing committed by this run.

## How this was run

Real browser (Playwright, Chromium) against the seeded demo install at `127.0.0.1:8123`, signed in once as the
owner (counter PIN 1234, sede *Central Branch*), with the till open. Viewport-sized captures only, never full
page. Every capture quoted below was looked at. Captures are in `storage/app/screenshots/audit-design/`.

| | covered |
|---|---|
| **Viewports** | 1440×900 · 1280×800 · **1280×700 (short laptop)** · 1024×768 · **1180×820 (iPad landscape)** · **820×1180 (iPad portrait)** · 390×844 |
| **Schemes** | light AND dark (`colorScheme`) on every screen |
| **Motion** | reduced on every screen; **allowed** on the hub, Bar, the PIN surface and the alta modal/wizard |
| **Counter** | hub, Dispensario (empty · member held · weight pad · basket · tender · blocked member), Barra, Recepción (empty · member held), Socios (empty · member held), Caja (summary · record panels · blind count), PIN operator switch, alta modal + staff wizard step 1 |
| **Admin** | dashboard (all sections), Roles y permisos, Socios index, Socio create, staff login |
| **Socio PWA** | login only (see Discussion) |

Each render also reported page height against the viewport, horizontal overflow, elements wider than the
viewport and controls under 44px, so "it looks fine" is backed by a number.

## Summary

| | count |
|---|---|
| PHASE 1 — critical | 5 |
| PHASE 2 — refinement | 10 |
| PHASE 3 — polish | 4 |

The screens changed most since August mostly hold up. The dispensary's two-pane layout, the pinned total on the
commit button and 268's tender panel are right at every tablet and desktop size, in both schemes. 267's PIN
surface and the alta wizard are clean. Two things regressed in the **shared counter top bar**. It breaks at both
ends of the width range: from 1280 up the club name and screen title shrink to one letter, and at 390 the bar
scrolls sideways. The admin dashboard's tables clip their money columns at 1440 and 1280.

---

### PHASE 1 — Critical

- **[Counter top bar, every counter screen @ 1440×900, 1280×800, 1280×700 / light+dark]: the club name and the
  screen title shrink to one letter.** They read *"C."* over *"D.."* (Dispensario), *"M."* (Mostrador), *"S.."*
  (Socios). Measured: the home link is 79px wide, and the club name shows 15px of its 90px. At `xl` the bar adds
  its labels (*"Trabajando:"*, *"Bloquear pantalla"*, *"Cerrar sesión del dispositivo"*). The bar is capped at
  1152px, so the labels always win and the home link (`min-w-0`) takes the loss. At 1180/1024/820 the bar is
  unlabelled and the name shows in full (154px). →
  Move the labelling breakpoint past the point where it fits (e.g. `2xl`), or drop the *"Trabajando:"* prefix and
  the long *"Cerrar sesión del dispositivo"* label first. Let the measurement decide, as the rule says. → The
  title is the `<h1>`, which tells the operator which screen they are on. The documented rule (prompt 130,
  restated in 205/206) is that *"labelling is all-or-nothing and only where it fits; where it flips is whatever
  the measurement says"*. It no longer fits at `xl`, because prompt 267 widened the operator chip. ·
  `pos/3-line-1440-light.png`, `hub/1440-light.png`, `members/1440-light.png`, `pos/4-tender-short-light.png`

  **Fixed in 272:** the bar is capped at 1152px, so the labelled row fits at no width: Lock / Log out are icon controls everywhere (aria-label + title), "Trabajando:" is gone, Administración keeps its word. Home link measures 154px at every width.

- **[Counter top bar, every counter screen @ 390×844 / light+dark]: the bar scrolls sideways and loses its way
  home.** Measured: `scrollWidth` 451 in a 390 viewport, on all six counter screens, so the whole page scrolls
  horizontally. The home link (brand plus screen title) is crushed to **16px** and shows nothing. The operator
  chip overlaps where it was. The Lock control is gone. The log-out icon is cut at the right edge, and the panic
  button sits at x=407–451, **off screen**. → At phone width, collapse the right-hand group (Lock, Log out,
  panic) into the overflow the 390 layout used to have, and keep home, sede and *Administración*. Or stack the
  bar into two rows below `sm`. → The August audit recorded the 390 bar as *"drops to brand + sede + overflow"*.
  That is no longer true. `CLAUDE.md` requires *"every screen has a labelled way back"*, and the brand link is
  that way back. A panic control that you have to scroll sideways to find is a safety control in the wrong
  place. See Discussion for the constraint any fix must respect. · `hub/390-light.png`,
  `checkin/390-light.png`, `pos/0-empty-390-light.png`, `pos/1-member-390-dark.png`

  **Fixed in 272:** the row flex-wraps (controls to a second row, and at 390 the leave-group + panic to a third) — every control one tap and on screen, no overflow; `scrollWidth` = 390. Administración stays labelled (246).

- **[Solid amber/red buttons @ all counter viewports / DARK]: white text on `bg-warning` / `bg-error` fails AA in
  dark mode, and seven of these buttons bypass `<x-button>`.** Dark mode swaps the tokens to `#d97706` and
  `#f87171`. White text on them computes to **3.19:1** and **2.77:1**, against the 4.5:1 a 16px semibold label
  needs. Seen on the blind count's **Confirmar recuento**. The same defect is in *Autorizar y registrar*
  (`dispensary-pos.blade.php:946`, `partials/authorise-with-pin.blade.php:14`), `till-session.blade.php:186,231`,
  `partials/fee-waiver.blade.php:89`, `partials/needs-operator.blade.php:7` and `check-in-screen.blade.php:231`.
  The shared component carries it too: `x-button`'s `warning` and `danger` variants are `bg-warning text-white` /
  `bg-error text-white`, used for example by *Descartar* in `member-cart-summary`. → Fix it once in `x-button`:
  keep white on the light-scheme fill, and use a dark label (`dark:text-slate-950`) or a deeper dark-scheme fill
  for `warning`/`danger`. Then convert the seven hand-rolled buttons to `<x-button variant="warning|danger">` so
  they inherit the fix. → Prompt 98 tuned these tokens per scheme so that TEXT in them passes. Nobody measured
  the inverse case, text ON them. This is the same drift the August audit's button finding named: a hand-rolled
  button is one that a palette fix cannot reach. · `till/close2-land-dark.png` (Confirmar recuento, dark)
  against `till/close-land-light.png` (same button, light, passes)

  **Fixed in 272:** fill tokens split from text tokens; `x-button` warning/danger use them; all seven hand-rolled buttons converted.

- **[Admin dashboard @ 1440×900, 1280×800, 1024×768 / light+dark]: the tables clip the money columns they exist
  to show.** Measured scroll containers:
  - *Últimas transacciones*: 301 of 344px visible at 1440, 245 at 1280, 117 at 1024. *IMPORTE* is cut
    mid-figure (*"18"*, *"20"*) at 1440.
  - *Top dispensado*: *TOTAL* is cut to *"61,("* / *"18,1"* at 1280.
  - At 1024, with the sidebar still expanded beside the right rail, *Top dispensado* shows only its first column,
    and the *Techo legal* cards wrap to one word per line.

  → Give these two tables the full main-column width (stack them below `2xl` instead of side by side). Or collapse
  the Filament sidebar by default below `xl` on this page. Or drop the Operador column where the width is short.
  → A clipped euro figure on an owner's dashboard reads as a wrong number, not a layout quirk. Desktop-first
  admin means 1280 and 1024 are in scope. · `adm-dashboard/tx-1280-light.png`,
  `adm-dashboard/tx-1024-light.png`, `adm-dashboard/scroll2-1440-dark.png`

  **Fixed in 272:** tables side by side only from 2xl; the rail joins the main column from 1280; Techo legal cards size to the section. No table scrolls at 1440/1280/1024.

- **[Dispensario, member blocked @ 1180×820, 820×1180 / light+dark]: an empty card sits between the identity card
  and the basket.** It is a 32px rounded box with nothing in it. The cause is `dispensary-pos.blade.php:585`:
  `data-member-detail` renders whenever the verdict is not clear. While the blocked surface is up, every blocking
  rule is `@continue`d, `membership-fix`/`inline-fee` are `@unless ($blockedSurface)`, and `member-owes` renders
  nothing when nothing is owed. So the section renders only its padding. → Gate the section on "has at least one
  row to show": the unsatisfied WARN rules, or something owed, while blocked. Compute that once in the component
  (the same rule the loop applies). → `CLAUDE.md`: empty states are *"INTENTIONAL (designed), never a broken/blank
  box"*. This one sits on the screen staff use most, in the state where they most need to read it calmly. ·
  `pos/1-member-blocked-port-dark.png`, `pos/1-member-blocked-land-dark.png`

  **Fixed in 272:** the section renders only when it has a row (the loop's own rule, computed once).

**Review:** Two of these are the same component, the counter top bar. Both are regressions: the bar was widened
without re-measuring either end of the range, and the fix belongs in one file. The dark-mode button contrast is
the one real accessibility failure found. It sits in the shared button component, so fixing it there reaches
every caller once the hand-rolled copies are converted. The dashboard clipping and the blank card are concrete
breakage rather than taste.

---

### PHASE 2 — Refinement

- **[Native controls @ all counter viewports / DARK]: the counter never declares `color-scheme`, so native widgets
  paint for a light page.** The date input's calendar glyph is **black on near-black**, effectively invisible, in
  the alta wizard (computed `color-scheme: normal`). The same is true of the native `<select>` popups (cuota,
  tipo de documento, movimiento, categoría) and the file inputs. Only the socio layout sets `<meta
  name="color-scheme" content="light dark">`. → Add `color-scheme: light dark` to the counter layout (meta or
  `:root`), keeping the `:root:not(.light)` guard `tokens.css` already uses. → Dark mode is first class. The
  wizard is where a new member's date of birth is typed. · `alta/date-dark-zoom.png`,
  `alta/wizard1-port-dark.png`

  **Fixed in 272:** `color-scheme: light dark` on `:root` with the explicit-light guard.

- **[Counter iconography @ all viewports / both]: OS emoji stand in for icons beside an outline-SVG set.** There
  are 12 emoji or glyph icons in counter views:
  - 🪪 on the POS, Recepción and Socios empty states
  - 📍 on every no-sede blocking state
  - ⚠️ offline banner · 🚫 · 💶 · ⛔ on the blocked surface
  - 📝 🤝 on the alta method cards
  - 🔍 in the Socios lookup
  - ⬜ ▦ ≡ on the view toggles

  The rest of the product uses one stroke-1.8 outline set (top bar, hub tiles, camera). Emoji render in full
  colour, outside the palette, differently on Android and iPad, and small. The POS *"Identifica a un socio"*
  hero is a ~24px colour sticker, and the Bar's large-tile toggle ⬜ is a grey emoji square in light and a white
  one in dark. → Replace them with the same inline outline SVGs (the hub already has scale, person, people, bag,
  card; add id-card, map-pin, alert, search, grid/list). → This is a cluster-level consistency issue, and it is
  the one visibly off-palette element left on the counter. · `pos/0-empty-land-light.png`,
  `alta/modal-land-light.png`, `bar/land-light.png`, `bar/port-dark.png`,
  `pos/1-member-blocked-port-dark.png`

  **Fixed in 272:** new `x-counter.icon` outline set replaces all twelve; a test fails any drawn emoji.

- **[Counter touch targets @ 1180×820, 820×1180 / both]: three controls fall under the counter's own 44px
  floor.** The floor is recorded in `DECISIONS.md` l.4489 and l.4705.
  - The hub's *Requiere atención* rows, including 269's new low-stock alert, are links **32px** tall.
  - The blind count's *No se puede contar* chips are **24px**.
  - Recepción's *Cerrar* is **66×36**.

  → Use `min-h-11` on the alert links (they are already full width), `min-h-11 px-3` on the chips, and `h-11` on
  Cerrar. → These are finger targets on a tablet in a dim room. The alert rail is the entry point 269 just
  added. · `hub/land-light.png`, `till/close-land-light.png`, `checkin/held-land-dark.png`

  **Fixed in 272:** all three at 44px.

- **[Admin panel @ desktop / DARK]: two neutral families on one screen.** Filament's default gray ramp is zinc:
  the page is `#09090b` (zinc-950) and sections are `#18181b`. The dashboard's own cards (`csc-card`,
  `csc-section`) are slate-800 `#1e293b`. The result is blue-grey cards on a neutral-black page, next to zinc
  Filament sections on other pages. The palette's neutrals (`#0f172a`, `#475569`, `#e2e8f0`, `#f8fafc`) are all
  slate, and so is the counter. → Add `'gray' => Color::Slate` beside `'primary' => Color::Blue` in
  `AdminPanelProvider::colors()`. One line, and it aligns the panel chrome with the counter and the dashboard
  cards. → August's report cleared Filament's gray as "the framework's neutral ramp". That still holds for
  light, but in dark the two ramps now meet on the same screen. · `adm-dashboard/1440-dark.png`,
  `adm-roles/1440-dark.png`, `login/port-dark.png`

  **Fixed in 272:** `'gray' => Color::Slate`.

- **[Dispensario, member held, basket empty @ all viewports / both]: the basket shows two empty states, and the
  second one is wrong.** It reads *"Cesta vacía. Elige una genética e introduce el peso."* and then, in a dashed
  box, *"Identifica a un socio y añade una genética para empezar."*, while the socio is identified and pinned
  directly above. The hint (`dispensary-pos.blade.php:962`) is static. → Keep one message, and make it depend on
  state (no socio: identify; socio held: choose a genetic). → A designed empty state should say the next step.
  This one gives two, and one of them is already done. · `pos/1-member-land-light.png`,
  `pos/1-member-blocked-port-dark.png`

  **Fixed in 272:** one state-dependent message.

- **[Alta staff wizard, step 1 @ all viewports / both]: browser-native file inputs, in the browser's language.**
  *Foto* and *Documento de identidad* show Chrome's **"Choose File / No file chosen"** in English inside a
  Spanish UI, as an unbranded control. → Use a visually hidden input behind an `x-button variant="secondary"`
  label, with a translated *"Ningún archivo"* / file-name line, as the counter's `photo-capture` component
  already does. → This is the one piece of copy that i18n parity cannot catch, because it is not the app's
  string. · `alta/wizard1-land-light.png`, `alta/wizard1-port-dark.png`, `alta/wizard1-390-light.png`

  **Fixed in 272:** new `x-counter.file-field` (hidden input in an `<x-button as="label">`, translated "Ningún archivo").

- **[Roles y permisos @ 1440, 1280, 1024 / both]: a 30-row permission matrix built from raw 13px browser
  checkboxes.** `roles-permissions.blade.php:58,67` uses `<input type="checkbox" class="rounded border-gray-300
  text-primary-600">`, but the panel theme ships no forms plugin, so these paint as native OS boxes. They are
  blue ticks in light, grey boxes in dark, with a 10px *por defecto* caption under each. The owner column's
  locked boxes are just greyed, with no visible "siempre". → Use `<x-filament::input.checkbox>` (or a toggle) so
  the matrix matches every other Filament form, and give the owner column a visible *Siempre* label. → This is
  the owner's page for deciding what staff may do (prompt 262). It should look like the rest of the panel, and
  its targets should not be 13px. · `adm-roles/1440-light.png`, `adm-roles/scroll1-1440-light.png`,
  `adm-roles/1440-dark.png`

  **Fixed in 272:** `x-filament::input.checkbox`, a visible "siempre", 44px targets, AA state text.

- **[Member card across counter screens @ 1180×820 / both]: the same socio renders three ways.** The *ACTIVO*
  status badge is an outlined neutral pill on Socios and a green tinted pill on Recepción and the POS. The
  dismiss control reads *Cambiar* on Socios and *Cerrar* on Recepción and the POS. The photo nag is a compact
  two-button strip on the POS and a full-width block on Recepción. → Render the status badge from one shared
  partial or component, with one dismiss word. → This is cross-screen drift the frontend-design skill calls out.
  An operator moving between screens should not have to re-read a familiar card. ·
  `members/held-land-light.png`, `checkin/held-land-dark.png`, `pos/3-line-land-light.png`

  **Fixed in 272:** one `member-status-badge` partial and one dismiss word (Cerrar). The photo nag's two shapes were left (no change recommended; different contexts).

- **[Recepción, member held @ 1180×820 / both]: *Registrar entrada* sits ~10px past the fold on the primary
  device.** The page is 876px in an 820 viewport once the photo nag shows, so the door's only commit is clipped at
  the bottom edge. → Tighten the vertical rhythm of the held-member card (the nag and the 2×2 facts grid), or pin
  the commit as the POS does. → The counter UX audit's first principle is that the commit is on screen. At the
  door it now depends on whether the member has a photo. · `checkin/held-land-dark.png`

  **Fixed in 272:** facts grid in one row from lg and a tighter rhythm; on screen at 1180×820 even with the nag and a warning.

- **[Admin dashboard KPI grid @ 1440, 1280 / both]: eight tiles in a three-column grid leave a stranded hole.**
  Row three is *Saldo de socios*, *Caja*, then empty. → Either let the row's last tile span (`col-span-2` on the
  eighth tile when `n % 3 == 2`), or move to a four-column grid at `2xl`. → The design audit rubric names
  stranded grid tiles specifically. The hole reads as a missing widget. · `adm-dashboard/1440-light.png`,
  `adm-dashboard/1440-dark.png`

  **Fixed in 272:** a wrapping flex row (12rem basis) — the last row's tiles share it, no hole.

**Review:** These are cluster-level issues, not taste. Four of them come from bypassing a shared primitive: native
controls, emoji instead of the icon set, raw checkboxes, three badge renderings. Each has an existing in-product
pattern to copy. Two are dark-mode specific, which is the scheme staff actually work in.

---

### PHASE 3 — Polish

- **[Cart column, Dispensario & Barra @ all two-pane viewports / both]: the scroll region's fade clips the first
  card's top edge even when nothing is scrolled.** `.counter-scroll-region` applies a permanent 12px
  `mask-image` (`app.css`). At `scrollTop` 0 the *Cesta* card's top border and rounded corners fade out, so it
  looks unfinished next to every other card. → Toggle the mask with a class when `scrollTop > 0` (Alpine,
  `@scroll`), or pad the region's top by 12px so the fade only ever covers scrolled content. → Prompt 225 meant
  it to say "there is more above", and at rest there is not. · `bar/cart-top-zoom-light.png`,
  `bar/cart-top-zoom-dark.png`

  **Fixed in 272:** the fade shows only once the region is scrolled (`.at-top`).

- **[Cart column @ all two-pane viewports / both]: the cards in the scroll region are 4px narrower than the
  pinned identity card and the commit button.** `scrollbar-gutter: stable` plus `pr-1` means their right edges
  do not line up. → Offset the gutter with a matching negative margin, or apply the same right inset to the pinned
  head and foot. · `pos/3-line-land-light.png`, `pos/3-line-port-light.png`

  **Fixed in 272:** the extra `pr-1` is gone; the cards line up with the pinned card and the commit.

- **[Hub @ all viewports / both]: the *¿Por qué no puedo dispensar a un socio?* disclosure has no affordance.**
  `summary` is `display:flex`, which suppresses the disclosure marker, so it reads as a bold static line in an
  86px card. → Add a trailing chevron that rotates on `[open]`. · `hub/land-light.png`, `hub/port-light.png`

  **Fixed in 272:** a rotating chevron (reduced-motion safe).

- **[Dispensario pinned identity card @ 1180×820, 820×1180, 1440 / both]: *Hacer foto* wraps onto two lines**
  inside the photo nag while *Subir archivo* does not, so the pair are uneven. → Use `whitespace-nowrap`, or let
  the pair go full width below `lg`, as Recepción's nag does. · `pos/2-genetic-land-light.png`,
  `pos/3-line-port-light.png`

  **Fixed in 272:** `whitespace-nowrap`.

**Review:** None of these gets in the way of a task. The first is the only one a user is likely to notice
unprompted.

---

## Verified OK — no action

Recorded so a later pass does not re-derive them.

- **The dispensary POS holds at every tablet and desktop size, in both schemes.** Page height equals the viewport
  at 1440, 1280, 1280×700, 1024, 1180×820 and 820×1180. Identity, allowance gauge and the pinned *Registrar
  aportación · total* are all on screen. Only the cart's middle scrolls, as intended (176/225/234).
- **268's tender panel is correct as a design.** *Justo · €5 · €10 · €20* sit in one row of equal 44px keys.
  *Borrar* is right-aligned on the label row. The *A cobrar en efectivo* / *Falta* (error colour) / *Cambio*
  summary reads at a glance in light and dark. The *Monedero* box appears only when the socio has a balance
  (seen at 2,84 €).
- **267's PIN surface is a true full-screen surface**, centred and identical at 1180×820, 820×1180 and 1440, in
  light and dark, with motion reduced or allowed. Keys are ~85×53px and the entered-digit dots are clearly
  visible in dark.
- **The alta modal and staff wizard fit every size.** Method cards, pinned footer (*← Métodos* / *Siguiente*), a
  44px close control, and a stepper with labels at tablet widths and numbers only at 390. With motion allowed,
  the 0.22s pop-in ends in the same state as reduced motion: no hide-until-JS content.
- **Bar POS** keeps the two-pane layout with *Cobrar* pinned at landscape and portrait. The empty basket has a
  designed message.
- **Caja**: the three record panels share the width (August's fix holds), and the blind count shows no expected
  figures.
- **Palette discipline.** A repo-wide grep finds **0** non-neutral Tailwind hue utilities (`red-*`, `amber-*`, …)
  in `resources/views`. Hex literals appear only in the dashboard's own stylesheet, and those values are palette
  or palette tints. Every dashboard chart takes its series colours from the palette (`brand`, `success`,
  `warning`, `error` and their soft variants).
- **No horizontal scroll anywhere except the counter at 390** (Phase 1). The admin tables that are wider than
  their cards scroll inside their own containers. That is the intended mechanism; the finding is about which
  columns end up hidden.
- **Motion.** The only animation is the alta modal's pop-in, and it is gated behind
  `prefers-reduced-motion: no-preference`. Everything else is colour transitions on hover.
- **Socio login and staff login** render cleanly at 390, 820 and 1440, in light and dark.
- **Admin at 390 is out of scope by the project's own rule** (*"Desktop-first admin"*). It renders.

## Discussion

- **The 390 top-bar fix has to respect a documented decision.** DECISIONS.md l.12786 (*"The admin's way back is a labelled word"*)
  made the *Administración* label show *"at EVERY width"*, because an unlabelled briefcase lost admins in
  portrait. That label is part of why the bar no longer fits a phone. Any fix should keep the word visible and
  move something else out of the row instead: Lock, Log out and panic into an overflow, or a second row below
  `sm`. It should not re-hide the label. This is raised here, not decided, because it re-balances a choice the
  owner made.
- **`CLAUDE.md` says the motion layer is "Motion One micro-interactions + light scroll reveals".** No Motion One
  package is installed (`package.json`), and the product's motion is CSS only. That is safe, and arguably right
  for a counter, but the working agreement describes a layer that does not exist. This is doc drift for the owner
  to reconcile (update the line, or build the layer). It is not a defect.
- **The commit button stays full blue while a member is blocked.** This is deliberate (prompt 60: *"every other
  blocked state stays CLICKABLE, and commit() flashes its reason"*), paired with the amber *Bloqueado* line
  under it. It is not reported. It is noted because a fresh eye reads it as a live button, and a later audit
  should not "fix" it.
- **Not covered, and why:**
  - **The member PWA's authenticated pages** (`/socio`, `/socio/historial`, …). A member session needs a magic
    link, which needs a member's email address. Reading member records to get one was refused as PII handling,
    and correctly so. Only `/socio/login` was audited. A harness that seeds a known demo member email would
    close this gap without touching real data.
  - **The till's closed / "open the till" state.** The shared demo till must not be closed, so only the open
    state and the (unsubmitted) blind count were captured.
  - **269's low-stock row on the counter hub.** The admin dashboard (all sedes) lists *1 variedad con stock
    bajo*. The Central Branch hub lists two alerts without it, presumably because the low variety belongs to the
    other sede. The prompt-269 harness capture (`storage/app/screenshots/269/2-counter-hub.png`) shows the row
    styled like its siblings.
