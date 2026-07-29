# UI Pass 1/4 — Design Foundations (type scale + spacing system)

Reusable. Read the project's `CLAUDE.md`, `DECISIONS.md`, and the `frontend-design` skill first. `git checkout main && git pull`, branch `ui/01-foundations`. State the starting commit.

## Why this exists
A functional-but-templated UI usually has fine branding but generic CRAFT — type sizes and spacing picked ad-hoc per element rather than from a system. This FIRST pass establishes the foundations (type scale + spacing) that passes 2–3 build on. Do it first; the others depend on it.

## Mandate / guardrails
- Bold at the craft level (you may drastically rework the type scale + spacing tokens and how they're applied) but stay INSIDE the project's existing brand — palette, corner-radius character, fonts, overall personality. Refinement and systematisation, NOT a rebrand. No new brand colours, no dark mode unless the project already has one.
- Everything stays component/token based; define shared tokens so the system applies everywhere, not per-page.
- Operating mode: decisions in `DECISIONS.md`, the check gate green before EVERY commit, never commit red, one commit per logical step. Verify by LOOKING (screenshots) across a range — 1440/1280/1024/390 + a short laptop height.

## Step 1 — Audit & report first (no changes yet)
Inspect the CSS/Tailwind config / components and report (to `ui-review/FOUNDATIONS.md`):
- Current type usage: what sizes/weights/line-heights/letter-spacing are in use, where defined, where ad-hoc/inconsistent (one-off sizes, magic numbers, inconsistent heading steps).
- Current spacing: section paddings, gaps, rhythm; where arbitrary or inconsistent between similar sections.
- Fonts: families/weights loaded, whether loading is optimised, any loaded-but-unused weights.
Commit the report before changing anything.

## Step 2 — Type scale
Define an intentional modular scale as tokens (display / h1 / h2 / h3 / body-large / body / small / caption), each with deliberate size + line-height + weight + letter-spacing. Reference Material's type-scale tokens (web) and iOS HIG Dynamic Type (mobile) for sensible step ratios and line-heights; readability first (comfortable body line-height, ~45–75ch measure). Consider fluid (`clamp`) sizing so big headings stay bold on desktop without overflowing small screens. Apply the scale across components/pages, replacing ad-hoc sizes; preserve the brand's heading character — formalise it, don't replace its personality.

## Step 3 — Spacing system
Define a consistent spacing scale (a base unit + rhythm, e.g. 4/8px) as tokens and apply it so section rhythm, card padding, and grid gaps are consistent and intentional — similar sections share the same rhythm. Set a consistent content max-width / reading measure so text columns aren't too wide.

## Step 4 — Font loading & base
Confirm fonts load efficiently (preconnect/preload, `font-display: swap`, no layout shift); drop unused weights. Set sensible base body styles (size/line-height/colour) from the tokens — the base body line-height is often the single biggest readability win.

## Verify & finish
Re-check key pages (a few content pages + a form) across the size range — hierarchy reads clearly, body is more readable, rhythm is consistent, brand look unchanged. Screenshots into `ui-review/`. Document the new tokens in `ui-review/FOUNDATIONS.md` and note in CLAUDE.md that the type-scale + spacing tokens are canonical (new UI uses them, not ad-hoc values). Check gate green, commit `style: establish type scale + spacing system`, push, do NOT merge. Pass 1 of 4 — don't touch components/layouts beyond applying tokens.
