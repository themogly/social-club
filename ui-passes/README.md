# UI Passes — the craft level-up sequence

Four sequential passes that take a functional-but-templated UI to an intentional, professional one. Use them when a build looks generic / AI-generated and needs craft (very common after a mock-builder like Lovable/v0, or on any quickly-built UI). They apply the `../skills/frontend-design` principles as concrete, staged work.

## Why a sequence, not one pass
One giant "level up the whole UI" pass is the riskier choice with an autonomous agent — a huge diff, no clean rollback, and a real chance of making working screens worse. Each pass here branches separately, is reviewed and merged on its own, and builds on the one before. Order matters: you can't polish components consistently until the tokens exist, and you can't document the system until it's built.

## The passes (run in order, merge each before the next)
1. **01-foundations** — establish the type scale + spacing system as tokens. Everything downstream depends on this; merges first.
2. **02-components** — consolidate + polish buttons, inputs, links (and date/native controls) into one shared system with full states, using pass 1's tokens.
3. **03-pages** — page-level layout, hierarchy and rhythm; restructure sections that read as generic. The pass allowed to make the most visible structural change.
4. **04-guidelines** — DOCS ONLY (no visual change): distil what passes 1–3 built into a project `ui-guidelines.md`, the per-project companion to the `frontend-design` skill.

## Guardrails (in every pass)
- Bold at the CRAFT level, but stay inside the project's brand — refinement, not a rebrand. No new brand colours / dark mode unless the project already has them.
- Audit/report first; use tokens not ad-hoc values; reuse shared components so fixes propagate; one shared system per thing (no one-off buttons).
- Verify by LOOKING across a RANGE of sizes (1440/1280/1024/390 + a short laptop height), never just two. Check gate green before every commit; never commit red. Branch per pass, unmerged until reviewed.
- **Motion guard:** if the project has a documented motion layer (`ui-guidelines.md`/`DECISIONS.md` — e.g. from `add-motion-layer`), it's DELIBERATE/canonical. Verify its non-negotiables (LCP/above-fold text visible without JS, per-effect `prefers-reduced-motion`, no layout-shift) but do NOT flag the reveals/scroll behaviour as inconsistency or normalize them away. Pass 04: document the motion layer AS-BUILT; if the doc still describes an old/replaced system, reconcile the doc — don't touch the code.

## When to run
After features are built and stable — polish the real thing once, not a moving target. If the project started from a mock, treat the mock as a visual spec and run these after re-architecting (see `../bootstrap.md`). Each pass references the project's `CLAUDE.md` and the `frontend-design` skill.
