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

## The health page's backup section (prompt 180)

```bash
npm run build
php artisan test tests/Browser/BackupSlotHarnessTest.php   # writes storage/app/system-health.html
node tests/Browser/shoot-backup-slot.mjs                   # → storage/app/screenshots/180/
```

Fails if the page renders any of the retired claims (`Sin configurar`, `Pendiente de conectar`, `Última
copia`, `Última restauración`) or loses the section heading.

**Two rules here are the INVERSE of the counter harnesses, and both follow from the same principle —
reproduce what the user actually sees:**

- **Inline `theme-*.css`, not `app-*.css`.** This is a Filament PANEL page, so the panel theme is exactly
  what it loads. Prompt 176's lesson is not "always app.css"; it is *inline what the page itself loads, and
  nothing else*.
- **Un-cloak instead of hiding.** The counter captures hide `[x-show]` elements because Alpine would hide
  them on boot. A Filament page is the other way round: its shell stays cloaked until Alpine runs, so a raw
  capture photographs an empty topbar — which looks like a broken page rather than a missing script. The
  script reveals the main region and suppresses the dropdown panels Alpine would have kept closed.

## The counter's alta wizard (prompt 174)

```bash
npm run build
php artisan test tests/Browser/AltaWizardHarnessTest.php   # writes storage/app/alta-{entry,review,duplicate}.html
node tests/Browser/shoot-alta-wizard.mjs                   # → storage/app/screenshots/174/
```

Three counter-side states at both orientations and both themes, portrait first — that is how a tablet gets
handed to somebody. Fails on a missing alta panel, a control under 44×44, or horizontal page scroll.

The applicant's own half is not captured here: it is the ordinary public application form, already
photographed by prompt 178's harness. That is the point of the design rather than a gap in the coverage.

## The in-browser MRZ prefill (prompt 179)

```bash
npm run build                                           # also vendors public/ocr/ — see scripts/vendor-ocr.mjs
php artisan test tests/Browser/MrzPrefillHarnessTest.php
node tests/Browser/shoot-mrz-prefill.mjs                # → storage/app/screenshots/179/
```

Three states × two locales × phone and tablet. The **`plain`** state is deliberately captured with the scan
trigger still `hidden`, because that is exactly what a browser which cannot run the reader shows — an
ordinary form. Fails on horizontal scroll, on fields marked with nothing read, on nothing marked after a
read, or on a confirmation target under 44px.

**Playwright is `--no-save`, so any `npm install` prunes it.** That happened during 179 (installing
tesseract.js removed it) and every `.mjs` here died with `ERR_MODULE_NOT_FOUND`. Reinstall with
`npm install --no-save playwright` — it is deliberately not a project dependency.

## Counter surface vs the blocker chain (prompt 187)

Screenshots the counter terminal **either side of the sede step** at 1180×820 and 820×1180, light and dark,
motion reduced and allowed (16 captures), and asserts what a picture cannot: the operator surface is **down**
while the chain is on the sede step — with the top bar, and therefore the sede switcher, reachable — and
**up**, with its PIN pad, once a sede is chosen.

Unlike the prompt-175 script this one runs **real Alpine**: the surface's content lives in `<template x-if>`,
which no CSS can materialise, and approximating the decision under test would only photograph our own
assumption. Livewire's bundle carries Alpine but will not boot without a Livewire endpoint, so the
standalone build is injected.

```bash
npm install --no-save playwright alpinejs
node_modules/.bin/playwright install chromium-headless-shell
npm run build                                              # compile the CSS the harness inlines
php artisan test tests/Browser/SurfaceChainHarnessTest.php # writes storage/app/surface-chain-*.html
node tests/Browser/shoot-surface-chain.mjs                 # non-zero if the surface is up on the sede step
```

Last run (branch `fix/surface-respects-blocker-chain`): **ALL PASS** — 16 captures, surface down with the
top bar reachable on the sede step, surface up with its PIN pad once the sede is chosen.

## Counter home + top-bar density (prompt 189)

Screenshots the counter home at 1180×820 and 820×1180, light and dark, and asserts what a picture cannot:
every tile is a real finger target (≥96px tall — far above the 44px floor, which is the whole reason a hub
beats a menu bar on a tablet), nothing is under 44×44, and the page never scrolls sideways.

`measure-topbar-density.mjs` is the other half. `measure-topbar.mjs` (prompt 132) asks whether anything
OVERLAPS; it passed before this branch and after, which is why it could not see what the owner reported.
Overlap is the failure state, cramped is the state just before it — so this measures the split between the
row's fixed furniture and the space left for the destinations. Run it, stash the top-bar change, run it
again: the comparison is the argument.

