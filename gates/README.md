# Gates

Report-only checks. Unlike the `audits/` (which fix things), these change NOTHING — each produces a single report you read before deciding to proceed. They catch the two things automated tests structurally can't.

## The gates
- **completeness-check.md** — catches what's ABSENT: features intended but shipped as a placeholder/stub/"coming soon"/dead button/dummy, or never built. Tests verify what exists works; this finds what's missing-but-looks-done. Run when the build feels "finished", before launch.
- **cms-field-usage-check.md** — catches admin↔front-end DRIFT: CMS/admin fields that are still editable but no longer rendered anywhere (orphaned when a front-end section was removed/rebuilt), plus the reverse (content hardcoded that should be editable). Run after a round of UI restructuring, and before launch.
- **pre-staging-gate.md** — a go/no-go before deploying: repo clean, check gate green, every go-live item actually present IN THE CODE (verified, not claimed), production config safe, plus the server checklist and the post-deploy-only verification list. Run before provisioning a server; re-run after clearing any blockers it names.

## How to run
Open a fresh session, paste the gate file, read the report it writes. None of them merge or change anything. Typical order near launch: completeness-check + cms-field-usage-check → clear what they find → pre-staging-gate → clear blockers → re-run for a clean GO → provision server → the human pre-launch checklist (`../verification/`).

## Why these are separate from audits
Audits answer "is what we built sound?" and fix it. Gates answer "is anything missing?", "is the admin still wired to the front end?", and "is it safe to leave local?" — and only report. Different jobs, different folders.
