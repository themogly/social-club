# 10 — Till sessions, cash movements, arqueo & cierre de turno

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–05 merged.

`git checkout main && git pull` → `git checkout -b feat/till-sessions`.

> Cash + volunteers + no card trail is the highest governance risk a club carries, and it is where
> v1 was weakest. Till sessions protect the staff as much as the club: if the drawer is short, the
> system should say by how much, on whose shift, and against which movements — not leave it to memory.

## Build

**Open a till**
- `till.open`: choose the workstation/terminal, enter the **float** (fondo) in euros, confirm.
  One open session per terminal per location. The POS screens refuse to commit without one and
  offer to open it.

**During the shift**
- Every dispensation, bar order, wallet top-up and refund attaches to the open session with its
  payment method, so the expected cash is derived, never typed.
- **Cash movements**: paid in, paid out, **banked** (removed to the safe/bank), and **petty cash**
  (a till expense from prompt 13) — each with a reason, an amount and the operator.
- A live session summary: opening float, contributions (cash / wallet split), bar takings, top-ups,
  refunds, petty cash out, banked, and **expected cash in drawer**.

**Close the till — blind arqueo**
- `till.close` (manager+). The count is **blind**: the operator enters the counted cash (optionally
  by denomination) **before** the expected figure is revealed. Showing the expected total first
  defeats the entire purpose of a count.
- On submit, reveal expected vs counted and the **variance (descuadre)**, require a note when the
  variance exceeds a configurable tolerance, and close the session. A closed session is immutable —
  corrections are a new, linked adjustment, never an edit.

**Z-report / session report**
- Per session: opening float, gross contributions by payment method, bar takings, fee income,
  top-ups and refunds, petty cash out, banked, expected, counted, variance, operator(s), duration,
  transaction count, voids. Printable and exportable. Feeds the dashboard and the financial reports
  (prompt 14).

**Oversight**
- A list of sessions with variances, filterable by location, operator and period.
- Alert on the dashboard for **unreconciled / still-open sessions** past closing time.
- Variance-by-operator over time is a report, not an accusation — it exists so patterns are visible.

## Rules

- Expected cash is **always computed from the ledger**, never stored as a typed figure, never cached.
- Every movement is attributed to the PIN-identified operator, not the device's login.
- A session cannot be reopened. A second session cannot open on the same terminal while one is open.
- Wallet payments and bar sales are in the session summary but only **cash** counts toward expected
  drawer cash — this distinction is where naive implementations go wrong.
- Never hard-delete a session or a movement.

## Tests (required)

- Open with a €200 float, take €150 cash contributions, €40 wallet contributions, €30 bar cash, pay
  €25 petty cash, bank €100 → expected drawer cash is exactly €255 and **excludes** the €40 wallet.
- Blind count: the expected figure is not present in the response or the DOM until the count is
  submitted (test the payload, not just the UI).
- A variance beyond tolerance requires a note; within tolerance does not.
- A closed session rejects new transactions; a POS commit with no open session is refused cleanly.
- Two sessions cannot be open on one terminal.
- A void during the shift adjusts the expected figure correctly.
- The Z-report totals equal the sum of the underlying rows for the session.

## Finish

`composer check` green. Screenshot the open/close screens and the Z-report across the standard size
range. Record the tolerance default in DECISIONS.md. Push the branch; **do not merge**.