```bash
npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
npm run build                                              # the arbitrary grid class must be compiled
php artisan test tests/Browser/CounterHomeHarnessTest.php  # writes storage/app/counter-home.html
node tests/Browser/shoot-counter-home.mjs
php artisan test tests/Browser/TopbarHarnessTest.php       # writes storage/app/topbar-harness.html
node tests/Browser/measure-topbar-density.mjs
```

Last run (branch `feat/counter-home`): **ALL PASS** — 4 captures, 5 tiles at 128px tall in both
orientations. Density: furniture 371px → **331px**; portrait strip headroom 181px → **221px (+22%)**.

## Bar screen: list rows + cart column (prompt 193)

Counts article rows per viewport, measures the first row, checks no name wraps, and measures how much of the
cart column sits below its own fold — at 1180×820, 820×1180, 1440×900 and a short 1280×720 laptop, because
two-width checking is how the old row layout survived. Screenshots land in `storage/app/screenshots/193/`.

Takes the layout as an argument; `articleLayout` is `#[Session]`-backed so the harness writes both.

```bash
npm install --no-save playwright && node_modules/.bin/playwright install chromium-headless-shell
npm run build
php artisan test tests/Browser/BarScreenHarnessTest.php   # writes storage/app/bar-screen-{list,grid}.html
node tests/Browser/measure-bar-screen.mjs list
node tests/Browser/measure-bar-screen.mjs grid
```

Last run (branch `feat/bar-rows-and-cart`), list layout: rows on screen **6 → 9** at 1180×820 and **6 → 12**
in portrait; first row **714×106 → 714×68** (portrait 166 → 68); names wrapped **0**; cart hidden below its
fold **212px → 0px** at every viewport.

## The commit buttons actually reach their actions (prompt 195)

The only script here that needs a **running server**, because it is the one thing a PHP test cannot see: a
Livewire action named after one of `$wire`'s aliases is unreachable from a browser, and
`Livewire::test(...)->call('commit')` invokes the PHP method directly so it never meets the alias table.
Forty-two green tests exercised a path no operator could reach.

```bash
npm run build
php artisan serve --port=8123
node tests/Browser/prove-commit-click.mjs          # MEMBER_QUERY=… to override the member lookup
```

Logs in as the dev owner, chooses a sede, identifies with a PIN, adds a line, presses the real button, and
asserts the request names the action (not `$commit`) and an order is recorded.

Last run (branch `fix/commit-action-name-collision`): bar `["commitOrder"]` + *Order recorded.*, orders
56 → 57; dispensary `["commitDispensation"]`. Against the old names on the same build: `["$commit"]`, flash
`null`, orders 57 → 57.

## Top-bar geometry after the Alpine scope (prompt 196)

196 added one attribute (`x-data="{}"`) to the counter shell. 132's overflow layout and 130's scrollable
strip both depend on that flex row, so the geometry is **proved** unchanged rather than assumed — re-run both
top-bar scripts after any change to the shell:

```bash
npm run build
php artisan test tests/Browser/TopbarHarnessTest.php
node tests/Browser/measure-topbar.mjs           # overlap / 44px / page scroll
node tests/Browser/measure-topbar-density.mjs   # the furniture-vs-strip split
```

Last run (branch `fix/topbar-alpine-scope`): **ALL PASS** at 768/800/1024/1280, 7 controls, no overlap, no
horizontal scroll; density byte-identical to 189 (furniture 331px, portrait strip headroom 221px).


## The confirmation carries the outcome and holds still (prompt 202)

The **second** script needing a running server, for two claims a PHP test cannot make: that the Charge button
does not MOVE when the confirmation renders above it, and that the change due is still on screen after
Livewire has re-rendered the column that produced it.

```bash
npm run build
php artisan serve --port=8123
node tests/Browser/prove-confirmation-holds-still.mjs   # → storage/app/screenshots/202/
```

Logs in, reaches a working bar counter, and charges three times in a row — tendering €50,00 each time so there
is always change to state. Exits non-zero if the Charge button moves more than 1px, if more than one outcome
block or live region is on screen, if no change is stated, or if the outcome survives the next basket action.

Last run (branch `feat/confirmation-carries-the-outcome`) at 1180×820: Charge y **736 · 736 · 736**, spread
**0.0px**; 1 outcome block and 1 live region per round; change **€48.80** each time with the tender field
already empty; 0 outcome blocks after the next article tap. **PASS**.

