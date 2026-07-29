---
name: frontend-design
description: >
  Craft-level UI design principles for building or refining web interfaces so
  they look intentional and professional rather than templated or AI-generated.
  Use this whenever creating, redesigning, or polishing any frontend/UI — pages,
  components, layouts, design systems, Tailwind/CSS work, or when a build feels
  generic and needs to be levelled up. Covers type scales, spacing systems,
  colour and mood, buttons and inputs, components (cards, modals, search,
  carousels), pricing pages, empty/loading/error states, hierarchy, and
  responsive verification. Brand-agnostic principles; adapt to each project's
  own palette and identity.
---

# Frontend design — craft principles

The gap between a "templated / AI-generated" UI and a professional one is almost
never the brand or the colours — it's the **craft-level execution**: spacing
rhythm, a real type scale, intentional hierarchy, restraint, and polished
component detail. This skill encodes those principles. They are transferable;
apply them inside whatever palette and identity a project already has. Be bold
at the craft level (re-space, re-scale, rebuild components) while staying inside
the established brand — refinement, not rebrand.

## The core mindset

- **Intentional, not arbitrary.** Every size, space, and weight should come from
  a system, not a one-off guess. Magic numbers and ad-hoc values are the main
  tell of a templated UI.
- **Restraint reads as premium.** Fewer fonts, fewer colours, fewer effects,
  more whitespace. When unsure, remove rather than add. Overusing gradients,
  shadows, and borders looks cheap; a thin line separates "visually interesting"
  from "noisy".
- **Hierarchy guides the eye.** The user should always know what's most
  important on a screen. Achieve it with size, weight, colour, and space — not
  decoration.
- **Verify by looking, across sizes.** Layout correctness can't be unit-tested;
  it's a visual check. Screenshot across a RANGE of widths (e.g. 1440/1280/1024/
  390 and a short laptop height), never just two — two-width checking overfits
  layouts and hides in-between breakage. Non-visual changes need no screenshots.

## Typography

- **Use a modular type scale**, defined as tokens. A clear hierarchy with
  deliberate steps: display / h1 / h2 / h3 / body-large / body / small / caption,
  each with its own size + line-height + weight + (where useful) letter-spacing.
  Reference Material Design's type-scale tokens (web) and the iOS HIG Dynamic
  Type sizes (mobile) for sensible step ratios and line-heights.
- **Limit fonts** — usually one or two families. A strong display/heading face
  plus a highly readable body face is plenty. Load them efficiently
  (preconnect/preload, `font-display: swap`, avoid layout shift).
- **Avoid the AI-default font cluster for anything carrying identity.** A small set
  of free faces — **Inter, Space Grotesk, Geist** (also Plus Jakarta Sans, Manrope) —
  is what AI builders and template generators reach for by default, so they now read
  as "AI-era template" however well-made they are. A common face used well still beats
  a rare one used badly — but for a brand whose product IS design/craft, sitting on the
  default shelf quietly undercuts the pitch. Pick a display/heading face with more
  character (a distinctive grotesque, an editorial serif, a humanist sans with
  personality), chosen for THIS product. And make the wordmark **ownable**: a logo
  that's just the brand name typed in the heading font is the most replaceable kind of
  mark — give it bespoke tracking, a modified letterform, or a custom glyph standing in
  for a letter, then outline it to vector paths so it's font-independent and the
  underlying face stops carrying the identity.
- **Self-host any brand/display/character font — don't depend on machine fonts.**
  A system stack (`ui-monospace, SF Mono…`, `system-ui, -apple-system…`) renders
  as whatever face the visitor's OS happens to have — different on every device,
  unjudgeable by you, and impossible to control. That's fine ONLY as a deliberate
  choice for plain body where the exact face doesn't matter, or as a *fallback*.
  Any font carrying identity — headings, display, a distinctive mono/label layer,
  anything that makes the design feel intentional — must be a real **self-hosted**
  webfont so it's identical for every user. Self-host (woff2, served from the
  app's own origin) rather than hot-linking Google Fonts (privacy + an extra
  render-blocking third-party request). **Vendor the woff2 into the repo** (commit
  the files, e.g. via `@fontsource`) so the build only bundles assets already on
  disk — a plugin that *fetches* webfonts at build time (a webfont Vite plugin, a
  CDN import) is NOT self-hosting: the fetch fails silently when the build has no
  network and every heading drops to the fallback face. Always set an explicit fallback stack so a
  failed font load degrades to readable text, never invisible/broken — `font-display: swap`,
  never block. Ship only the weights actually used (and mind families with gaps,
  e.g. Space Mono has no 500 — a `font-weight:500` silently resolves elsewhere).
  This is the same lesson as icons and assets: never rely on something just
  happening to be present on the machine.
