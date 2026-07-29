# 12 — Bar / merch POS (separate catalogue, separate ledger)

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–11 merged.

`git checkout main && git pull` → `git checkout -b feat/bar-pos`.

> Deliberately separate from the dispensary. Bar and merch income is auxiliary association income;
> mixing it with cannabis contributions muddies the non-profit accounting and makes the reports
> unusable for the committee. Different catalogue, different ledger, different reports — one shared
> till session (prompt 10) so the drawer still reconciles as one drawer.

## Build

**Screen (Livewire, tablet-first, PIN-identified operator)**
- Article grid by category with images, quantity stepper, running basket, quick-cash buttons.
- **Member optional.** Attach a member to charge the wallet or apply tier pricing; or serve
  unattributed for cash with an optional free-text **reference** (useful during rollout and for
  guests where the club's rules permit).
- A **quick/miscellaneous amount** line for anything not in the catalogue, with a required reference.

**Payment**
- Cash into the open till session, or wallet if a member is attached. Mixed allowed. Change due
  shown clearly.

**Commit**
- One transaction writes the `Order` (frozen `items` snapshot with unit prices at time of sale), the
  unit `StockMovement`s via `RecordStockMovement`, the wallet transaction if applicable, and the
  cash line against the till session.

**Void & correct** — same discipline as the dispensary: a recorded, reasoned, permissioned reversal
that returns stock and reverses money. Never a silent edit.

**Receipt** — printable/emailable ticket, worded as a normal bar sale (this genuinely is a sale of
refreshments, not a cannabis contribution — keep the two vocabularies distinct).

## Rules

- Articles only. **A genetic can never appear on a bar order** — enforce at the model and test it.
- Bar revenue is tagged so every report can separate it from contributions and from fee income.
- Money in integer cents; snapshots freeze prices.
- Same till session as the dispensary, so `cierre de turno` covers the whole drawer.

## Tests (required)

- A basket of 3 articles totals correctly, depletes unit stock, and writes one order.
- A genetic cannot be added to a bar order (validation + policy).
- Member-less order succeeds with a reference; wallet payment requires a member.
- Void returns unit stock and reverses the money; totals reconcile.
- Bar takings appear under bar in the reports and never inside contribution totals.
- Double-submit produces exactly one order.

## Finish

`composer check` green. Screenshot at 1440 / 1280 / 1024 / 390 and a short laptop height, empty and
populated basket. Push the branch; **do not merge**.
