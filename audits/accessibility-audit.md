# Accessibility Audit (report-first, then fix in phases)

Reusable WCAG/a11y audit. Run in a fresh session against a project with a running local site. Read the project's `CLAUDE.md` and the `frontend-design` skill first. This targets the accessibility issues that AI-builder output and quick builds reliably miss — the same set Lighthouse / PageSpeed flags (missing labels, bad ARIA, low contrast, no landmarks).

## Method
- `git checkout main && git pull`, branch `a11y/audit-pass`. State the starting commit.
- Audit by INSPECTING the rendered markup + the Blade/components, AND by running an automated pass: Lighthouse/axe (e.g. `npx @axe-core/cli <url>` or Lighthouse via Playwright) against every significant page. Use automated results as a STARTING checklist, then verify each by hand — automated tools catch ~30–40% of issues; the rest need judgement.
- Test keyboard-only (tab through every page/flow) and check focus visibility. Check a screen-reader pass mentally via the accessibility tree if a real SR isn't available.
- Audit every page type + every form/flow (forms are the worst offenders).

## Step 1 — Report FIRST (before any fixes)
Write `audits/reports/accessibility-audit.md` using `- [item]: [what's wrong] → [what it should be] → [why it matters]`, organised PHASE 1 / 2 / 3, each phase ending with a `Review:`. Commit the report before fixing. Be honest and tight; don't invent issues.

### PHASE 1 — Blockers (fails WCAG AA / breaks assistive tech)
- **Form labels:** every input, textarea, AND `<select>` has an associated `<label>` (via `for`/`id` or wrapping), or a proper `aria-label`/`aria-labelledby` where a visible label genuinely can't exist. Placeholder text is NOT a label. (Lighthouse: "Form elements do not have associated labels", "Select elements do not have associated label elements".)
- **ARIA correctness:** `aria-*` attributes match their element's role; no prohibited ARIA attributes on elements that don't support them; no role/attribute mismatches. Often from ported component libraries (shadcn/Radix) or ARIA added where native HTML would be correct. Prefer native semantic elements over ARIA where possible ("no ARIA is better than bad ARIA").
- **Colour contrast:** text vs background meets WCAG AA (4.5:1 normal text, 3:1 large text/UI). Check text over images/overlays (needs a scrim), muted/grey text, button states, placeholder text, autofilled inputs (dark themes — Chrome repaints them light). (Lighthouse: "Background and foreground colors do not have a sufficient contrast ratio".)
- **Landmarks & structure:** exactly one `<main>` landmark per page; proper `<header>`/`<nav>`/`<footer>`; logical heading order (one `<h1>`, no skipped levels). (Lighthouse: "Document does not have a main landmark".)
- **Images:** meaningful images have descriptive `alt`; decorative images have empty `alt=""` (not missing).
- **Keyboard:** every interactive element is reachable and operable by keyboard; no keyboard traps; custom controls (dropdowns, modals, accordions) work with keyboard.

### PHASE 2 — Important
- Visible focus indicators on every focusable element (don't remove outlines without replacing them).
- Link/button text is descriptive (no bare "click here"/"read more" without context); buttons are `<button>`, links are `<a>` (not divs with click handlers).
- `lang` attribute on `<html>`; page `<title>` is meaningful and unique per page.
- Modals/overlays manage focus (trap focus while open, return it on close, `aria-modal`); skip-to-content link.
- Form errors are associated with their fields and announced (`aria-describedby`, `aria-invalid`).

### PHASE 3 — Polish
- Reduced-motion respected (`prefers-reduced-motion`); touch targets ~44px; sensible tab order; status messages use `aria-live` where needed.

## Step 2 — Fix in phase order
Phase 1 first, gate between phases. One commit per logical fix. Reuse shared components so a fix lands everywhere at once (e.g. fix the form-field component, not each form). Run the project's check gate green before EVERY commit. Re-run the automated pass after fixes to confirm the flagged items clear; spot-check by keyboard. Prefer NATIVE semantic HTML over ARIA. Don't change the visual design beyond what contrast/focus require — and where contrast forces a colour change, note it for the owner if it affects brand colours.

## Finish
Update the report marking each item done/deferred; note any contrast fixes that touched brand colours (owner may want to confirm). Re-run Lighthouse/axe and record the before/after. Check gate green, DECISIONS.md updated, push the branch, do not merge.
