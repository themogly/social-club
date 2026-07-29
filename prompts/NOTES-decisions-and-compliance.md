# NOTES — the legal model, security requirements, and decisions to confirm

Read this before prompt 01. Sections A and B are **requirements** — they change the schema.
Section C is the client-decision checklist that the prompt-01 checkpoint pauses for.

---

## A. The legal model the software encodes

**Be honest about the ground truth: Spanish CSCs operate under judicial *tolerance*, not
authorisation.** There is no national cannabis statute. The model comes from Supreme Court doctrine
on *consumo compartido* (STS 1472/2002, STS 888/2012, STS 1014/2013) plus association law
(**Ley Orgánica 1/2002**). Two regional laws that tried to regulate clubs were struck down by the
Constitutional Court (Catalonia's Ley 13/2017 → STC 100/2018; Navarra's Ley Foral 13/2014 →
STC 144/2017). **STS 484/2015 (caso Ebers)** is the counter-example: ~290 members, open admission,
industrial cultivation — convicted, because scale and institutional character break the defence.

**Consequence for the build: every threshold is a configurable setting, never a hardcoded constant.**
Municipal and regional practice varies and case law moves.

**What the software must make true:**

1. **Closed circuit.** Every gram traceable from acquisition/harvest → batch → an identified member,
   with a timestamp and an operator. *The cannabis belongs to the members; the association
   administers collective self-consumption.* Nothing leaves the premises.
2. **Members only, verified adults.** No dispensation without an active, in-date membership, an
   ID-verified date of birth over the configured minimum age, and a completed carencia.
3. **Prior consumer, vouched for.** An **avalador** (existing member sponsor) is recorded for each
   new member. Therapeutic members substitute a medical certificate.
4. **Declared, limited consumption.** Each member signs a *previsión de consumo* declaring monthly
   grams. Daily and monthly caps are **enforced at the counter — blocked, not warned.**
5. **Cost contribution, not sale.** Language throughout the UI and the reports is *aportación /
   contribución*, *socio*, *dispensación*. Never *venta*, *cliente*, *precio de venta*. Surplus is
   reinvested. Bar/merch income is a separate ledger so it never muddies the non-profit accounting.
6. **No advertising.** No public pages, no public menu, no SEO surface, no shareable stock listing.
   Everything is behind authentication. This is why `/` is the dashboard (prompt 14).
7. **The mandatory books** (Ley Orgánica 1/2002) are generated from the data, not kept in a
   spreadsheet: **libro de socios**, **libro de actas**, **libros contables** (prompt 16).

**Reference thresholds — seed these as defaults, all editable (prompt 03):**

| Setting | Default | Source |
|---|---|---|
| Minimum age | 18 | Law; many clubs set 21 |
| Carencia (waiting period) | 15 days | ConFac code of good practice |
| Daily gram limit | 3.5 g | ConFac; Inst. Nacional de Toxicología cites 3–5 g |
| Monthly gram ceiling | 100 g | ConFac; aggregated across all associations |
| Declared forecast options | 30 / 50 / 60 / 90 g | Common club practice |
| Active-member soft cap | 750 | ConFac recommendation (contested; sources vary widely) |
| Premises stock ceiling | active members × daily limit × **5 days** | THC Abogados rule of thumb |

That last one is a genuinely good dashboard alert. Worked with **this table's own 3.5 g default**:
*100 active members × 3.5 g × 5 days = 1,750 g maximum on site.* (Sources that quote 5 g/day give
2,500 g for the same club — which is exactly why the figure is a setting and the alert shows its
own arithmetic rather than a bare number.) Compute it live and warn when stock exceeds it (prompt 14).

**Keep honest about the limits of software.** Recording an obligation is not discharging it.
Paying staff from surplus has real PAYE/governance consequences; the club's actual legal status and
practice must be confirmed with its own lawyer and bookkeeper before go-live. The system's job is
to make the club *able* to answer questions, not to make the answers true.

---

## B. Security requirements (non-negotiable — a competitor already failed this way)

