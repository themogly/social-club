# 08 — Pricing, tiers & discounts

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–07 merged.

`git checkout main && git pull` → `git checkout -b feat/pricing-discounts`.

## Build

**Price per gram, per genetic, per location**
- The base price is `price_per_gram_cents` on the genetic, per location.
- **Tier pricing** (confirm at the prompt-01 checkpoint): each membership tier may carry its own
  price per gram for a genetic — not a discount off a headline price, an actual price for that tier.
  This matches how clubs really work and keeps the receipt honest.

**Discounts**
- **Standard, org-wide templates** a location may enable: staff, local, concession, **therapeutic**.
  Fixed percentage or fixed amount off, per genetic-category or global.
- **Per-member custom** discount, owner-assigned (`member.discount.assign`), with an optional expiry.
- Deterministic resolution order, stated in the UI and in DECISIONS.md, e.g.: **tier price → best
  single applicable discount → per-member custom (if better)**. Discounts do **not** stack by default.
  Whatever order is chosen, exactly one place computes it: `App\Actions\ResolvePrice`.
- Bar articles carry their own simpler discount path; keep it separate from the genetic pricing.

**Presentation**
- The counter shows the applied price and *why* — "Therapeutic −20%" — not a silently different
  number. The receipt shows the same. A member who asks why must get a straight answer.

## Rules

- One resolver. POS, PWA menu, reports and receipts all call `ResolvePrice`; no second copy of the
  arithmetic anywhere.
- Prices and discounts are **frozen into the transaction snapshot** at commit — changing a price
  later never rewrites history.
- Money in integer cents; the shared `round_half_up` for every percentage calculation.
- Discount changes are audited (who, what, when, from → to).

## Tests (required)

- Tier price beats base price; the best single discount applies; discounts do not stack unless the
  setting says so.
- Per-member custom discount overrides the standard one when better, and expires on its date.
- Therapeutic members get the therapeutic treatment automatically.
- Rounding: a 17.5% discount on €7,49/g × 1.33 g resolves to the exact pinned cent value.
- A price change after a dispensation does not alter that dispensation's stored total.
- `member.discount.assign` refused to manager and staff.

## Finish

`composer check` green. Record the resolution order in DECISIONS.md. Add `ResolvePrice` to CLAUDE.md
reference implementations. Push the branch; **do not merge**.
