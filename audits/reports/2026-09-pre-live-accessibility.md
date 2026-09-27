# Accessibility audit (WCAG 2.2 AA): pre-live, 2026-09

**Commit** `2d98aed` (branch `audit/pre-live`, identical to `main`, after prompt 269) · **date** 2026-09-27 ·
**report only**: no code changed, nothing committed.

## Method

- **The automated pass had to be done by hand.** `node_modules/@axe-core/` is an empty directory, so
  `tests/Browser/axe-sweep.mjs` cannot run: it imports `@axe-core/playwright`. There was no other axe copy on
  the machine and nothing was installed. Instead I wrote a Playwright probe that runs inside the page. For
  every visible text node it computes the contrast ratio by compositing the whole background chain through a
  canvas, so `oklch` and alpha colours are handled. It also finds controls with no name and controls named only
  by a placeholder, measures touch targets, lists landmarks, headings and dialogs, and finds focusable elements
  inside `aria-hidden`. A Tab walk then compares each element's focused and unfocused styles to check for a
  visible focus indicator. I checked every finding by hand after that, either with a screenshot, a measurement
  taken in the browser, or a `file:line` reference.
- **What was rendered.** The counter as `staff@club.test` (PIN 3456), 1024×768, light and dark, with
  `reducedMotion: reduce`. 14 states: the hub; the POS empty, with a blocked member, with a member held, and with
  a basket and the tender panel open (Falta and Cambio); the bar empty and with a basket; check-in with and
  without a member; Socios with and without a member; the sign-up modal; the staff wizard (including its
  validation errors); and the till. Plus the PIN surface, driven from the keyboard. The panel as
  `manager@club.test`, and as `owner@club.test` for Roles y permisos and Salud del sistema, 1440×900, light and
  dark: 16 pages. `/login`, `/socio/login` and the password reset page at 1024 and 390.
- **What was not rendered.** The signed-in member PWA. Getting a member session meant writing a login token
  into the shared demo database, and that was declined, so the PWA was reviewed from its Blade files only (see
  Discussion). I did not delete anything and did not change or close anything. The one side effect was a
  single deliberate wrong PIN (below), and every test basket was emptied afterwards.
- Screenshots are in `storage/app/screenshots/audit-a11y/` (94 files). The files cited below are named where
  they are used.

## Summary

| | count |
|---|---|
| PHASE 1: blockers (blocks a task or fails AA) | 6 |
| PHASE 2: important | 9 |
| PHASE 3: polish | 5 |
| Dismissed on inspection | 3 |

**Structure is in good shape.** Every state has one `<main>`, one `<h1>` and a unique title, `lang` is correct,
every icon-only button has a name, and reduced motion is respected.

**What is left falls into three groups:**
- **Dark mode reuses semantic tokens as fills and hover states.** The dark tokens were tuned for text on dark
  surfaces, so they fail when they become a fill under white text or a light hover. This hits the screens staff
  use most.
- **The new keyboard and overlay behaviour.** The PIN pad treats Enter as submit even when a digit key has
  focus, and the new modals do not move focus into themselves.
- **Form fields and errors that repeat old mistakes.** The POS cash field is back to a placeholder only, and the
  staff wizard does not use the error pattern the PWA form already has.

---

### PHASE 1: Blockers

- **[counter · PIN surface] Pressing Enter on a focused PIN key submits the PIN typed so far, so a keyboard user
  cannot sign in.**
  - `counter-surface.blade.php:74` binds `@keydown.window.enter="open && padVisible && submit()"`. Enter is the
    normal way to activate a focused `<button>`, and keydown reaches `window` before the button's click fires.
    So Enter on "3" adds a digit, but Enter on the next key first submits "3" and only then adds the second
    digit.
  - Verified with the keyboard, in `pinpad-enter-on-digit-dark.png`: the pad showed **"PIN no reconocido."**
    and one dot. Every key after the first costs one attempt against the lockout throttle (prompt 235).
  - Typing digits on a physical keyboard or keypad does nothing (typing "34" left 0 dots).

  → Do not submit when the event comes from a pad key, for example
  `@keydown.window.enter="if (! $event.target.closest('[data-counter-surface] button')) …"`. Also accept `0–9`
  and Backspace from `@keydown.window` while the pad is visible.

  → Why: WCAG 2.1.1. Since prompt 267 this PIN is how anyone signs in, everywhere, including the admin panel. A
  keyboard-only operator locks themselves out by working the pad the standard way.

  Review:

- **[counter · dark] Dark-scheme `warning` and `error` tokens are used as solid fills under white text, and fail
  AA.**
  - In dark, `--color-warning` is `#d97706` and `--color-error` is `#f87171`. Both were chosen by prompt 98 for
    *text* on dark surfaces. White on them is **3.19:1** and **2.77:1**.
  - Measured in the browser: the POS "Descartar" confirm is white on `rgb(248,113,113)`
    (`pos-discard-confirm-dark.png`).
  - Same construction in the shared button and in the partials:
    - `<x-button variant="danger|warning">` (`components/button.blade.php:20,22`).
    - Condonar cuota (`partials/fee-waiver.blade.php:89`).
    - Autorizar y registrar (`dispensary-pos.blade.php:946`).
    - The supervisor-PIN confirm (`partials/authorise-with-pin.blade.php:14`).
    - The end-of-day reweigh "Confirmar recuento" and the "Marcar para contar" chip
      (`till-session.blade.php:231`, `:186`).
    - "Identificarme" (`partials/needs-operator.blade.php:7`).
    - The camera and photo error pills (`camera-scan.blade.php:51`, `photo-capture.blade.php:82`).

  → Split fill tokens from text tokens: `--color-warning-fill` / `--color-error-fill` with their own dark
  values. This is the same move the dashboard's `--br` / `--brfill` split made. The palette's own `--error`
  `#dc2626` takes white at 4.83:1. No amber takes white in dark, so the warning fill wants dark ink text, or
  amber-800 as it already has in light.

  → Why: these are the consequential buttons (override, waive, discard, close-out), in the scheme staff work in
  all evening.

  Review:

- **[counter · dark] A tapped tile or weight preset is left with a light hover background, and the text on it
  falls to 1.9–2.4:1.**
  - `article-card.blade.php:60` has `hover:bg-brand-tint/40`, and the weight presets at
    `dispensary-pos.blade.php:187` have `hover:bg-brand-tint`. Neither has a `dark:hover:` override.
  - On a touch tablet `:hover` sticks to the last element tapped. In dark, the bar and POS tile's secondary text
    (category, THC/CBD, stock, "Con lote") measures **1.91:1** on the grey that results
    (`bar-tile-hover-dark.png`). The tapped preset's price is **2.42:1** on near-white (`pos-tender-1024-dark.png`).
  - Separately, the ⅛ eighth-price label (`text-brand` with no dark override, `:193`) is **3.90:1** on
    slate-950.

  → Add `dark:hover:bg-slate-800` (or equivalent) to both, and `dark:text-blue-400` for the eighth label.

  → Why: AA applies in every state, and this is the state a tablet sits in after every tap. **It also corrects
  the August report**, which dismissed the tile finding as axe misreading "a transitional background". It was
  this hover.

  Review:

- **[counter · tender panel, prompt 268] "Cambio" in success green is 4.43:1 in dark, and "Rehacer" fails in both
  themes.**
  - The change figure is `text-success` 16px bold on the tender box's `dark:bg-slate-800`: measured `#16a34a` on
    slate-800, **4.43:1**. Found on both panels: `dispensary-pos.blade.php:889` and `bar-pos.blade.php:414`.
  - Same pair on Socios' "Debe 0,00 €" (`membership-counter.blade.php:171`).
  - The signature pad's "Rehacer" uses `text-success/80` on the success tint: **4.02:1** light, **3.58:1** dark
    (computed from the tokens; `signature-pad.blade.php:67`). This is August's "opacity on a token" defect
    coming back.

  → For the first: the dark success value was only ever checked against slate-900. Give those boxes
  `dark:bg-slate-900`, or lift the dark success token enough to clear slate-800. For the second: drop the `/80`.

  → Why: "Cambio" is the figure the operator reads to count change into a hand. 16px bold is not "large text"
  for AA.

  Review:

- **[counter · forms] Fields with no real label: the POS cash field (placeholder only) and the reason boxes.**
  - The POS **"Efectivo entregado"** input is named only by its placeholder. The visible "Efectivo entregado"
    above it is a `<p>` (`dispensary-pos.blade.php:865`). August fixed exactly this on the bar
    (`<label for="bar-cash-tendered">`), and prompt 268 rebuilt this panel without it.
  - The price-override amount and reason fields are placeholder-only (`:829–830`).
  - So are the limit-override reason (`:945`, and `authorise-with-pin.blade.php:7`) and the waiver's "Otro"
    reason (`fee-waiver.blade.php:79`).
  - Both void boxes have a `<label>` that is neither `for=` nor wrapping, so it labels nothing
    (`dispensary-pos.blade.php:982–983`, `bar-pos.blade.php:451–452`).

  → Real `<label for>` / `id` pairs, copying the bar's cash field.

  → Why: WCAG 3.3.2 / 1.3.1. The audit spec lists "placeholder is NOT a label" as a blocker. The text vanishes
  on the first keystroke of the field that decides how much cash goes in the drawer, or why a price or limit
  was overridden. (August put a placeholder-only field in Phase 2. It is filed here to follow the spec; see
  Discussion.)

  Review:

- **[panel · Roles y permisos, prompt 262] The "por defecto" state text is below AA in light mode.**
  - The line under each checkbox is `text-[10px] text-gray-400`, measured **2.62:1** on white
    (`roles-1440-light.png`).
  - The line that matters most, "por defecto: sí / no" (the club has diverged from the factory default), is
    Filament `text-warning-600`, about **3.2:1** (computed; no divergent row exists in the seed)
    (`roles-permissions.blade.php:73–74`).
  - The same `text-gray-400` is on the help menu's "Guías de tareas" label (`help-menu.blade.php:28`).

  → `text-gray-600 dark:text-gray-400` and `text-warning-700 dark:text-warning-400`.

  → Why: this page is the owner's record of every departure from the defaults. The one signal on it should be
  readable.

  Review:

---

### PHASE 2: Important

- **[counter · shared button + commit] The focus ring is effectively invisible on filled buttons.**
  - `<x-button>`'s base is `focus:outline-none focus:ring-2 focus:ring-brand/40` with no offset. It appears 44
    times in `resources/views`, and the Registrar/Cobrar commit (`dispensary-pos.blade.php:1010`) uses it too.
  - The 40% ring measures **1.8:1** against white, **1.5:1** against slate-950 and **~2.6–2.9:1** against the
    blue fill. `pos-commit-focus2-dark.png` shows the focused commit button looking the same as unfocused.

  → `focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2`, with a ring-offset colour for
  each theme, on the shared component.

  → Why: WCAG 2.4.7 / 1.4.11 (3:1 for a state indicator). August checked that every `outline-none` had a
  replacement ring, but never measured whether the ring could be seen.

  Review:

- **[counter · overlays] The new modals do not move focus into themselves, and one uses `alertdialog` without a
  name.**
  - Opening the sign-up modal leaves focus on "+ Nuevo socio/a", behind it. Entering the staff wizard drops
    focus to `<body>` (verified).
  - The PIN surface opens with focus on `<body>`, and Tab reaches the top bar and the POS behind it before the
    pad.
  - "Importe manual" leaves focus on its trigger.
  - The POS discard confirm is `role="alertdialog"` with no name and no focus move
    (`member-cart-summary.blade.php:50`).
  - Because these are `aria-modal="true"`, a screen reader treats the element that still has focus as hidden.

  → Focus the dialog's first control (or heading) on open, and return focus to the trigger on close. The
  receipt and document sheets already do exactly this (`$refs.closeButton.focus()`). Make the discard confirm
  `role="alert"`, or name it and focus it.

  → Why: WCAG 2.4.3. This is initial focus, **not** a focus trap, so it keeps the recorded no-trap decision
  (see Discussion).

  Review:

- **[counter · overlays] The back gesture leaves the page instead of closing the sign-up modal or "Importe
  manual".**
  - Verified with `page.goBack()`: modal open on `/counter/members` → Back → `/counter`. Same from the bar's
    manual-amount dialog.
  - Only `receipt-sheet` and `document-sheet` call `pushState`. The camera and photo overlays do not either.
  - On the sign-up modal, Back also bypasses the close guard, so an applicant's typed data is lost without the
    confirm.

  → `history.pushState` on open and `popstate` closes (through `attemptClose()`), as the sheets do.

  → Why: CLAUDE.md names this rule, and names the sign-up modal as part of the canon. Keyboard and
  switch-access users on Android rely on Back to dismiss things.

  Review:

- **[counter · staff sign-up wizard, 221] Validation errors are not tied to their fields.**
  - After a failed "Siguiente" there are five `<p class="text-error">` messages, with no `id`, no
    `aria-invalid`, no `aria-describedby` and no live region. Required fields are not marked in code either, and
    focus stays on Siguiente (verified; `alta-wizard-errors-dark.png`, `alta-staff-form.blade.php:47` onwards).
  - The PWA application form collects the same fields and already does all of this (`<x-socio.field-error>`,
    August).

  → Reuse the PWA pattern, and move focus to the first invalid field.

  → Why: WCAG 3.3.1 / 1.3.1. This is the longest staff form on the counter.

  Review:

- **[counter · PIN surface] Nothing on the PIN pad reaches a screen reader.**
  - "PIN no reconocido." (`counter-surface.blade.php:154`) is a plain `<p>`.
  - The digit display is `aria-hidden` (`:146`), so there is no feedback on how many digits have been entered.

  → `role="alert"` on the feedback, and an `aria-live="polite"` sr-only count ("3 dígitos").

  → Why: WCAG 4.1.3 / 3.3.1.

  Review:

- **[counter · tender] The totals change without an announcement.**
  - Quick-cash taps now add (prompt 268) and flip "Falta" ↔ "Cambio", and the commit button's total changes.
    None of it is in a live region (`dispensary-pos.blade.php:868–899`, `bar-pos.blade.php` `<dl>`).

  → `aria-live="polite"` on the `<dl>` (or on an sr-only summary line).

  → Why: WCAG 4.1.3. The result of a tap should not be visual-only.

  Review:

- **[counter · toggles] Some toggles show which option is selected only by colour.**
  - Affected: Gramos / Calculadora € (`dispensary-pos.blade.php:165–166`), the fee's Efectivo / Monedero
    (`inline-fee.blade.php:19–20`), the category and strain filter chips on the bar and POS, and the batch
    picker.
  - On the same screens, the source and layout toggles already carry `aria-pressed`.

  → Add `aria-pressed` (or radio semantics) to match.

  → Why: WCAG 4.1.2 / 1.4.1.

  Review:

- **[counter · POS weight keypad] "⌫" has no name** (`dispensary-pos.blade.php:209`), while the PIN pad's
  backspace is `aria-label="Retroceso"`.

  → Same label. → Why: WCAG 4.1.2. Screen readers read the glyph inconsistently, or not at all.

  Review:

- **[counter + PWA · signature pad] The canvas has no name or instructions, and saving is not announced.**
  - `signature-pad.blade.php:100` has no `role` or `aria-label`. The form-mode "✓ Firma capturada" is not live.
  - The pad is used on the POS, in the staff wizard and on the applicant's own form (`application.blade.php:307`).

  → `role="img"` with an `aria-label` that includes the instructions, and `role="status"` on the captured
  message.

  → Why: WCAG 1.1.1 / 4.1.3. The pointer requirement itself is exempt as path-dependent input; being unlabelled
  is not.

  Review:

---

### PHASE 3: Polish

- **[counter] Touch targets under the project's 44px floor** (all pass WCAG 2.5.8's 24px):
  - Hub "Requiere atención" alerts: 32px (`counter-home.blade.php:153`).
  - The weight panel's Cancelar: 28px (`dispensary-pos.blade.php:159`).
  - Gramos / Calculadora €: 32px (`:165–166`).
  - Check-in's Cerrar: 36px (`check-in-screen.blade.php:100`).

  → `min-h-11`. → Why: tablet-first. The rail is the hub's newest control.

  Review:

- **[counter · hub] Alert severity is shown only by the dot's colour** (`counter-home.blade.php:157`, where the
  dot is `aria-hidden`).

  → An sr-only severity word, or a shape difference. → Why: WCAG 1.4.1. Low impact, since the label itself says
  what is wrong.

  Review:

- **[panel · Roles y permisos] The divergence from default is not announced.** The checkbox's `aria-label`
  overrides its wrapping label, so "por defecto: no" never reaches a screen reader.

  → `aria-describedby` pointing at that span. → Why: this is the page's main information.

  Review:

- **[counter · flash] The ✕ dismiss has `opacity-70`.** It measures 3.18:1 light and 3.32:1 dark, which passes
  3:1 for a glyph but repeats the opacity-on-token pattern (`counter-flash.blade.php:57`).

  → Drop the opacity. → Why: cheap, and it closes a pattern that has already caused one failure.

  Review:

- **[counter · tender] Names that need their context.** "Borrar" beside "Efectivo entregado" clears only the
  cash, and "€5 / €10 / €20" now *add* money.

  → `aria-label="Borrar efectivo entregado"` / "Añadir 5 €". → Why: WCAG 2.4.6. Prompt 268 changed what these
  buttons do, and the names no longer say it.

  Review:

---

## Verified OK / fixed since the last audit (2026-08, `9ef638c`)

