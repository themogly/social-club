# UI Pass 3/4 — Page Layout, Hierarchy & Composition

Reusable. Read `CLAUDE.md`, `DECISIONS.md`, `ui-review/FOUNDATIONS.md`, `ui-review/COMPONENTS.md`, and the `frontend-design` skill first.

## Prerequisite
Passes 1 (tokens) AND 2 (components) must be merged — confirm on main; if either isn't, STOP and say so. Branch `ui/03-pages`. State the starting commit.

## Why this exists
Passes 1–2 systematised type/spacing and polished components. This works at the PAGE level — section composition, visual hierarchy, rhythm between sections, and layout structures that still read as generic. This is the pass allowed to make the most VISIBLE structural change (restructure sections, change column splits, re-order content). Apply the `frontend-design` principles: hierarchy guides the eye via size/weight/colour/space (not decoration); generous consistent rhythm; restraint; intentional composition.

## Guardrails
- Bold at the layout level, inside the brand (refinement, not rebrand; no new colours / dark mode unless already present). Use pass 1 tokens and pass 2 components — no ad-hoc values or one-off buttons. Content stays component/CMS-driven; reuse shared section components. Empty states stay intentional.
- Check gate green before every commit, never red, one commit per change, regression after each. Verify by LOOKING across the size range.

## Step 1 — Audit & report first (`ui-review/PAGES.md`)
Walk every page at the size range and assess per page/section:
- Hierarchy: is the most important thing clearly dominant? Headings/leads/body in a clear relationship (now the type scale exists)?
- Rhythm: is vertical spacing between sections consistent + breathing (using the spacing tokens)? Flag sections still hand-rolling padding.
- Composition: are column splits/grids/image-text balances intentional, or generic/awkward? Note anything that reads as a default template block.
- Note any section that was added AFTER the main build (e.g. a late FAQ, a new module) — these are easily missed; include them explicitly.
Commit the report before changing anything.

## Step 2 — Hierarchy & rhythm (all pages)
Apply the type scale so each page has one clear hierarchy, consistent page-to-page. Normalise section rhythm to the spacing tokens so cadence is even; fix cramped or drifting sections. Tighten generic-looking composition (column splits, grid balance, image/text proportion) using whitespace + hierarchy, not added decoration. Migrate remaining ad-hoc `text-*`/`py-*` to tokens as you touch each section; tidy any letter-spacing spread toward a small consistent set.

## Step 3 — Restructure the sections that read as generic
For any block the audit flagged as backwards/weak/template-y, restructure it for intentional hierarchy (give the valuable/dynamic content the dominant space; subordinate the secondary). Remove anything dishonest or inert (e.g. a placeholder "live feed") — replace with an honest, on-brand version. Constrain content to the reading measure where rows/text run uncomfortably wide. Stack sensibly on mobile (test 390). Flag any real-content needs as owner tasks.

## Step 4 — Cross-page consistency sweep (do this deliberately — it's the easily-missed one)
Repeated elements must look IDENTICAL across pages; per-page review misses this because each page looks fine alone. Go element by element, not page by page: take each repeated element and view it on EVERY page that has it, side by side, and reconcile any that differ. Cover at least: heroes, section headers/eyebrows, CTAs and link styles (e.g. the arrow/"explore" link), buttons, feature/benefit lists (icon + row treatment), section-transition treatment (dividers vs spacing — pick ONE site-wide), cards, and section spacing rhythm. Any element that differs per page should be CONSOLIDATED into one shared component (not edited to match on each page — near-copies just re-drift). Record each consistency decision in `ui-guidelines.md` (and pass 4) so it's written down and a future pass can enforce it — an unwritten rule can't be checked. Confirm empty/loading states are intentional.

## Verify & finish
Re-check every page across the size range, plus any restructured block at 1440/1024/390. Screenshots into `ui-review/`. Update `ui-review/PAGES.md`; update CLAUDE.md if conventions changed; note new tokens. Check gate green, commit (`style:`/`feat:` as appropriate), push, do NOT merge. Pass 3 of 4 — pass 4 distils the conventions into `ui-guidelines.md`.