## The member lookup is live, and is a real combobox (prompt 204)

The **third** script needing a running server. Two of its four claims cannot be made anywhere else: that a
wedge scanner's 48 characters survive `wire:model.live.debounce` (a truncated `lookup` on the submit request
would turn every card scan into a name search, invisibly), and that the placeholder fits the narrowest field
it appears in.

```bash
npm run build
php artisan serve --port=8123
node tests/Browser/prove-live-lookup.mjs        # MEMBER_QUERY=… to match your demo data
```

The bar's lookup sits behind a per-sede flag, so enable it first or the bar measurement is skipped (the script
says so rather than passing silently):

```bash
php artisan tinker --execute="\$l = App\Models\Location::query()->withoutGlobalScopes()->first();
app(App\Support\ActiveScope::class)->setOrganisation(\$l->organisation_id);
App\Support\Settings::set('bar_attach_socio_enabled', true, App\Enums\SettingType::BOOL, \$l->id);"
```

Last run (branch `feat/live-member-lookup`) at 1180×820: typing `ell` → **5 rows**, `aria-expanded="true"`,
no Enter; ArrowDown → `member-lookup-option-0` with exactly **1** `aria-selected`, then `option-1`; Escape
closes; Enter on the active option → `selectMember` **without** `submitLookup`; the wedge scan's submit
request **carried all 48 characters**; placeholder needs **178px of 268px** in the bar socio column and 178
of 656 on Recepción. **PASS**.

## The membership dead end, before and after (prompt 203)

```bash
npm run build
php artisan test tests/Browser/MembershipDeadEndHarnessTest.php   # → storage/app/deadend-{lapsed,none,elsewhere}.html
node tests/Browser/shoot-membership-deadend.mjs                   # → storage/app/screenshots/203/
```

Regenerate the **before** (the owner's screenshot: an ACTIVE member with nothing to press) from `main`'s
blade, using the same recipe as the 175 shooter — the artifacts are written before the assertions run, so a
failing harness still produces them:

```bash
git show main:resources/views/livewire/counter/membership-counter.blade.php \
  > resources/views/livewire/counter/membership-counter.blade.php
php artisan test tests/Browser/MembershipDeadEndHarnessTest.php   # fails; writes the artifacts anyway
cp storage/app/deadend-lapsed.html storage/app/deadend-before.html
git checkout -- resources/views/livewire/counter/membership-counter.blade.php
```

The script asserts what a picture cannot: **before has 0 controls** and every after has at least one, none
under 44×44, no horizontal page scroll.

Last run (branch `feat/membership-at-the-counter`): before **0** controls at both orientations; lapsed **1**;
none **2**; elsewhere **2**; no horizontal scroll anywhere. **PASS**.

## The counter hub and the terminal strip (prompt 205)

```bash
npm run build
php artisan test tests/Browser/CounterHomeHarnessTest.php tests/Browser/TopbarHarnessTest.php
node tests/Browser/shoot-counter-hub.mjs      # → storage/app/screenshots/205/  (DEBUG_CONTRAST=1 for per-sample ratios)
node tests/Browser/measure-topbar.mjs         # the row's geometry, on its new contents
```

`shoot-counter-hub.mjs` asserts what a picture cannot: the **tap count** for Recepción → Dispensario, **AA
contrast** on the hero tile and every rail figure, the 44×44 floor on tiles and bar controls, and no horizontal
page scroll — at both orientations, both themes, motion reduced and allowed.

**Its contrast check resolves colours in the browser, on a canvas.** Parsing `getComputedStyle().color` with an
`rgb()` regex is wrong on a Tailwind v4 page: colours come back as `oklch(...)` and `oklab(... / 0.8)`, and the
first draft read a lightness of 0.968 as a red channel — reporting 1.10:1 for near-white on near-black and
3.89:1 for 80% white on brand blue by coincidence. Copy this pattern rather than the regex.

`measure-topbar.mjs` was **updated, not deleted**: the five-destination row it was written for is gone, and left
unchanged its selector list matched one element and reported ALL PASS. It now carries a `MIN_CONTROLS` floor so
an empty measurement fails loudly.

Last run (branch `feat/counter-home`): topbar **7 controls, zero overlaps, none under 44px** at 768/800/1024/1280;
hub **5 tiles, 7 bar controls, worst contrast 5.17:1**, no horizontal scroll at 1180×820 and 820×1180, light and
dark; **Recepción → Dispensario = 2 taps**. **PASS**.