- **Readability first.** Body text wants a comfortable measure (~45–75 characters
  per line) and generous line-height (~1.5 for body). Don't let text columns run
  full-width on large screens — cap the measure.
- **Consistent steps.** Headings should step down clearly and consistently; avoid
  near-identical sizes that look like a mistake. Weight and colour can carry
  hierarchy as much as size.

## Spacing & layout

- **Adopt a spacing scale** (a base unit with a rhythm, e.g. 4/8px-based) and
  apply it everywhere — section padding, card padding, grid gaps, stack spacing.
  Similar sections should share the same rhythm.
- **Whitespace is a feature, not wasted space.** Generous, consistent spacing is
  one of the highest-leverage "professional polish" levers. Tighten what's
  cramped; open up what's crowded.
- **Align to a grid.** Consistent gutters and a content max-width keep things
  feeling ordered. Avoid tiles that strand or stretch awkwardly at in-between
  widths.
- **Group related things, separate unrelated things** (proximity). Spacing should
  communicate structure before borders or backgrounds do.

## Colour & mood

- **Palette discipline.** Work from the project's defined palette; never invent
  new shades ad-hoc. A small set of brand colours + a neutral ramp (greys) +
  semantic colours (success/warning/error) is usually all you need.
- **Mood comes from hue + restraint.** Warm hues feel inviting/energetic; cool
  hues (blues) feel calm/ordered/trustworthy. Subtle shifts in hue, corner radius
  and typography change the entire feel of an interface — small changes, big
  effect.
- **Contrast for accessibility.** Body and UI text must clear WCAG AA. Don't put
  low-contrast grey text on coloured or photographic backgrounds without a scrim.

## Gradients, shadows, corners

- **Gradients** add depth and modern flair but must be used in moderation and
  blend cleanly — abused or clashing gradients look cheap. Subtle is the rule.
- **Shadows** convey elevation; keep them soft and consistent (a small shadow
  scale), not heavy or applied to everything.
- **Corner radius** is part of the brand voice (sharp = structured/serious;
  rounded = friendly/soft). Pick one system and apply it consistently — mixing
  radii reads as careless.

## Images & media (performance is a design concern)

Images are usually the heaviest thing on a page and the biggest driver of poor
load performance (LCP / Core Web Vitals). AI builders and imported mocks are
especially bad here — they drop in huge unoptimised hero images and oversized
stock. Treat image performance as part of doing the design properly, not a
separate "optimisation" afterthought:

- **Format: serve WebP (or AVIF) wherever possible.** It's dramatically smaller
  than PNG/JPEG at equivalent quality. Keep an original/fallback where a client
  genuinely needs it, but WebP is the default for photographic content. Never
  ship a multi-MB PNG/JPEG hero when a ~100–300KB WebP looks identical.
- **Size to the slot.** Don't ship a 4000px image into a 600px container.
  Generate appropriately-sized variants; the file's pixel dimensions should match
  (roughly, ×DPR) how big it actually renders.
- **Responsive images.** Use `srcset`/`sizes` (or `<picture>`) so phones get a
  small file and large screens get a sharp one — don't send the desktop hero to a
  390px phone.
- **Lazy-load below the fold** (`loading="lazy"`), but NOT the LCP/hero image —
  that one should load eagerly (and consider preloading it) so first paint is fast.
- **Always set explicit `width`/`height` (or an aspect-ratio box)** so images
  reserve their space and don't cause layout shift (CLS) as they load.
- **Never carry over an AI builder's raw inline images** — replace with optimised,
  correctly-sized assets (or placeholders to be supplied), and route real uploads
  through an optimisation pipeline (see the admin-design skill).
- Decorative/vector UI → SVG (tiny, crisp); photos → WebP. Don't use a giant
  raster where a vector or CSS would do.

- **Clear hierarchy:** one primary action per context, secondary/tertiary styled
  down. Don't make everything look clickable-important.
- **One shared button system** — variants (primary/secondary/ghost/destructive)
  and sizes defined once, reused everywhere. One-off buttons are a templating
  tell.
- **Text alignment to round numbers.** Match button height and font size so the
  label sits optically centred (e.g. don't pair a 32px button with 17px text and
  hope) — adjust to clean values.
- **Icons as a visual cue.** When using an icon with a label, lead with the icon
  for scannability when it aids comprehension (icon-then-label for actions like
  "▶ Play video"); a trailing icon makes sense for directional/destination
  actions ("Log out ⎋"). It's contextual, not a universal rule.
- **States matter:** hover, focus-visible (a real visible focus ring), active,
  disabled, and loading. A button with no feedback feels broken.

## Inputs & forms

- Labels always present and associated; placeholders are not labels.
- Visible focus states; clear inline error messages with `role="alert"`; success
  and loading states for async submits.
