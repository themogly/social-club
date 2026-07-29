# 11 — Dispensary POS (weight-based, member-first)

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–10 merged.

`git checkout main && git pull` → `git checkout -b feat/dispensary-pos`.

> This is not a retail till with a cannabis SKU. The flow is **member first, always**: identify,
> verify, check limits, choose genetic, weigh, contribute, sign. There is no anonymous cannabis
> transaction — ever. (The bar, prompt 12, is the opposite and is deliberately separate.)

## Build

**Layout (Livewire, tablet-first, PIN-identified operator)**
- Left: the member panel — **photo**, name, member number, tier, membership status, carencia,
  **consumption gauge** (today and MTD, from `ResolveMemberLimits`), wallet balance, sanctions.
  Empty until a member is identified; the screen cannot proceed without one.
- Centre: genetics grid for the active location — name, THC/CBD %, cultivation type, price per gram,
  remaining stock in grams, batch indicator. Search and category filter.
- Right: the basket — lines of genetic × grams × price/g = line total, running total, and the
  post-transaction wallet balance.

**The flow**
1. **Identify** — QR scan (handheld or camera) primary; name/number search fallback. Optionally
   restrict to members currently **checked in** (setting) so the door and the counter agree.
2. **Verify** — the operator sees the photo. Eligibility comes from the **same
   `ResolveMemberEligibility` Action the door uses** (prompt 09), evaluated for the **counter**
   surface — so "warn at the door, block at the counter" is expressible, configured once in prompt
   03, and cannot drift between the two screens.
3. **Choose genetic** → **enter weight in grams to 2 dp** on a numeric pad (not a quantity stepper).
   Converted to centigrams by the Action. Price comes from **`ResolvePrice`** (prompt 08) — tier
   price, then discounts — never from a raw column read. Live line total.
   Optional **calculator mode**: enter euros, get grams. **Grams are authoritative**: the entered
   euro figure is a convenience that resolves to a gram amount (rounded down to 0.01 g), and the
   line total is then recomputed from those grams. Never store the typed euros as the total.
4. **Batch** — auto-selected FEFO, overridable by the operator; expired batches refused.
5. **Limits** — checked live as the basket changes, and again atomically at commit (prompt 06).
   Breach blocks; override is permissioned, reasoned and logged.
6. **Contribute** — cash into the open till session, or charge to the **wallet**. Mixed is allowed.
   Debt only within the configured limit.
7. **Sign** — optional per setting: capture an on-screen signature stored against the dispensation
   (`signature_path`, private disk). This is the *acta*-grade evidence some clubs want per withdrawal.
8. **Commit** — one transaction writes: the `Dispensation` rows, the `StockMovement` (DISPENSE) via
   `RecordStockMovement`, the `WalletTransaction` if wallet-paid, the cash line against the till
   session, and the audit entry. All or nothing.
9. **Receipt** — printable/emailable ticket worded as a **contribution**, not a sale.

**Void & correct**
- A completed dispensation may be **voided** (`dispensation.void`, manager+) with a reason: stock is
  returned to the originating batch, grams are released from the member's limit totals, the wallet or
  cash is reversed, and the original row is marked VOIDED with the reversal linked. **Never a silent
  edit or delete.** A correction is a void plus a new dispensation, linked.

**Robustness at the counter**
- Optimistic UI must not outrun the transaction — the basket cannot commit twice (idempotency key on
  submit, tested under double-tap and retry).
- If no till session is open, the screen says so and offers to open one (prompt 10) rather than
  failing obscurely.
- Fast operator switching by PIN mid-shift; every line records the operator who was identified.
- **Connectivity loss is an explicit decision, not an omission.** Limits, stock and balances are
  live-query by mandate, so an offline POS cannot safely commit a cannabis dispensation — it would
  have to guess at the two figures the club's legal defence rests on. **Default: fail closed.** On
  connection loss the screen shows an unmistakable offline state, preserves the in-progress basket
  locally, blocks commit, and retries; the basket commits intact when the connection returns.
  Record this in DECISIONS.md, and if the club insists on offline dispensing, that is a decision for
  them to take knowingly — not a default.

## Rules

- **Weight in centigrams, money in cents, one rounding helper.** Line total =
  `round_half_up(price_per_gram_cents * grams_cg / 100)`.
- Order snapshots freeze the genetic name, batch number and price per gram at the moment of
  dispensing — later price or name changes must not rewrite history.
- Every dispensation is attributed: member, operator, location, till session, batch, timestamp.
- Wording throughout: *aportación / contribución*, *socio*, *dispensación*. Never *venta* or *cliente*.
- No cannabis line may exist without a member. Test it.

## Tests (required)

- 3.50 g of a genetic at €10,00/g → line total €35,00, stock −350 cg, one DISPENSE movement, one
  dispensation row, limits updated.
- Rounding: 1.33 g at €7,49/g → the exact pinned cent value.
- A member-less cannabis basket is impossible (validation + policy, both tested).
- Lapsed / suspended / in-carencia / over-debt members are each blocked with the right reason.
- Limit breach blocks; override requires the permission and is logged.
- Void: stock returns to the same batch, grams are released, wallet/cash reversed, both rows linked,
  totals reconcile.
- Double-submit produces exactly one dispensation.
- Committing with no open till session is refused cleanly.
- Concurrency: two tills dispensing the last of a batch — exactly one succeeds.

## Finish

`composer check` green. Screenshot the POS at 1440 / 1280 / 1024 / 390 and a short laptop height,
empty and populated basket, motion reduced and allowed. Push the branch; **do not merge**.
