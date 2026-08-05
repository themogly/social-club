# Accessibility Audit — Phase C round

**Branch:** `a11y/audit-pass` off `main` @ `270088a`.
**Scope:** the new/changed surfaces since the last a11y pass — the `TimePicker` on the sede-create form (147,
never checked), the twice-moved admin topbar (143/151), and a re-confirmation of the prompt-98 contrast tokens
and prompt-130 44×44 target floor after several rounds of UI change. Method: inspected the rendered Blade +
components, the compiled utilities, and re-ran the structural topbar guard; cross-checked against the logged-in
computed-style measurement taken in the 151 branch.

**Headline:** no WCAG-AA blockers. The one genuinely new component (the `TimePicker`) is AA-compliant — a
labeled, keyboard-typeable text input. The topbar measurements re-confirm. One recommendation sits in
Discussion because acting on it would override a documented design decision. No inline fixes required.

---

## PHASE 1 — Blockers (WCAG AA / assistive tech)

- **`TimePicker` (LocationForm, 147) — AA-compliant, no blocker.** Verified against the brief's three concerns:
  (1) *keyboard entry without the picker* — `native(false)` renders a real `<input>` the user types into
  ("06:00"); the dropdown is an enhancement, not the only path, so keyboard-only entry works; (2) *label* —
  `->label('Corte del día operativo')` is associated with the input, not a placeholder; (3) *value announced* —
  the labeled input's value is in the accessibility tree. The `helperText` ("p. ej. 06:00") guides the format.
  So it passes AA. (The stronger-SR alternative is in Discussion — it conflicts with a documented decision.)
- **Topbar (143/151) — re-confirmed.** The structural guard (`PanelThemeSourceTest`, `DashboardScreenTest`)
  passes on main, and the logged-in computed-style measurement from the 151 branch showed both locale segments
  at **31–32 × 24 px** (≥ the 24×24 floor), the active segment's `background-color` = `oklch(0.598 …)` (the
  primary — non-transparent, and ≠ the inactive one), inner ES/EN gap ~2px, group gaps 16–18px, and **no overlap
  among the top-right controls at 1280 / 1024 / 800**. `aria-pressed` is correct (true active / false inactive).
  These are the exact figures the work order asked me to re-confirm; the audit and the measurement agree.
- **Public application form (the main public a11y surface) — clean.** 14 associated `<label>`s (every input,
  select and the two consent checkboxes wrapped in `<label>`), one `<h1>`, a single `<main>` landmark in the
  socio layout, `lang` on `<html>`, and the language switcher is real `<button>`s. No unlabeled controls.

Review: No blockers. The new component and the churned topbar both pass; nothing to fix in Phase 1.

## PHASE 2 — Important

- Nothing to fix. Focus indicators are present (Filament + the socio components carry focus rings — the locale
  segments even add `focus-visible:ring-2`), buttons are `<button>` and links are `<a>`, the page `<title>` is
  per-screen, form errors surface through Filament's field-level messaging. `ColourContrastTest` guards the
  prompt-98 tokens at the markup level (locale targets ≥ 24px, no single-line `nowrap` on card labels).

Review:

## PHASE 3 — Polish

- Nothing to fix. `prefers-reduced-motion` is gated on the motion effects; touch targets on the counter are the
  44×44 floor (130/132, guarded by `measure-topbar.mjs`); `aria-live` is used where status changes matter.

Review:

---

## Discussion (documented decision — NOT changed here)

- **`TimePicker` `native(false)` vs `native(true)`.** The custom (branded) picker is AA-compliant as shown. A
  NATIVE `<input type="time">` would give the strongest possible screen-reader + keyboard support with zero
  custom-widget risk, at the cost of the branded look. But `native(false)` is a **documented decision** — the
  CLAUDE.md design rule is "native form controls replaced with branded components on desktop, native on touch",
  and the LocationForm is a desktop admin form. So this is not changed in the audit. **Recommendation for the
  owner:** on this specific, rarely-used, compliance-critical form, consider `->native(true)` — the SR-robustness
  win likely outweighs the branding on a form staff touch once per sede. Owner's call; flagged, not forced.

## OWNER / OPS

- None specific to accessibility. Automated Lighthouse/axe runs against the logged-in panel are an owner/CI
  nicety; the panel is behind auth (no public page to score), and the public form's structure is verified above.
