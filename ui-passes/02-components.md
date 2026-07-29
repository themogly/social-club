# UI Pass 2/4 — Buttons & Interactive Components

Reusable. Read `CLAUDE.md`, `DECISIONS.md`, `ui-review/FOUNDATIONS.md`, and the `frontend-design` skill first.

## Prerequisite
Pass 1 (type/spacing tokens) must be merged — confirm on main; if not, STOP and say so. Branch `ui/02-components`. State the starting commit.

## Why this exists
Pass 1 systematised type + spacing. This polishes the INTERACTIVE layer — buttons, links, form inputs, native controls — to the same standard, applying the `frontend-design` principles: one shared button system; clean size/text relationships; icon-as-cue (lead for actions, trail for directional); real states (hover, active, focus-visible, disabled, loading); labelled inputs with visible focus and clear error/success/loading states.

## Guardrails
- Bold at the component level, inside the brand. Use pass 1's tokens (no new ad-hoc `text-[…]`/`py-[…]`; add a token if one's genuinely missing). ONE shared system per thing — reuse/extend the shared components so changes propagate; no one-off buttons.
- Check gate green before every commit, never red, one commit per step, regression after each. Verify by LOOKING across the size range. Styling-only changes are behaviour-preserving; if you touch a form's logic, pin with a test.

## Step 1 — Audit & report first (`ui-review/COMPONENTS.md`)
- Buttons: variants/sizes in use, one-offs outside the shared system, inconsistent padding/height/text/weight, icon usage, and whether states are defined and consistent.
- Links: styled vs unstyled, focus states, the "text link with arrow" pattern.
- Inputs (text/select/textarea/checkbox/radio): consistency of height/padding/border/radius/focus, label association, placeholder-as-label misuse, error/success/loading handling across all real forms.
- Native controls: any `<input type="date">`, default `<select>`, etc. that render in the browser's own style and look out of place.
Commit the report before changing anything.

## Step 2 — Buttons
One shared system: clear variants (primary/secondary/ghost-or-outline/destructive) and a small set of sizes, defined once; replace one-offs. Sizing + label relate cleanly; labels use the type scale; optically centred. Icons: lead for actions where it aids scanning, trail for directional; consistent size/gap; icon-only buttons get an accessible label. All states on all variants incl. a visible focus-visible ring; respect `prefers-reduced-motion`.

## Step 3 — Form inputs
One consistent input style (height/padding/border/corners) with a visible focus state; selects/textareas match. Every input has an associated label. Clear inline error (`role="alert"`), success, and loading/disabled-on-submit — verified on the REAL forms. Constrain form width to a comfortable column (not full-bleed).

## Step 4 — Native controls, links & small interactive bits
- Replace prominent native controls with branded components on DESKTOP, keep native on MOBILE/touch (better there); both submit the same value; don't lose native's built-in accessibility. Any date-of-birth picker MUST allow fast year selection. (See the `frontend-design` skill's native-controls guidance.)
- Consistent styled-link treatment (incl. the "read more →" arrow pattern) with hover + focus.
- Any tabs/toggles/accordions get consistent, accessible interactive styling reusing the same focus/transition conventions.

## Verify & finish
Re-check every form + the main button-bearing pages across the size range; confirm buttons are consistent + stateful, inputs uniform + accessible, focus rings visible on keyboard nav, brand intact — and that forms still SUBMIT and validate (test an invalid submit). Screenshots into `ui-review/`. Update `ui-review/COMPONENTS.md`; note any new tokens. Check gate green, commit `style: consolidate + polish buttons, inputs and links`, push, do NOT merge. Pass 2 of 4 — don't restructure page layouts (pass 3).
