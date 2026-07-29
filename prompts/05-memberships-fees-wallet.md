# 05 — Memberships, tiers, fees, carencia & the member wallet

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–04 merged.

`git checkout main && git pull` → `git checkout -b feat/memberships-wallet`.

## Build

**Tiers (org-wide templates)** — name, default fee (cents), default period
(MONTHLY|YEARLY|LIFETIME|CUSTOM), benefits text, tier pricing hook (prompt 08), active.
A location may use any org tier; per-location fee overrides allowed.

**Memberships (per location)** — enrol a member at a location on a tier: start date, computed
expiry, fee (defaults from tier, **overridable** with `membership.fee.override`, recording who
overrode and why). A person may hold memberships at more than one location.

**Carencia (waiting period)** — on approval, `carencia_ends_at = joined_at + carencia_days`
(setting, default 15). Until it passes the member **may check in but may not be dispensed to**.
Surfaced clearly on the member record, at check-in and at the counter. Manager-waivable only if the
setting allows, and always logged.

**Renewals & expiry**
- Status derived: `active` / `expiring_soon` (configurable window) / `lapsed`.
- A renewal action (with the fee override) extends from the later of today and current expiry.
- A **scheduled expiry sweep** flips lapsed memberships nightly and queues optional reminder emails
  at N days before expiry. Idempotent under retry — a per-member/per-period sent marker, tested.
- A lapsed member is **blocked at the counter** and flagged at check-in.

**Transfer & enrol-existing**
- **Transfer** moves a membership to another location (permissioned, audited, both locations' reports
  reflect it).
- **Enrol-existing** adds a membership at a second location, sharing the person record and details.

**The wallet**
- Balance in **signed integer cents** per the checkpoint decision in prompt 01 (per-location or
  pooled). Positive = credit the member has paid in; negative = debt.
- Every movement writes a `WalletTransaction` with `balance_after_cents`, a type
  (TOPUP|CONTRIBUTION|FEE|REFUND|ADJUSTMENT|TRANSFER), the operator, and a polymorphic link to what
  caused it. **The wallet is an append-only ledger; the balance is derived and reconciled, never
  free-typed.** An adjustment is an explicit, permissioned, reasoned entry — never a silent edit.
- **Top-up** (cash at the counter, into the open till session) and **refund** (out of the till) are
  distinct types and both hit the cash reconciliation in prompt 10.
- **Debt**: allowed only if the setting permits, capped by an owner-set limit (org-wide if the
  pooled model was chosen), enforced at the counter, and reported. If v1's **ring-fencing** model was
  confirmed at the checkpoint, carry it forward: credit auto-pays debt across unfenced locations via
  a recorded `CreditTransfer`; a ring-fenced location is excluded from auto-settlement and moves
  money only by explicit, permissioned, reported manual transfer.
- **Wallet float is a liability.** Total credit held across members is a reported figure (prompt 14),
  not just a member-level number.

**Fees**
- A fee may be taken as cash at the counter or charged to the wallet (setting; confirm at checkpoint).
- Fee income is tracked **separately from consumption contributions** in every report — they are
  different things for a non-profit.
- Optional: split a fee into instalments with a due schedule and an unpaid-fee flag at the door.

## Rules

- Money is integer cents; the ledger is the truth and the balance column is derived from it.
- Every wallet movement is attributed (who, when, where, which till session).
- Never `migrate:fresh` against data that matters; migrations touching balances are tested against a
  seeded copy, not just a fresh DB.
- Read settings (carencia days, expiry window, debt limit) through **accessor methods with safe
  defaults** — a stale cache must not throw inside a queued expiry job.

## Tests (required)

- Enrol → carencia set correctly; dispensation blocked before it ends, allowed the day after; the
  manager waiver is permissioned and logged.
- Expiry sweep flips only genuinely lapsed memberships and is idempotent across two runs; reminder
  emails send once per member per period under retry.
- Wallet: top-up +€50 → balance and a TOPUP row with the right `balance_after`; a contribution
  reduces it; a refund reverses it; the ledger sum always equals the balance.
- Debt cannot exceed the configured limit; the denial is a 403/validation, not a hidden button.
- Fee override requires the permission and records who and why.
- Transfer moves the membership and leaves both locations' reports correct.
- (If ring-fencing is in scope) credit at an unfenced location auto-clears debt at another and is
  recorded in both; a ring-fenced location does not auto-settle.

## Finish

`composer check` green. Record the wallet model, debt policy and fee-payment rule in DECISIONS.md.
Push the branch; **do not merge**.
