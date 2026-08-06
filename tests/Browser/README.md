# Browser checks

Pixel-level checks that a headless PHPUnit render cannot make. Playwright is **not** a CI dependency (it needs a
~100 MB browser), so these are run by hand and their result recorded in `DECISIONS.md`. The PHP side
(`TopbarHarnessTest`) runs in `composer check` and guards the STRUCTURE; the `.mjs` scripts measure the pixels.

## Counter top-bar bounding boxes (prompt 132)

Proves **no two interactive controls in the counter top bar intersect**, and **none is under 44×44**, at
768 / 800 / 1024 / 1280 — the check prompt 130 lacked (its structural test passed while a real browser showed a
70 px `Caja`∩`Panel` collision at 1024). The overflow-menu layout removes the wide fixed secondary group, so
the widened five-destination row cannot run into it at any width.

```bash
npm install --no-save playwright
node_modules/.bin/playwright install chromium-headless-shell
npm run build                                        # compile the CSS the harness inlines
php artisan test tests/Browser/TopbarHarnessTest.php # writes storage/app/topbar-harness.html
node tests/Browser/measure-topbar.mjs                # measures; exits non-zero on any overlap / sub-44px / page scroll
```

Last run (owner-authorised branch `fix/nav-overlap-remainder`): **ALL PASS** at all four widths — 7 controls
(five destinations + sede chip + overflow trigger), zero overlaps, none under 44 px, no horizontal page scroll.

## Counter blocking states (prompt 175)

Screenshots each dispensary blocking state — **sede, till, member** — at 1180×820 and 820×1180, light and
dark, motion reduced and allowed (24 captures), and asserts the three things a picture cannot: **exactly one**
blocking state per screen, no **destructive colour** on a blocked state, and no action under **44×44**.

It also composes the **cold start before and after** side by side, which is the argument for the branch.

```bash
npm install --no-save playwright
node_modules/.bin/playwright install chromium-headless-shell
npm run build                                                  # the harness inlines the BUILT css — rebuild it
php artisan test tests/Browser/BlockingStatesHarnessTest.php   # writes storage/app/blocker-{sede,till,member}.html
node tests/Browser/shoot-blocking-states.mjs                   # → storage/app/screenshots/175/
```

To regenerate the "before" side, check out the pre-175 dispensary blade, run the harness (its assertions will
fail — the artifacts are written first, deliberately, so this works), keep the file, and restore:

```bash
git show e8c68cd:resources/views/livewire/counter/dispensary-pos.blade.php \
  > resources/views/livewire/counter/dispensary-pos.blade.php
php artisan test tests/Browser/BlockingStatesHarnessTest.php   # fails; writes the artifacts anyway
cp storage/app/blocker-till.html storage/app/blocker-before-coldstart.html
git checkout -- resources/views/livewire/counter/dispensary-pos.blade.php
```

**Note on fidelity.** These are static captures with no Alpine running, so every `x-show` element would
render *visible* (the offline banner, the top bar's overflow menu, the 173 surface). The script hides them,
reproducing what an operator actually sees. The blocking states are plain server-rendered markup with no
`x-show`, so nothing being photographed is suppressed by that rule.

Last run (branch `feat/counter-blocking-states`): **ALL PASS** — 24 captures, one blocking state in every
state at both orientations and both themes, no destructive colour, `Ir a la caja` measured 116×44 in brand
blue (`rgb(37, 99, 235)`). Cold start: **3 statements before, 1 after**, in both themes.

**Rebuild before measuring.** The first run of this check reported `Ir a la caja` at 116×**20**, because the
local `public/build` predated prompt 173 and did not contain `min-h-[2.75rem]` at all. `public/build` is
gitignored and production builds on deploy, so nothing had shipped broken — but a browser check is only as
honest as the bundle it inlines. `npm run build` is a step in the sequence above for that reason.
