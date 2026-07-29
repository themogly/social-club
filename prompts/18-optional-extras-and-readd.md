# 18 — Optional extras & documented re-add paths

**Not one branch.** A menu of independent mini-prompts, each its own branch, each optional. Build
none of these to "finish" the product — the product is finished at 17. Build them when the club asks.

`git checkout main && git pull` → `git checkout -b feat/<the-one-you-picked>`.

---

## A. Cultivation / seed-to-sale

Plants, grow rooms, phases, nutrient and cost logging, harvest records with yields, drying and
curing loss, and the **batch → stock → dispensation** chain closed end to end. The
consumption-forecast aggregate (prompt 06) already computes the authorised volume; this module
records what was actually grown against it. Cost per gram flows into stock valuation.

Only build this if the club cultivates rather than acquires — confirm at the prompt-01 checkpoint.

## B. Hardware integrations

Each sits on top of an existing Action; none needs a schema change.

- **Digital scale** over RS232/USB (Ohaus Scout SKX123 / Navigator NV212 are the market standards) —
  reads weight straight into the POS weight field, with merma reconciliation between weighed and
  deducted. The single biggest counter-speed win.
- **RFID / NFC** cards, fobs and wristbands as an alternative to the QR at check-in and the counter.
- **Label and receipt printers**; barcode generation for articles.
- **Self-service kiosk** — check-in, balance top-up, and queue ticketing.

## C. Member-facing additions (extend prompt 15)

- **Wallet top-up** by card (Stripe/SEPA) — this is where a payment layer first earns its place; it
  sits on top of the existing cents ledger without changing it.
- **Reservations / pre-orders** with a ready-for-pickup queue at the counter.
- **Encrypted member ↔ club chat**; strain ratings and voting; referral links with rewards.
- **Apple / Google Wallet passes** — needs an Apple Developer account with a Pass Type ID certificate
  and a Google Cloud service account with a Wallet issuer account. Real external prerequisites; the
  emailed QR and the PWA card remain the always-available fallback.

## D. Staff operations

- **Clock in / out, breaks, shift rotas**, hours report.
- **Sales and dispensations per operator** over time; discount and void usage patterns per staff
  member. Frame it as visibility, not surveillance — the honest use is spotting a broken process
  before it becomes an accusation.
- **Staff-ops notifications**: low stock, expiring memberships, unreconciled tills, aforo reached.
  Queued via Horizon. Staff-only — no member-facing push here (that's prompt 15).

## E. Platform

- **Multi-organisation SaaS.** The schema is already org-keyed and the scope model is the future
  tenant shape, so this is "allow more than one organisation, add org registration and billing" —
  not a refactor. Keep it that way: never let a query lose its `organisation_id`.
- **Public API / webhooks** for a club's own integrations.
- **AI assistant over club data** — every competitor markets one. It needs the data model finished
  first, which after prompt 17 it is. Scope it to *querying* the club's own data, and be careful
  never to let it become a route around the permission system.

---

## Deliberately NOT built, and why

- **A public website, menu, or club directory listing.** Spanish CSCs may not advertise. This is the
  one item on this page that should stay unbuilt regardless of what a competitor does — at least one
  incumbent ships public social posts and a public club directory, which is a risk they have chosen
  to accept on their members' behalf. Don't copy it without the club's lawyer saying so in writing.
- **Anything that lets cannabis be attributed to a non-member**, or leave the premises in the
  system's record. The closed circuit is the legal model; software that helps break it is a liability.