- **Structure**
  - All 30 rendered states have exactly one `<main>` and one `<h1>`.
  - Counter titles are unique per screen (Mostrador / Dispensario / Barra / Recepción / Socios / Caja).
  - Socios now has one `<h1>`, and the PWA login has one.
  - `lang="es"` everywhere for a Spanish user. The one exception is the bare 403 page (manager on
    `/manage-settings`), which renders `lang="en"` with the title "Forbidden". It is minor and not ranked.
- **August fixes still hold**
  - Skip link on the counter works (it is first in the Tab order).
  - Bar cash field `<label for>`.
  - Filament primary is `Color::Blue`.
  - The PWA application form's error association and 44px consent rows.
- **Correct as built**
  - Member lookup is a correct combobox/listbox: `aria-activedescendant`, arrow keys, Escape, and a live "Sin
    resultados".
  - Receipt and document sheets move focus to Cerrar, close on Escape, handle `popstate`, and give the iframe a
    `title`.
  - Top-bar icon buttons are named (Bloquear pantalla, Bloqueo de seguridad).
  - Layout and source toggles carry `aria-pressed`.
  - The staff wizard stepper uses `aria-current="step"`.
  - Flash blocks carry `role`/`aria-live`, with assertive only for errors.
  - The offline banner and camera errors are `role="alert"`.
  - Roles y permisos checkboxes are named "Rol: permiso", and disabled owner boxes are named.
- **Contrast, target size, focus and motion**
  - Light-mode contrast on all counter states has no failures other than those listed above.
  - Quick-cash buttons, weight presets and PIN keys are all at least 44px.
  - PIN keys show the browser's focus ring in both themes (`pinpad-enter-on-digit-dark.png`).
  - `prefers-reduced-motion`: the only animation, the sign-up modal's pop, exists only under
    `no-preference`; the rest are colour transitions.
- **Prompts 267 and 268**
  - "Cambiar de persona" is a named button.
  - The tender's wallet row now appears only with a positive balance (268), and when shown it has a real label
    (`for="wallet"`).

### Dismissed on inspection

- **Filament toggles on Crear/Editar socio flagged as having no name.** They are `role="switch"` buttons
  labelled through `<label for>`, which works on buttons (`labels` shows "Terapéutico" and so on). This was a
  gap in the probe, not a defect.
- **Backdrop buttons with `aria-hidden` on the sign-up modal and the sheets.** They are `tabindex="-1"`, so they
  are not reachable and correctly hidden.
- **The panel sidebar's active item looks the same focused and unfocused.** This is vendor Filament styling, on
  a desktop panel.

---

## Discussion

1. **The focus-trap decision drifted from "deferred" to "rejected".**
   - August's report and DECISIONS (~l.8426) *deferred* the counter overlay focus trap. The concern was an
     `inert` being left on, and it wanted its own branch with a browser test.
   - Later entries (DECISIONS ~l.11012 for the sign-up modal, ~l.12980 for the receipt sheet) cite that as the
     audit having *rejected* trapping, and the no-trap behaviour is now asserted in a test.
   - I am not recommending reversing it. The Phase 2 item asks only for **initial focus in, focus back on
     close**. That has no "inert left on" failure mode, and the sheets already do it.
   - Whether a real trap is ever wanted is the owner's call. With `aria-modal="true"` and focus left behind the
     dialog, what a screen reader announces and what is focused disagree today.
2. **August's dismissal of the tile contrast was wrong.** It was the dark hover state (Phase 1, third item), not
   a rendering artefact. It is worth correcting in that report's record so the next pass does not dismiss it
   again.
3. **How to rank placeholder-only fields.** The audit spec ranks them as Phase 1. August ranked the bar's as
   Phase 2. I followed the spec, and in any case the POS cash field is a regression of an item already fixed.
4. **Fill tokens touch brand colour, so the owner should decide.** Phase 1's fill split means a darker amber, or
   dark text on amber, for warning buttons in dark mode, and `#dc2626` (already in the palette) for error fills.
   Light mode is unaffected.
5. **Limits of this pass.**
   - axe could not run (the package directory is empty). Re-running `tests/Browser/axe-sweep.mjs` after
     `npm install --no-save @axe-core/playwright` would add machine coverage of the rule set.
   - The signed-in PWA was reviewed from code only. Its screens were not changed since August apart from the
     application form, which was re-read.
   - Panel pages were probed at 1440 only.
   - One wrong PIN was entered deliberately as `staff`, to prove the first Phase 1 item.
