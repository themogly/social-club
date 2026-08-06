# Browser checks

Pixel-level checks that a headless PHPUnit render cannot make. Playwright is **not** a CI dependency (it needs a
~100 MB browser), so the `.mjs` scripts are run by hand and their result recorded in `DECISIONS.md`.

**The PHP half of every harness runs in `composer check`** and guards the STRUCTURE; the `.mjs` scripts
measure the pixels. That was previously only *claimed*: `tests/Browser` was not in either phpunit config's
`<testsuites>`, so none of the harnesses' structural assertions had ever run in CI — they passed only when
someone pointed PHPUnit at the directory by hand. Fixed in prompt 178; both `phpunit.xml` and
`phpunit.mysql.xml` now collect it. Each harness guards its own artifact-writing against a missing asset
build, so a run without `npm run build` degrades rather than failing.

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

## The cart column (prompt 176)

Measures the two SELLING screens in their resolved states — a socio identified, a basket empty and full —
at both tablet orientations, and screenshots them in both themes. The measurement that justifies the
branch is **where the commit button is**: on `main` it was below the fold on both screens.

```bash
npm install --no-save playwright
node_modules/.bin/playwright install chromium-headless-shell
npm run build                                              # the harness inlines the BUILT css — rebuild it
php artisan test tests/Browser/CartColumnHarnessTest.php   # writes storage/app/cart-*.html
node tests/Browser/measure-cart-column.mjs                 # → storage/app/screenshots/176/
```

It exits non-zero if a commit action is outside the viewport, if no product is visible without scrolling,
if the cart column moves when the selection pane scrolls, if the page scrolls sideways at 820/1024/1180/
1440, or if any control on a selling screen is under 44×44.

**Before** (`main` at `592c93c`, after a rebuild) and **after**, commit action y-position:

| screen | viewport | before | after |
|---|---|---|---|
| Dispensario, basket full | 1180×820 | 942–1006 — **186px below** | 736–800 — inside |
| Dispensario, basket full | 820×1180 | 2055–2119 — **939px below** | 1096–1160 — inside |
| Barra, basket full | 1180×820 | 905–969 — **149px below** | 736–800 — inside |
| Barra, basket full | 820×1180 | 1809–1873 — **693px below** | 1096–1160 — inside |

After the change the page height is exactly the viewport (820 / 1180) on every screen, the commit sits at
the same y whether the basket is empty or full, and the cart does not move when the pane is scrolled to
its end.

### Two fidelity rules these harnesses depend on

**Inline `app-*.css` only, never `*.css`.** The counter layout loads `resources/css/app.css` and nothing
else. `theme-*.css` is the Filament PANEL theme and is never on a counter page; globbing `*.css`
concatenates it *after* app.css and corrupts the cascade. This is not hypothetical — it silently defeated
`md:flex-row`, so a correctly-built two-pane layout measured as a stacked one and the numbers said the
change had made things worse. `BlockingStatesHarnessTest` had the same glob and was corrected with it
(re-run after the fix: still ALL PASS, so prompt 175's recorded figures stand).

**Pass the layout params the real page passes.** The selling screens declare
`#[Layout('components.layouts.counter', ['fullHeight' => true])]`. A harness that renders the layout
without them photographs a shell the operator never sees.

Both rules have the same shape as the rebuild rule above: **a browser check is only as honest as the page
it assembles.**

## The application form's ID upload (prompt 178)

Photographs the public application form at **phone width** — where an applicant actually opens an emailed
invite link — in both locales and both themes, and asserts the upload is present and NOT required.

```bash
npm run build
php artisan test tests/Browser/ApplicationFormHarnessTest.php   # writes storage/app/application-form-*.html
node tests/Browser/shoot-application-form.mjs                   # → storage/app/screenshots/178/
```

**Rendering this route in a chosen locale needs the applicant's own switcher.** `app()->setLocale()` does
nothing: `SetLocale` resolves the locale again on the way in, and for an unauthenticated prospect that means
the club default (prompt 96 — a prospect cannot have a preference, so the only lever is the club default).
Prompt 167's switcher drops an in-session override, so the harness uses `withSession(['locale' => …])`.

This mattered: the first version of the feature test set the app locale and then asserted with a bare
`__()`, which compares the response's locale against itself and passes without testing anything. It now
asserts `trans($key, [], $locale)` per locale, plus that the two locales differ — so neither assertion can
pass vacuously.

Last run: **ALL PASS** — 4 captures, `#document_scan` 316×36 at 390px, `required=false` in both locales, no
horizontal scroll.
