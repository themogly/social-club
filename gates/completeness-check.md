# Completeness Check (report-only — catches what's ABSENT)

Reusable completeness inventory. READ-ONLY: changes nothing, produces one report. Read the project's `CLAUDE.md` first.

## Why this exists
Tests, audits and QA all verify that what EXISTS works correctly. NONE of them catch a feature that was intended but quietly shipped as a placeholder, stub, "coming soon", hardcoded dummy, or was never built — because a tidy placeholder is not technically *wrong*, so it passes every check. Missing-on-purpose looks identical to done. This check hunts for exactly that: absence, not malfunction. (On the project this came from, it caught a homepage "live Instagram feed" that was just a note saying "ask to enable".)

## Method
- `git checkout main && git pull`, state the commit. Do NOT change code or fix anything.
- If the project was ported/derived from a mock or another app, compare the INTENDED feature set against what's actually wired up. Otherwise infer intent from routes, views, CMS fields, comments, and any spec/DECISIONS.
- Walk EVERY public page and EVERY admin area in the browser AND read their source. For each, ask: is everything here REAL and functional, or is anything a placeholder / stub / dummy / dead button / "coming soon" / hardcoded sample / TODO?

## Hunt for (report each with page + evidence + severity)
- **Placeholder integrations** — social feeds, maps, reviews/chat widgets, analytics, anything that says "connect"/"enable"/"ask to" or is visibly inert.
- **Dead or non-functional UI** — buttons/links to nowhere or `#`, forms that don't submit, tabs/sections with no content, CTAs that 404, social icons linking to `#` or a default URL.
- **Hardcoded dummy content masquerading as real** — fake phone numbers, `example.com` emails, lorem ipsum, placeholder addresses, sample testimonials/stats/prices left in code rather than CMS/seed. (Distinguish intentional seed/demo data from things that should be real.)
- **Stubbed features** — a route/controller/method returning a "not implemented"/empty/coming-soon state; a nav item pointing at a thin or missing page; a toggle hiding something unfinished.
- **Half-wired flows** — a journey that dead-ends, a missing step, a "view all"/"more"/pagination/search control that does nothing.
- **Content gaps that look broken vs intentional fallback** — empty sections, missing-image fallbacks (note which need real owner content vs which are bugs).
- **Markers** — grep `app/ resources/ routes/ config/` for `todo|fixme|hack|placeholder|coming soon|not implemented|ask to enable|@todo` (ignore vendor); report the real ones.

## Report (`COMPLETENESS-CHECK.md`), grouped by severity
- **Incomplete — needs a decision/build before launch:** a feature meant to work but doesn't. For each: what it is, where, what it does now vs what was intended, and the options (build / replace with something simple / cut).
- **Owner content tasks:** real features that just need the owner to fill in real data (photos, address, copy, `[Owner]` placeholders) — so they're not confused with defects.
- **Intentional / fine:** placeholders or empty states that are correctly deliberate — listed briefly so the owner can confirm they agree.

Be honest and specific; cite the page/file for every item. Don't pad — if the site is largely complete, say so and keep the list tight. Commit just the report. End with a count of genuine incomplete-features and a one-line recommendation (build / simplify / cut) on each.
