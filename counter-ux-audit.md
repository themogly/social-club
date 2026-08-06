# The counter, audited — what's wrong, and what it should be

A UX audit of the five counter screens, measured in a real browser at real iPad sizes, set against how
mature tablet POS products actually behave. Written against `main` at `d54e55b`.

Every number below is measured, not estimated. Screenshots and the raw measurement set are in the
scratchpad.

---

## Method

Five screens — Recepción, Socios, Dispensario, Barra, Caja — walked at **1180×820** (iPad landscape) and
**820×1180** (portrait), in four states: no sede chosen, no operator identified, PIN pad open, operator
identified. For each: page height against viewport height, position of every commit action as a percentage
of the fold, every interactive element under 44×44, and the information hierarchy by heading position.

Alongside that, a review of published behaviour in Square, Toast, Shopify POS, Lightspeed (X/K/S), SumUp,
Loyverse, Dynamics 365 Commerce, and the two cannabis-specific systems, Dutchie and Treez.

---

## The one structural finding

**The counter screens are a single vertical stack that gets longer. A POS is a two-pane layout with a cart
column that never moves.**

That difference produces most of what follows. In landscape the stack leaves the bottom half of the screen
empty; in portrait it runs 700px past the fold and takes the commit button with it.

| screen | viewport | page height | commit action | where it sits |
|---|---|---|---|---|
| Barra | 1180×820 | 951 | Cobrar | y=850 — **30px below the fold** |
| Barra | 820×1180 | 1877 | Cobrar | y=1776 — **596px below the fold** |
| Dispensario | 820×1180 | 1680 | Registrar aportación | y=1555 — **375px below the fold** |
| Dispensario | 1180×820 | 1190 | Registrar aportación | 73% — visible, but the product list is not |
| Caja | 1180×820 | 820→954 | Abrir caja | 50% → **107% once you identify yourself** |

On the dispensary in landscape the cart column is on the right and the commit button is in it — which is
the right shape — but the column is **not sticky**. Scroll down to reach the genetics list, which starts
below the fold, and the basket and its button scroll away with it.

---

## Findings, worst first

### 1. Identifying yourself pushes the thing you came to do off the screen

You were right that the PIN prompt should lock the page. It is worse than confusing — it is actively
destructive.

The operator strip is an inline block in normal flow. Closed it is **49px tall**. Open it becomes **521px —
36% of the viewport** — and everything below it moves down by that much.

On the till at iPad landscape:

```
no operator          Abrir caja at y=381   (50% down — comfortably in view)
PIN pad opened       Abrir caja at y=805   (102% — off the bottom)
operator identified  Abrir caja at y=853   (107% — still off the bottom)
```

So: you open the till screen, see the button you need, tap *Identificarse* to be allowed to press it — and
the button leaves the screen and does not come back. The operator now has to scroll to find the thing that
was in front of them ten seconds ago.

**The convention is unambiguously a full-screen surface.** Toast returns the device to a passcode screen;
Square presents a passcode screen; Shopify's staff PIN unlocks the app; Lightspeed K-Series goes furthest —
its Home screen *is* the lock screen, carrying clock-in, sign-in and QR scan. Nobody uses an expanding
inline panel.

One thing to keep, from the same research: **do not prompt per transaction.** Of the products reviewed only
Lightspeed S-Series offers that, as an option. Toast explicitly keeps staff signed in through a shift. The
current model — identify once, idle timeout, manual lock — is right; only its presentation is wrong.

### 2. Four blockers, four visual styles, no order

Open the dispensary with nothing set up and you are told four things at once, in four places, in four
different styles:

- *Sin operador identificado* — amber strip, top of page
- *No hay caja abierta* — red card, top of the right column, with a dark-red **Ir a la caja** button
- *Identifica a un socio* — grey empty state, centre of the left column
- *Identifica a un socio para poder registrar* — grey helper text, under the commit button

Nothing tells the operator which to fix first, and the four are not equal: without a sede nothing works,
without an operator nothing may be written, without a till nothing may be dispensed, without a member there
is nothing to dispense. **That is a strict sequence and the screen presents it as a pile.**

This is the standardisation you asked for, and it is the highest-value change in the audit: **one blocking
pattern, one at a time, in dependency order.** Full screen, one sentence saying what is missing, one button
that fixes it. When it's fixed, the next one appears — or the work does.

Also: **Ir a la caja is styled destructive.** It is navigation. Red means "this will destroy something"
everywhere else in the product.

### 3. On the product screen, the products are below the fold

Dispensario at 1180×820: the *Genéticas* heading sits at **y=565**, and beneath it three stacked rows of
filter pills — Categoría, Tipo, Variedad — before a single genetic appears. The list itself starts past
**y=1190** in an 820px viewport.

Above it, the left column spends its first 700px on a member search box, a second member search box, and a
large empty panel saying no member is identified.

The member does need to come first — both cannabis systems agree, and it is the right order for a CSC where
identity gates whether the aportación may happen at all. But "first" should mean *a step*, not *permanent
occupation of the top half of the screen*.

### 4. Touch targets, a short and specific list

The counter's 44px work mostly held. Four exceptions, and one is ironic:

| control | measured | where |
|---|---|---|
| `Identificarse` | **109×32** | the operator strip, on every screen |
| `Desbloquear` | **155×42** | the PIN pad's own confirm button |
| `Cancelar` | **157×42** | the PIN pad |
| `Todas` / `Bar` | **66×30 / 48×30** | bar POS category filters |

The PIN pad's digits are fine; its confirm button is not.

### 5. Landscape wastes half the screen, portrait overflows

