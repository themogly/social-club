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
