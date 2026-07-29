# UI Pass 4/4 — Distil the UI Guidelines (docs only)

Reusable. Read `CLAUDE.md`, `DECISIONS.md`, `ui-review/FOUNDATIONS.md`, `ui-review/COMPONENTS.md`, `ui-review/PAGES.md`, and the `frontend-design` skill first.

## Prerequisite
Passes 1–3 merged — confirm on main. If a UI branch is still unmerged, document main's current state, not things not yet merged. Branch `ui/04-guidelines`. State the starting commit.

## Why this exists
Final pass. Passes 1–3 made visual changes; THIS pass makes NONE. It distils the design system that now exists in the code into one project reference, `ui-guidelines.md`, so future pages/features match the system instead of reinventing it. This is the per-project companion to the transferable `frontend-design` skill: the skill is the general craft, this doc is THIS project's concrete decisions (actual tokens, components, conventions).

## Scope: documentation only
Do NOT change any component, style, token, or page. If you spot a genuine inconsistency while documenting, NOTE it in the doc's "known gaps / follow-ups" section — do not fix it here (a fix is a separate branch). The only files this pass creates/edits are the guidelines doc and a CLAUDE.md pointer.

## Step 1 — Read the system out of the code
Inventory what passes 1–3 established (read the tokens, the components, the section patterns) so the doc reflects reality, not aspiration:
- Type scale: the tokens, sizes/line-heights, what each is for.
- Spacing: the section-rhythm tokens + convention, the reading measure, container widths.
- Colour: the real palette in use (values/tokens).
- Components: the shared UI components (buttons + variants/sizes/states, inputs, links, date/native fields) and the layout/section components — what each is and when to use it.
- Patterns: eyebrow/label conventions, section composition, key page structures, empty-state/fallback conventions, motion/reduced-motion, the focus-ring convention.

## Step 1b — Itemised consistency audit (ENUMERATE, don't "look for" — this is the step that actually catches drift)
"Scanning for inconsistencies" reliably MISSES things (a stray icon, one divergent colour, an odd divider) because it depends on happening to notice. Instead, for each repeated element TYPE, build a literal table of EVERY instance across the whole site and diff them. This forces the comparison structurally so the odd one out can't hide.

For each element type below, list every instance (page + location) as a table row, with columns for its key attributes, then flag any row that differs from the majority:
- **Section headings:** every section heading on every page → columns: has eyebrow? eyebrow text, has accent line?, has an icon? (should be NO), heading token/size, alignment. Flag any heading missing the eyebrow/accent line or carrying an icon others don't.
- **Eyebrows/labels:** list all → flag duplicates on the same page, off-style casing/colour, any not using the shared style.
- **Buttons & links:** every button and every link-style (e.g. arrow/"explore"/"meet the team") → which component/variant, colour, has the animated arrow? → flag any hand-rolled or divergent one.
- **Feature / "what's included" lists:** every one → icon used (should be identical), left-line? (should be consistent), spacing → flag mismatches.
- **Cards:** every card type → border/shadow/radius treatment → flag any not matching the standard card.
- **Section transitions:** every section boundary → divider line or spacing-only? → flag any using a divider if the rule is spacing-only (or vice-versa).
- **Section spacing rhythm:** which spacing token each section uses → flag hand-rolled padding.
- **Imagery:** instructor/portrait crops etc. → aspect ratio/treatment → flag inconsistent crops.

Write this audit into `ui-review/CONSISTENCY.md` as the tables + a clear "⚠️ odd ones out" list. This is the authoritative punch-list. (Per scope, this pass DOCUMENTS and FLAGS — it does not fix; each flagged item becomes a small follow-up branch. But nothing should be flagged that wasn't actually enumerated and compared.)

Do this on the MERGED, stable state only — if branches are unmerged, the audit is of a state that isn't real. Confirm main is current first.

## Step 2 — Write `ui-guidelines.md` (project root or `docs/`)
Concise and practical (not a novel):
- Type scale (token → use) + "use the scale, never ad-hoc sizes".
- Spacing system + rhythm + reading measure + "use the spacing tokens, not hand-rolled padding".
- Palette (real values) + "no new shades".
- Component catalogue: each shared component, purpose, variants, when to use — + "one shared system, no one-offs". Verify every component you name is a real file — grep for it. If a treatment is actually repeated inline (e.g. several copies of the same accent span) rather than abstracted into a component, document it AS inline and flag the consolidation as a follow-up; never name a component that doesn't exist in the code (an idealised catalogue describing phantom components is worse than none).
- Conventions: eyebrows, focus rings, native-control handling (branded desktop / native mobile, DOB year-jump), CMS-driven content + intentional empty states, reduced-motion.
- A short "adding a new page/section" checklist so future work matches the system.
- A "known gaps / follow-ups" section — pull the "⚠️ odd ones out" list straight from the Step 1b consistency audit (`ui-review/CONSISTENCY.md`) so every enumerated inconsistency is captured as a follow-up, not just things that happened to be noticed.
Example-led where useful (token names, component tags). It should be the thing someone reads before building new UI here.

## Step 3 — Wire it up
Add a CLAUDE.md pointer: "UI conventions: see `ui-guidelines.md` — the canonical design system for this project; the `frontend-design` skill is the general craft, this doc is our specifics." Point the reference-implementations section at the real components as canon.

## Finish
Check gate green (nothing should have changed functionally), DECISIONS.md noting the doc was created, commit `docs: ui-guidelines (the project design system)`, push, do NOT merge. Summarise what the doc covers and list anything in "known gaps / follow-ups" the owner may want as a future small fix.
