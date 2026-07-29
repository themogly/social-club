# Code-Style Audit (Laravel idiom & consistency — report-first, then fix)

Reusable Laravel code-style audit. NOT a redesign or feature change — behaviour identical before/after. Read the project's `CLAUDE.md`, `DECISIONS.md`, and the `laravel-craft` skill first.

## Method
- `git checkout main && git pull`, branch `chore/code-style-audit`. State the starting commit.
- Audit `app/`, `routes/`, `database/`, `config/`, `tests/`.
- Grounded in: convention over configuration; use what the framework gives you before writing helpers; simplicity / "the best abstraction is often no abstraction" / "it's not Spring"; YAGNI (remove dead code, no commented-out blocks); explicit over magic; fat models / thin controllers; fail loudly (no error-swallowing); expressive code, comments explain "why" not "what".
- **ENFORCE the project's documented decisions** (from CLAUDE.md) rather than relitigating them. Flag a genuine idiom-vs-decision conflict to a `## Discussion (needs owner decision)` section; never auto-undo a documented decision.

## Step 1 — Report FIRST (before any fixes)
Write `audits/reports/code-style-audit.md` using `- [item]: [what's wrong] → [fix] → [why it matters]`, organised PHASE 1 / 2 / 3, each ending with a `Review:`. Commit before fixing. Short honest report beats padded — few items on a clean codebase is a good result.

### PHASE 1 — Correctness & convention
Wrong-layer logic (fat controllers, logic in views), missing scopes/relationships/casts, inline validation that should be a Form Request (and vice-versa — don't over-extract simple single-use rules), N+1 risks, error-swallowing (catch-and-return-null), naming and idioms vs the CURRENT Laravel version's conventions (e.g. the `casts()` method over the `$casts` property; config/middleware in `bootstrap/app.php`, not a hand-kept `Http/Kernel.php` — AI-builder/ported output often carries superseded idioms), mass-assignment exposure, anything contradicting CLAUDE.md, and any genuine bug found (these rank first — a real defect in shipped output beats a style nit).

### PHASE 2 — Simplification & de-abstraction
Needless abstractions / interfaces with one implementation and no swap need, reinvented framework features, a "Service" that should be a model method or Action, two-ways-to-do-one-thing inconsistency (extract a shared Action), dead code, unused imports.

### PHASE 3 — Polish & readability
Clearer names, dense methods broken up, "what" comments removed, uniform class structure. Don't churn working, already-consistent code for taste.

## Step 2 — Fix in phase order
Pin behaviour with a test BEFORE refactoring; prove identical behaviour after. One commit per item. Check gate green before each; never commit red. Don't change behaviour, public APIs, routes, schema, or payment/webhook/booking logic except as internal refactors proven by tests. Money handling stays correct.

## Finish
Update the report marking each item done/deferred. Full suite green, static analysis + formatter clean, DECISIONS.md updated, push the branch, do not merge.
