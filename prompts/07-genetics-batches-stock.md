# 07 — Genetics, batches, weight-based stock, merma & the bar catalogue

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–03 merged.

`git checkout main && git pull` → `git checkout -b feat/genetics-stock`.

> **The core modelling point.** Cannabis is **not** a product with a price. A **Genetic** is a strain
> definition with cannabinoid data, priced **per gram**; a **Batch** is a specific lot of that
> genetic at a location with its own stock, cost, harvest date and lab report. Stock is an integer
> **centigram** count. Everything else — bar, food, merch — is a plain unit **Article** with its own
> catalogue and its own ledger. Do not collapse these into one `products` table.

## Build

**Genetics (org-wide definitions, Filament resource)**
- Name, description, category, `thc_pct` / `cbd_pct` (decimal 2 dp), terpene profile (JSON),
  `cultivation_type` (INDOOR | OUTDOOR | GREENHOUSE), images (cropped at upload to a locked ratio,
  WebP, dimensions capped, metadata stripped), `published` (menu visibility), active.
- **Price per gram** — either a single price or per-tier prices (prompt 08 owns pricing; expose the
  hook here). Stored in cents per gram.
- List view shows live **total remaining stock in grams across open batches** at the active location,
  with the low-stock indicator. Detail shows the batch list and a movement chart.

**Batches (per location)**
- `batch_no` (generated, configurable format), genetic, harvest/acquired date, expiry,
  `initial_cg`, `remaining_cg`, `cost_cents` per gram, lab report attachment, notes, status
  (OPEN | CLOSED | QUARANTINED).
- **Intake** action: record a new batch with its weight in grams (2 dp) → converted to centigrams by
  the Action. **Adjustment**: signed movement with a required reason. **Merma**: a distinct movement
  type for drying/handling loss, permissioned (`stock.merma`), always with a reason — this is the
  reconciliation between weighed-out and stock-deducted and it must not hide inside ADJUSTMENT.
- **FEFO/FIFO batch selection** at the counter: default to the oldest open non-expired batch of that
  genetic; the operator may pick another explicitly. Expired batches are blocked from dispensing.
- Batch → dispensation chain is queryable both ways: everything dispensed from a batch, and the
  batch behind any dispensation. This is the traceability spine.

**Articles (bar / food / merch, per location)**
- Name, category, `price_cents`, `stock` (int units), low_stock_threshold, images, active.
- Restock and adjustment actions writing `StockMovement` with unit quantities.
- **Completely separate ledger from cannabis** — separate reports, separate till lines, so bar income
  never muddies the contribution accounting.

**Stock movements — one writer**
- All stock changes go through a single atomic `App\Actions\RecordStockMovement` (intake, dispense,
  sale, adjustment, merma, transfer) that updates the running remaining/stock value **inside a
  transaction with a row lock**. The POS calls the same Action. Nothing else touches stock columns.
- Movement ledger per batch/article with operator, reason, reference and timestamp.

**Stock take / inventory count**
- A counting workflow: open a count for a location, enter counted grams per batch and units per
  article, review the variance, then commit — which writes ADJUSTMENT/MERMA movements with the count
  as the reference. Permissioned and audited. Never a silent overwrite.

**Alerts**
- Low stock per genetic and per article (configurable thresholds).
- Batch nearing expiry.
- **Premises stock ceiling**: warn when total on-site grams exceed
  `active_members × daily_limit_g × ceiling_days` (setting, default 5 days). Surfaced on the
  dashboard in prompt 14 — it is a compliance signal, not a merchandising one.

## Rules

- **Weight is integer centigrams end to end.** Grams to 2 dp only at the input/display edge, via the
  `Weight` cast. A float grams value stored anywhere is a bug.
- Stock is per-location; no cross-location bleed. A transfer between locations is an explicit,
  permissioned, reported movement pair.
- Dispensing depletes stock — the *aportación* framing changes the language, never the inventory truth.
- Images: crop at upload, never hard-reject a wrong shape; pair with `object-cover` + `aspect-ratio`
  on the front end as a safety net.
- Genetics are org-wide definitions; **batches, stock and prices are per-location.**

## Tests (required)

- Intake 12.50 g → `remaining_cg` +1250 and one INTAKE movement; adjustment −3.25 g → −325 with a
  recorded reason; merma is its own type and requires the permission.
- Concurrency: two simultaneous dispensations from the same batch cannot oversell it (row lock).
- FEFO picks the oldest open batch; an expired batch is refused.
- `lowStock()` returns only at/under threshold; the premises-ceiling alert fires at the right total.
- A stock take commits variances as movements and leaves the ledger reconciling to the counted figure.
- Cross-location batch access is refused (403/404), by policy not by hidden UI.
- Articles are unaffected by cannabis stock logic and vice versa.

## Finish

`composer check` green. Add `RecordStockMovement` and the batch-selection rule to CLAUDE.md reference
implementations. Push the branch; **do not merge**.