In late 2025 a public disclosure documented a major CSC platform exposing **~1.08 million member
records and ~986,000 passport/ID scans with no authentication at all** — sequential-integer IDOR on
member profiles, ID scans at predictable public paths with no token or expiry, a hardcoded payment
secret in the mobile app bundle, and cross-account message access. Because members were flagged with
a medicinal usage type, it became an **Article 9 special-category health-data breach**.

**Therefore, as build requirements — not advice:**

- **UUID (or ULID) primary keys on every user-addressable model.** No sequential integers in any
  route, API response, filename or QR payload. State this in prompt 01 and test it.
- **ID documents and member photos:** encrypted at rest, stored off the public disk, served only
  via **short-lived signed URLs**, never at a guessable path, and **every view is access-logged**.
- **Authorization on every endpoint, including object-ownership checks** — not just authentication.
  Policies on every Filament resource and every Livewire component. **Write the denial tests.**
- **Cannabis consumption data is Article 9 special-category data.** Explicit versioned consent,
  documented lawful basis, heightened access control, and a DPIA.
- **No secrets in any client bundle, ever.**
- **MFA available on admin accounts**; session management; access logging on sensitive records.
- **Breach runbook**: detection, and AEPD notification inside 72 hours (Article 33).
- Automated daily backups with a **tested restore**.

---

## C. Decisions to confirm at the prompt-01 checkpoint

Bring these answers from the client. Prompt 01 pauses until they're settled.

1. **Club identity** — association name, CIF/NIF, registered address, the list of premises
   (locations), and who the owners/board are.
2. **Scope model.** Recommended: `organisation_id` on everything (one org now); **Location is the
   operational scope** via a custom switcher + global scope, *not* Filament's built-in tenancy —
   because the owner needs an "All locations" rollup and org-wide member search, both of which fight
   automatic tenancy. Confirm.
3. **Limits.** Minimum age, carencia days, daily gram cap, monthly gram ceiling, the declared-forecast
   options, active-member cap, and whether a breach **hard-blocks** or allows a **logged manager
   override**. (Recommended: hard block, override permitted only to a manager, always logged.)
4. **Aforo** — the capacity limit per premises, and the club's opening hours (drives auto-checkout).
5. **Wallet & debt.** Confirm: wallet credit is per-location or pooled org-wide; whether debt is
   allowed at all and to what limit; whether membership fees can be charged to the wallet. (v1 had a
   ring-fencing model — carried forward in prompt 05, confirm it's still wanted.)
6. **Pricing model.** Price is **per gram per genetic**. Confirm tier-based pricing (different price
   per membership tier) vs one price with discounts. Confirm the therapeutic-member treatment.
7. **Avalador** — required for all new members, or waivable by a manager? Therapeutic exemption?
8. **Bar** — is there one? If so it gets its own catalogue, till and ledger (prompt 12).
9. **Languages** — Spanish + English minimum. Catalan? Which is the default?
10. **Currency display** — `€1.234,56` (Spanish convention, recommended) vs `€1,234.56`.
11. **Weight precision** — **0.01 g / centigrams** (recommended, matches scale hardware) vs 0.001 g.
12. **Multiple owners?** Owner is an assignable role more than one person can hold. Confirm.
13. **Data retention** — how long member data is kept after they leave, before purge.
14. **Existing member list** — CSV to import on day one? Get the file and its columns.
15. **Cultivation** — does the club grow, buy, or both? Determines whether prompt 18's cultivation
    module is in or out of the first release.

---

## D. Deliberately deferred (clean re-add paths noted in prompt 18)

- **Cultivation / seed-to-sale** beyond the consumption-forecast → authorised-volume calculation.
- **Card payments / SEPA / online fee collection** — the wallet ledger is built so a payment layer
  sits on top without touching it.
- **Hardware**: digital scale over RS232/USB, RFID/NFC fobs, fingerprint, label printers, kiosk.
  All are integration points on top of existing Actions, not schema changes.
- **Multi-organisation SaaS** — the schema is org-keyed and the scope model is the future tenant
  shape, so this is "allow >1 org", not a refactor.
- **AI assistant over club data** — every competitor markets one; it needs the data model first.