Recepción, Socios and Caja all fit inside 820px with room left over — the content ends around y=400 and the
rest is white. The same layout in portrait needs 1180–1877px. One column that grows, rather than a layout
that reflows.

---

## Your six ideas, against the evidence

**"When a PIN is required the whole page should be locked."** Correct, confirmed by measurement (finding 1)
and by every product reviewed. Build it as one lock surface with two modes — *no operator yet* and *locked
after idle* — rather than a second thing next to the existing overlay.

**"When the till is closed, one big button saying open till for today, then enter the float."** Correct, and
it matches SumUp almost exactly: a locked till, one action, then the cash fund on a calculator pad, then
Validate. Two refinements from the research worth taking:

- **The float belongs on the same screen as the open action.** Every product does this; none uses a separate
  wizard step.
- **Make it one tap most days.** SumUp has a "Default cash fund" setting; Toast auto-fills from
  configuration; Shopify carries the previous session forward; Dutchie sets the next float at the *previous*
  close-out. Typing the same €200 every morning is the thing they all designed away.

Also worth stealing: Toast's three drawer states — *Active* (takes payments), *Open* (takes adjustments but
not payments), *Closed*. That middle state is how shift handover works without closing the day.

**"Designed to be used as an app on a tablet."** It currently isn't, and finding 1 and 3 are why. The
specific thing that makes a POS feel like an app rather than a web page is that **the cart column never
moves** — selection scrolls, the cart does not. Everything else follows from that.

One correction to a common instinct, because it will otherwise mislead the build: **do not put the primary
action in a bottom bar.** That is a phone convention and it does not transfer. 88% of tablet use is seated,
large tablets are rested on a surface in roughly two-thirds of sessions, and on a tablet the top and bottom
edges are the hostile zones — the bottom especially, because thumbs are never near it and a standing
operator's own wrist occludes it. What the mature products actually ship is identity and entitlement at the
**top of the cart column**, commit at the **bottom of that column** — not the bottom of the screen.

**"A home page with icons taking you to the POS, member registration."** Half right, and the research
sharpens it. Dynamics 365 states the rule most clearly: the hub carries operations that are *not* part of
the current transaction, the transaction screen carries selling, and **which one you land on after sign-in
is a setting**.

But the most transferable finding is from the two cannabis products: **neither lands on a hub or a product
grid. Both land on a queue of people.** Dutchie's Guest List is the main page of the register; Treez's main
POS screen is the customer queue. In a club the unit of work is a member, not a basket — which is an
argument that Recepción, showing who is in and who is waiting, is the right front door, with a hub for
everything that isn't a transaction.

**"A way to look up a member — see what they had before and when their membership expires, then take
payment."** This is Dutchie's guest profile (tabbed: personal, medical, history, notes) and Toast's previous
orders block (last order date, order count, average spend). Your Socios screen already half-exists for this
— today it finds a member and collects a fee and nothing else. Making it the member record is a small step
from where it is.

One design rule from the research that matters more than the screen itself: **the entitlement number belongs
on the cart, not on a profile screen.** Flowhub renders the remaining allowance persistently in the
upper-right of the cart; Treez puts a *Purchase Limits* button at the top of the cart. An operator should
never have to leave the sale to find out whether the member is allowed what they are asking for.

**"List views of products as well as icons."** Right, and the evidence points at something more specific
than a toggle. Loyverse is the only vendor publishing guidance: grid when you have images and want density,
list when **names are long or you need to see prices without an extra tap** — and its defaults are
device-dependent, grid on tablets, list on phones. Treez is search-first precisely because cannabis products
carry lab results, sizes and live stock that will not fit on a tile.

Your genetics carry THC, CBD, category and remaining stock. That is Treez's case, not Loyverse's. So:
**list as the default for genetics, grid for bar articles, and search reachable from both.** A toggle is
worth having, but the default matters more than the toggle.

---

## The standard to build to

Four rules, applied to all five screens. This is the "standardisation" half of the task and it is what makes
the screens feel like one product.

**One blocking pattern.** Full-screen, one heading, one sentence, one button. Used for every precondition —
no sede, no operator, no till, no member — and shown **one at a time, in dependency order**. Never a banner,
never two at once, never four in four styles.

**One cart column.** Right-hand side, fixed, never scrolls. Member identity and their remaining allowance at
the top; the basket in the middle; the commit button at the bottom **of the column**. The selection pane to
its left is the only thing that scrolls.

**One touch floor: 44×44**, no exceptions, including the PIN pad's own buttons and the filter pills.

**One colour meaning.** Red is destructive. A blocked state is amber. Navigation is neutral. *Ir a la caja*
is not a red button.

---

## What this breaks into

Four branches, and two of them are prompts you already have — this audit changes their content, not their
existence.

1. **The lock surface** — full-screen operator identification with its two modes, replacing the inline
   strip. Fixes finding 1. This is prompt **173** (handover mode) extended: same primitive, three modes
   rather than two, so it should be built once, not twice.
2. **Blocking states, standardised and sequenced** — one pattern, dependency order, applied across all five
   screens. Fixes finding 2. Includes the till-open screen and its one-tap float.
3. **The cart column and the selection pane** — sticky cart, scrolling selection, list/grid for products,
   entitlement on the cart. Fixes findings 3 and 5.
4. **The member record on the Socios tab** — lookup, history, expiry, then take payment. This is where your
   Alta wizard (**174**) also lands, so they belong adjacent.

Sequencing note: 2 and 3 are independent of each other and of the current queue. 1 should absorb 173 rather
than precede it. 4 should follow 174, or be built with it.
