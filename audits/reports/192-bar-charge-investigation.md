# Prompt 192 — investigation: "Charge does nothing on the bar"

**Investigated at:** `542d79e` (main). **No production code changed by this branch** — the prompt asks for a
finding first, and the layout fix belongs in 193.

---

## The finding, in one line

**The server-side charge path works for the reported basket, exactly as reported.** The failure is
client-side, and the reason the operator saw nothing is a separate, real defect: **the outcome of pressing
Charge is rendered ~650px away from the button that was pressed.**

---

## 1. The suspected cause — the clipped payment section — is NOT the cause

The prompt's first suspicion was that a required payment control sits inside an unreachable overflow
container, so Charge validates against something the operator cannot satisfy. **The clipping is real. It is
not what stops the charge.**

Reproduced as a test, at the reported values — one article at €0.90, **both tender fields left empty**,
till open, operator PIN-identified:

| | |
|---|---|
| `flashType` | `success` |
| `flashMessage` | *Pedido registrado.* |
| Orders created | **1** |

Empty tender fields are the *exact* case (`HandlesTender`): `requestedWalletCents()` returns 0, so
`tenderSplit(90)` is `[90, 0]`; the wallet-needs-a-socio check is skipped because wallet is 0; the sum check
balances; and `isUnderTendered()` returns **false** early precisely because a blank cash field means
"exact". So nothing in the payment section is required to complete a cash sale, and being unable to reach it
cannot refuse one. **Do not "fix" the charge by changing validation** — there is nothing wrong with it.

## 2. The till is not the cause either

The prompt asks whether the bar silently requires an open caja. It does require one — and it does say so.
`bar-pos.blade.php` puts `CounterBlocker::TILL => $openTill !== null` in its chain, so with no open till the
screen renders the till blocking state **instead of** the article grid and cart. The operator could not have
added an article to a basket in that state, which means a session *was* open in the reproduction. The view
and `commit()` resolve the till through the same `openTillSession()`, so they cannot disagree.

(The prompt's observation that "the bar screen shows no till warning while Dispensario does" is consistent
with a till being open at the time — the bar's warning exists, it just had no reason to render.)

## 3. Where it actually fails: the click never reached the server

The operator reported the basket still showing the line after pressing Charge. On success `commit()` calls
`resetBasketState()`, so a basket that survives is proof the method never ran. Combined with §1 — the same
call succeeding in a test — the click did not reach Livewire.

**What I could prove from here ended there, and I did not guess past it. Good, because my ranking of the
candidates was wrong.**

> ### CORRECTED — the cause is now known, and it was not the one I thought most likely
>
> Prompt **195** found it: Livewire v4's `$wire` proxy resolves an ALIAS TABLE before it looks for a
> component method, and that table maps `commit` → `$commit`. So `wire:click="commit"` called Livewire's
> built-in no-op and `BarPos::commit()` was never invoked from a browser. Measured on the same build, same
> click: `["$commit"]` and no row; after renaming to `commitOrder`, `["commitOrder"]` and an order.
>
> **I named prompt 188's stale surface as "the most likely cause". That was wrong**, and it is worth saying
> why rather than quietly editing it out. The two are distinguishable by one measurement I did not take: an
> overlay swallowing a tap produces **no Livewire request at all**, whereas this produced a request that
> returned 200. I had the right instinct — that the click never reached the server — and reached for the
> nearest recently-found bug instead of measuring which of the two shapes it was. A stale `fixed inset-0
> z-50` surface is a *plausible-looking* cause of a dead button, which is exactly why it needs ruling out by
> evidence rather than by plausibility.
>
> Both bugs were real; only one was this one. 188 is a separate defect in a separate file and its fix is not
> credited to this, nor this to it.

The candidates I ranked at the time, both since ruled out:

- ~~**Prompt 188's stale surface.**~~ **RULED OUT.** A tap swallowed by an overlay sends no request at all;
  this one sent a request that returned 200. A real defect, fixed separately, but not this one.
- ~~**`x-bind:disabled="! online"`.**~~ **RULED OUT.** A disabled button sends no request either.

**Neither survived the one measurement that mattered.** The lesson for the next investigation: when the
question is "did the click reach the server", *read the wire* before ranking suspects — the request body was
sitting there the whole time saying `$commit`.

## 4. The second bug — and it is the one worth the branch

> **Would the operator have been able to tell? No.**

Measured on the real authed screen with the built CSS, at the reported viewport:

| | 1180×820 | 820×1180 |
|---|---|---|
| Charge button | y **736 → 800** (viewport 820) | y 1096 → 1160 (viewport 1180) |
| Cart column | y 89 → 800 | y 89 → 1160 |
| **Cart scroller hides below its own fold** | **212px** | 0px |

Two things follow.

- **The payment section is 212px below a fold with no affordance.** It scrolls, so it is reachable — but
  nothing on screen says there is more, and the only hint is the half-cut *"Wallet (€)"* heading the reporter
  noticed. That is the observed clipping, and it is a genuine defect even though it did not cause this one.
- **Every reason Charge can refuse for is flashed ~650px from the button.** The flash block is a sibling
  rendered *above* the two-pane layout; the panes start at y=89 and the button sits at y=736. In an 820px
  viewport the operator presses a control at the bottom of the screen and the answer appears at the top.

**Prompt 60 built a guard for exactly this class** — *"clicking Charge must ALWAYS produce an observable
outcome … never a silent dead control"* — and `ChargeAlwaysObservableTest` has one test per blocked state.
Every one asserts `assertSee(...)`, which is true of the rendered component **regardless of where on the page
it renders**. The guarantee was proven to a parser, not to a person. That is the same lesson as
`test_every_counter_route_refuses_to_show_its_screen_during_handover` in the security audit: a test that
passes while the thing it protects is wide open.

---

## Recommendations (for 193, not for here)

1. **Colocate the outcome with the control.** The flash for a commit belongs at the foot of the cart column,
   beside the Charge button — not at the top of the page. Keep the top-of-page banner for screen-wide state
   (offline) if useful, but the answer to "I pressed Charge" must be where Charge is.
2. **Make the cart scroller's overflow visible**, or restructure so the payment section is not below a
   silent fold. 212px of hidden content on a column whose whole job is "commit this" is the reported symptom.
3. **Add a measurement, not just an assertion, to the prompt-60 guard** — the flash element must be within
   the viewport and within a stated distance of the button. `assertSee` cannot express that.
4. **Re-test the original reproduction on current main first** (see §3).

## What I could not check

The browser console, the network panel, and `storage/logs/laravel.log` for the reporter's session — I do not
have that machine. No exception is reachable on this path in any case: every throw inside `commit()` is
caught and flashed, and the success path was exercised. If the reproduction survives on current main, the
console is the next place to look and the question is narrow: **is the Charge button disabled, and is
`navigator.onLine` false?**
