# Audits

Report-first review passes. Each is run on demand in a fresh session by pasting its file. They VERIFY the build is sound — the skills (in `../skills/`) make the code right as it's written; these audits confirm nothing slipped.

## How to run one
1. Open a fresh Claude Code session in the project (`claude --dangerously-skip-permissions`, your model of choice).
2. Paste the contents of one audit file.
3. It branches off main, writes a report to `audits/reports/<name>.md` FIRST, then fixes in phases (Critical → Refinement → Polish), and leaves the branch unmerged for your review.

## The audits
- **design-audit.md** — responsive/layout/hierarchy/accessibility/consistency, tested across a RANGE of viewport sizes.
- **accessibility-audit.md** — dedicated WCAG/a11y pass: form labels, ARIA correctness, colour contrast, landmarks/headings, keyboard, focus, alt text. Targets the set Lighthouse/PageSpeed flags. Automated (axe/Lighthouse) + manual.
- **admin-audit.md** — CMS/admin-panel quality: image-shape control, validation the site/checkout depends on, no exposed dangerous/internal fields, no orphaned fields, admin/public theming separation, owner-friendly UX.
- **code-style-audit.md** — Laravel idiom & consistency; enforces (doesn't relitigate) the project's documented decisions.
- **seo-audit.md** — technical + on-page SEO; inventories first, real data only in structured data.
- **security-audit.md** — security + privacy (access control/IDOR, secrets, PII/GDPR, payments, webhooks, headers, private monitoring).

## When to run
Periodically — after every few features, not only at the end. Drift caught early is a one-line fix; caught at the end it's a whole audit round. They are report-first and stay unmerged until you review.

## Principles (all share)
- Report before fixing; gate fixes behind the committed report.
- Short honest report beats a padded one — few items on a clean codebase is a good result, not a failure.
- Separate real defects from OWNER tasks (content, legal copy, infra) — never fabricate content.
- Never undo a documented decision; escalate conflicts to a Discussion section.
- Check gate green before every commit; never commit red; pin behaviour-preserving refactors with a test first.
