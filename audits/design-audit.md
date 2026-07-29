# Design Audit (report-first, then fix in phases)

Reusable design audit. Run in a fresh session against a project with a running local site. Read the project's `CLAUDE.md` and the `frontend-design` skill first.

## Method
- `git checkout main && git pull`, branch `design/audit-pass`. State the starting commit.
- Audit using the browser: viewport-sized screenshots only (never full-page; capture long pages per-section). You MUST look at the rendered pages, not just the code.
- **Test across a RANGE of viewport sizes, not just two.** Check at minimum 1440, ~1280, ~1024, 390, AND a short laptop height (e.g. 1440×560), AND a zoomed-in browser. Layouts overfit to two widths break at the in-between sizes — that is where most issues live.
- Audit EVERY public page at the range of widths: every marketing/content page, every form, every step of any multi-step flow (booking/checkout), success/empty/404 pages, and the authenticated account area if present.
- **Motion guard:** if the project has a documented motion layer (in `ui-guidelines.md`/`DECISIONS.md` — e.g. a GSAP/scroll-driven layer from `add-motion-layer`), treat it as DELIBERATE and canonical. Verify its non-negotiables — above-the-fold/LCP text visible without JS, per-effect `prefers-reduced-motion`, no layout-shift/perf regression — but do NOT flag, remove, or "normalize" the motion itself as drift or off-pattern. If the docs describe an OLD/replaced motion system, that's a doc-drift item (reconcile the docs), not a reason to revert working code.

## Step 1 — Report FIRST (before any fixes)
Write `audits/reports/design-audit.md` using `- [item]: [what's wrong] → [what it should be] → [why it matters]`, organised PHASE 1 / 2 / 3, each phase ending with a `Review:` rationale. Commit the report before fixing. Be honest and tight — a clean codebase yielding few items is a good result, not a failure; do not invent problems or churn working code for taste.

### PHASE 1 — Critical
Broken/awkward layouts, responsiveness breakage at in-between sizes (full-viewport-height heroes that become one-screen walls; text+image two-column sections that collapse to an orphaned tall image; stranded/stretched grid tiles), visual-hierarchy failures, off-palette colour, one-off buttons, banned treatments (per the project's design rules), and accessibility failures (AA contrast, missing focus states, unlabelled inputs, missing alt text).

### PHASE 2 — Refinement
Spacing/rhythm consistency, type scale application, colour application, alignment, iconography consistency. Cluster-level issues, not taste nits. **Image performance:** flag oversized/unoptimised images — non-WebP/AVIF photographic assets, files far larger than their display slot (e.g. a multi-MB or 4000px image rendered at 600px), missing `srcset`/responsive variants, missing lazy-loading below the fold, and missing `width`/`height` (layout shift). Especially scrutinise hero/banner images and anything carried over from an AI-builder mock.

### PHASE 3 — Polish
Hover/focus transitions, reduced-motion-aware entrance animations, empty/loading/error/success states. Dark mode is N/A unless the project is explicitly dark-themed — say so.

## Step 2 — Fix in phase order
Phase 1 first, gate between phases. One commit per item. `composer check` (or the project's check gate) green before EVERY commit; never commit red. Reuse shared components so each fix lands across all affected pages at once. Re-screenshot each fix across the full size range to confirm it genuinely improved at all of them; if a change isn't clearly better, revert it. Stay inside the established design language and palette — refine, don't rebrand.

## Separate: content tasks
List "needs real photos / real copy / placeholder media" as OWNER CONTENT tasks in their own section — these are not design defects and must never be faked.

## Finish
Update the report marking each item done/deferred. Check gate green, DECISIONS.md updated, push the branch, do not merge — owner reviews.