- **Dark themes: handle browser autofill.** Chrome repaints autofilled inputs with
  its own light background and dark text, overriding your field styling — so on a
  dark form the filled fields turn light/unreadable while empty ones stay on-theme.
  Style `:-webkit-autofill` (force the field background with an inset `box-shadow`;
  set `-webkit-text-fill-color` + `caret-color` to the foreground token) in the
  shared field component so filled and autofilled states stay on-theme and AA-readable.
- Constrain form width to a comfortable single column on desktop — full-width
  stretched forms feel unfinished. Group fields logically; don't ask for more
  than needed.

### Native form controls (date/select/range/color)
Native HTML controls are functional and accessible for free, but they render in
the BROWSER's own style — ignoring your design system and looking different per
browser. An unstyled `<input type="date">` or default `<select>` is a common
"out of place" tell. Replace prominent ones with branded components, BUT:
- **Keep native on mobile/touch** where it's genuinely better — the OS date/
  select wheel has bigger targets, is familiar, and is accessible. A strong
  pattern: custom branded control on desktop (pointer), native on touch. Both
  paths must submit the SAME value/format.
- **A replacement must EARN BACK accessibility, not lose it.** A native date
  input is keyboard- and screen-reader-operable by default; a custom JS picker
  must replicate that (focusable, arrow-key navigation, Enter to select, Escape
  to close, correct ARIA) or it's a downgrade dressed as an upgrade.
- **Date-of-birth (and any far-back date) needs fast year/month jumping.** A
  picker that only steps one month at a time is unusable for a birthday — make
  the year directly selectable. This is the single most common custom-date-
  picker failure.
- It's a PRESENTATION swap: the submitted value, and the validation/error
  display, must be unchanged.

## Components

- **Cards:** consistent padding, radius, and shadow from the system; don't let
  content crowd the edges; image + text proportions should be deliberate.
- **Modals/dialogs:** dim/scrim the background, trap focus, escape to close,
  don't overload — one clear purpose.
- **Search:** add autocomplete/suggestions to speed input; ALWAYS design the
  "no results" state helpfully (suggestions, alternatives, popular searches) —
  never a bare "nothing found".
- **Sliders/carousels:** clear controls, visible affordance that more content
  exists, don't hide critical content behind interaction, respect
  `prefers-reduced-motion`.
- **Tables/lists:** align numbers right, give rows breathing room, make scannable.

## Pricing pages (high-intent, worth extra care)

- Make the recommended/most-popular tier visually distinct (subtle emphasis, not
  garish). Keep tiers easy to compare — aligned rows of features.
- State what's included plainly; avoid jargon. Price prominent and unambiguous.
- One clear primary CTA per tier. Reduce decision friction.

## Empty, loading & error states

- Design them deliberately — they're where templated UIs fall down. An empty
  state should guide the next action, not show a blank box. Loading should
  reassure (skeletons/spinners). Errors should be human and actionable.
- Intentional fallbacks for missing content (e.g. a designed placeholder when an
  image or CMS field is empty) — never a broken-looking gap.

## Motion

- Subtle and purposeful: transitions that clarify state change, not decoration.
- **Never gate content VISIBILITY on an animation or on JS.** Content must be present and visible by default; reveal/scroll/entrance animations are an *enhancement* layered on top, never the thing that makes content appear. The brittle anti-pattern (which AI-builder mocks ship constantly): elements default to `opacity:0` and only become visible when JS adds a class (e.g. an intersection-observer/`x-intersect` reveal). If that JS never fires — wrong scope, a failed bundle, an init bug — every "revealed" section stays invisible forever and the page looks broken/empty below the fold. Instead: visible by default; gate the hidden-then-animate state behind a `.js` signal (an inline `<head>` script adds `.js` to `<html>`; scope the pre-animation hidden state to `.js [data-reveal]`), so no-JS / failed-JS degrades to "shown, not animated." Reveals should also have a fallback that simply shows everything if the observer can't run.
- **Honour `prefers-reduced-motion` as "show the content WITHOUT the movement" — not as an afterthought reset.** The reduced-motion branch must land on the *visible* end state with no animation. Beware the common blanket reset (`@media (prefers-reduced-motion: reduce){ *{ animation-duration:.01ms!important; transition-duration:.01ms!important } }`): it forces every transition to its END state instantly, which means a broken hide-by-default reveal will appear *correct* under reduced motion (the instant transition drags it to `opacity:1`) while rendering BLANK under normal motion. So reduced-motion can silently MASK the visibility bug above — see the verification note in bootstrap: motion-gated UI must be checked with motion BOTH reduced and allowed, because a reduced-motion screenshot can photograph the broken state as the working one. Build it right (visible by default) and the same code satisfies the accessibility requirement and removes the blind spot at once.

