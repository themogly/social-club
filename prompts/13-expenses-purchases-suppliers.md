# 13 — Expenses, purchases & suppliers

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–10 merged.

`git checkout main && git pull` → `git checkout -b feat/expenses-purchases`.

> Two genuinely different flows, and conflating them is the classic mistake. **Till petty cash** is
> money out of the drawer during a shift — it *must* hit the cash reconciliation or the drawer looks
> short. **Overheads** are paid outside the till by the treasurer — rent, utilities, refurb — and
> must *not* touch the cash-up, but must appear in "where the money goes".

## Build

**Expense categories** (org-wide, owner-editable, seeded): Stock, Consumables, Staff payment,
Repairs & maintenance, Rent, Utilities, Other — each hinting TILL or OVERHEAD. Owner-only to manage.

**Till petty cash** (`expenses.record`, staff)
- Recorded against the **open till session**, amount, category, reason, optional receipt photo.
- Immediately reduces expected drawer cash (prompt 10). Attributed to the PIN-identified operator.
- Optional approval threshold: above a configurable amount, requires `expenses.approve`.

**Overheads** (`expenses.overheads`, owner/treasurer only)
- Amount, category, `paid_from` (BANK|CARD|OTHER), supplier, date incurred, receipt/invoice,
  optional **recurring schedule** (monthly rent, quarterly utilities) driven by the scheduler.
- **Never touches a till session or the cash-up.** Test this explicitly — it's the one that gets
  wired wrong.

**Suppliers & purchases**
- Supplier records: name, contact, notes, payment terms.
- Purchases: supplier, location, amount, items, invoice attachment, paid / owing, date. Where a
  purchase brings in cannabis, it links to the **batch intake** (prompt 07) so cost per gram flows
  through to the stock valuation and the purchase-vs-withdrawal reconciliation in prompt 14.
- Supplier balance owing is a reported figure.

**Recurring overheads**
- A scheduled job materialises due recurring expenses. **Idempotent** — a per-schedule-per-period
  marker, tested under retry, so a scheduler that fires twice doesn't double-charge the accounts.

**Where the money goes**
- A category breakdown across a period, split till vs overhead, with the non-profit **surplus**
  (contributions + bar + fees − outgoings) presented as cost recovery and reinvestment, never profit.
  Rendered in prompt 14.

## Rules

- Money in integer cents. Receipts on the private disk, not the public one.
- Approval is a recorded action with an approver and a timestamp, never a silent status flip.
- Staff-payment expenses get their own category and are always attributed and approved — the system
  *records* them clearly so the treasurer can handle the real-world PAYE/governance obligations.
  Recording is not discharging; say so in the UI help text.
- Read the approval threshold and recurrence settings through accessors with safe defaults.

## Tests (required)

- A till petty-cash expense reduces the session's expected cash by exactly its amount and appears in
  the Z-report.
- An overhead does **not** change any till session's expected cash, and still appears in the period's
  outgoings and the category breakdown.
- Above-threshold petty cash requires approval; below does not.
- The recurring job creates one expense per schedule per period across two runs (idempotency).
- A purchase linked to a batch intake carries cost per gram into stock valuation.
- `expenses.overheads` is refused to a manager and a staff user (403 at the policy).

## Finish

`composer check` green. Push the branch; **do not merge**.