## Accessibility (it's part of "done", not an extra)

Accessibility failures are invisible until a tool (Lighthouse/axe) flags them, and
AI-builder output reliably ships the same set. Build these in as you go — they're
cheap up front, tedious to retrofit:
- **Every form control has a real associated label** — inputs, textareas, AND
  `<select>`s. Use `<label for>`/wrapping, or `aria-label` only where a visible
  label genuinely can't exist. **Placeholders are not labels.** Fix this in the
  shared form-field component so it's right everywhere.
- **Semantic HTML first, ARIA second.** Use `<button>`, `<a>`, `<nav>`, `<main>`,
  `<header>`, `<footer>`, real headings. "No ARIA is better than bad ARIA" — only
  add `aria-*` when native HTML can't express it, and make sure attributes match
  the element's role (mismatched/prohibited ARIA is itself a failure).
- **Exactly one `<main>` landmark per page**, one `<h1>`, no skipped heading
  levels. Pages need a landmark structure, not a soup of divs.
- **Contrast meets WCAG AA** (4.5:1 text, 3:1 large text/UI) — including muted
  grey text, text over imagery (use a scrim), button states, and placeholders.
- **Keyboard + focus:** everything interactive is reachable and operable by
  keyboard; every focusable element has a visible focus indicator (never remove
  the outline without replacing it); modals trap and restore focus.
- **Images:** meaningful ones get descriptive `alt`; decorative ones get `alt=""`.
- Run axe/Lighthouse as a backstop, but it only catches a fraction — the rest is
  built-in discipline. (See the kit's `accessibility-audit.md` for a full pass.)

## Process

- **Audit before changing.** Inventory the current type/spacing/colour usage and
  where it's inconsistent; fix the system, then apply it — don't patch
  page-by-page.
- **Systematise, then apply.** Define tokens (type scale, spacing, colour,
  shadow, radius) once; replace ad-hoc values with them so the fix lands
  everywhere and can't drift.
- **Reuse shared components** so a change applies site-wide, not per page.
- **Small, reviewable passes** beat one sweeping redesign — easier to review,
  safer to roll back, less chance of regressing working screens.
- **Distinguish design defects from content gaps.** "Needs real photos / real
  copy" is an owner content task, not a design fix — list those separately and
  never fabricate content (especially never fake reviews/testimonials).

## Cross-page consistency (the drift that per-page review misses)

A specific, recurring failure mode on multi-page sites built incrementally: a
repeated element (button, link style, feature/benefit list, section heading/
eyebrow, divider treatment, card, spacing) ends up **different on different
pages** — yet each page, reviewed on its own, looks fine. No single page
"violates a rule", so per-page review (and screenshot-by-screenshot checking)
passes it. The inconsistency only shows when you put two pages side by side.

This happens because the pages were built or edited across separate passes, and
each pass made a local choice. It is the highest-frequency consistency bug in
incremental builds. Defend against it deliberately:

- **One shared component per repeated element.** If the same kind of thing
  appears on multiple pages (instructor card, feature list, CTA link, section
  header), it must be ONE parameterised partial used everywhere — never
  near-copies built per page. Near-copies are *guaranteed* to diverge over time.
  When you find two pages doing the "same" thing differently, the fix is usually
  "consolidate into one component", not "edit both to match".
- **Write the rule down so it's enforceable.** A rule that lives only in
  someone's head can't be checked. If "section transitions use spacing only, no
  dividers" or "feature lists use icon X" isn't written in the design-guidelines
  doc, nothing can flag a regression — it just drifts and gets caught by eye
  later (if ever). Every consistency decision should be recorded in the project's
  UI-guidelines doc, derived from what the build actually does.
- **Compare repeated elements ACROSS pages, not just within a page.** Make this
  an explicit step: take the same element (the CTA, the feature list, the section
  header) and view it on every page that has it, side by side. This is the only
  reliable way to catch cross-page drift, because the model/agent reviews pages
  largely in isolation and is strong at local correctness but weak at global
  sameness.

## Anti-patterns (the "templated / AI-generated" tells)

- Ad-hoc font sizes and spacing with no system behind them.
- Too many fonts, weights, or colours; inventing shades on the fly.
- Inconsistent corner radii / shadow treatments across similar elements.
- One-off buttons instead of a shared variant system.
- Overused gradients and heavy shadows.
- Full-width body text with no measure cap; cramped or wildly inconsistent
  section spacing.
- Undesigned empty/loading/error states; broken-looking gaps where content is
  missing.
- Layouts verified at only one or two widths, breaking at in-between sizes.
- A repeated element (button, list, divider, heading, card) styled differently
  on different pages — cross-page drift from per-page building; caught only by
  comparing pages side by side.
- Decoration standing in for hierarchy.
