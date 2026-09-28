# What you need to decide — after the pre-live audits and prompts 275–279

*2026-09-28. Everything below is built, merged to `main` and green. Where a decision was yours, I built the
recommended answer so nothing waited, and it can be changed. Nothing here is legal advice; the legal items need the
club's gestor/lawyer.*

## A. Decisions I made for you (overrule any of them)

| # | Where | What I built | The alternative |
|---|---|---|---|
| A1 | 276 (your 269) Hachís | **UI only**: "Hachís" is a first-level product type, stored as Extracto + Hachís. No migration, and it behaves exactly like flower by weight. | A real separate product type, if you want hash reported as its own category. |
| A2 | 277 (your 270) central store | The grow is **a location of its own kind** ("Almacén / cultivo"). Moving part of a batch creates a **child batch with the same lote number** that traces back to the harvest. | One batch spread across several locations: a much bigger change for no visible gain. |
| A3 | 277 stock ceiling | The store has **no per-location ceiling**. An **association-wide** figure (all stock against all active members) shows as a **warning** on the owner's dashboard. | Something stricter, or nothing. **The legal basis is unconfirmed: ask the gestor.** |
| A4 | 278 (your 271) tier prices | A membership tier becomes a **% discount** on any batch's price ("Descuento de la tarifa (%)" on the tier). | A price per tier on every batch (heavy), or no tier pricing. **Existing tier price rows only apply to batches without their own price; set the tier % if you used them.** |
| A5 | 278 split sale | A sale that runs from one batch into the next charges **each part at its own batch's price**, and the counter warns before commit. | The whole line at the first batch's price. |
| A6 | 278 price unit | **Price per gram** (per unit for pre-rolls and edibles). | A total price for the batch, divided by its weight. |
| A7 | 278 strain price | The strain's per-sede price is **kept as a fallback** for a batch with no price of its own. Every existing batch was given its strain's price, so in practice it's rarely used. | Remove the strain price table entirely, which would mean re-pricing any unpriced batch first. |
| A8 | 270 PIN lockout | A **correct PIN no longer clears the lockout**; failures wear off with time (5 min for attempts, an hour for escalation). | The old behaviour: a correct PIN resets it. That made brute-forcing the owner's PIN possible. |
| A9 | 270 PINs | **PINs must be unique** across all accounts. | Allow duplicates. Since the PIN became a sign-in, a shared PIN signs you in as whoever matches first. |
| A10 | 271 debt limit | A debt limit of **0 means "no club-wide cap"**, and at the counter a member may owe within their **approved tab**. The door keeps its own warning threshold. | 0 meaning "nobody may owe anything". |
| A11 | 271 Publicada / photos | "Publicada" controls **the members' menu**. The counter still sees unpublished strains. Photos show on the counter and the menu. | Remove those fields. |
| A12 | 275 business day | Date-only checks (batch expiry, sanctions) use the sede's **business day**: until the 06:00 cutoff it's still "yesterday". | The calendar day at midnight. |

## B. Open questions only you can answer

1. **Strict euro parsing everywhere?** Every field a person types money into is strict ("1.250" is refused). The question is whether the general parser should be too.
2. **Enforce the CSP?** The security policy reports violations to the log (`csp.violation`). Turn on `CSP_ENFORCE=true` once that log stays quiet in real use.
3. **Keep the PIN lockout working in a Redis outage?** Today the lockout fails open if Redis is down, so the counter never goes down. Moving the lockout counters to the database would keep them working through a Redis outage.
4. **Minimum age and retention periods**: set them on legal advice. Retention now has a floor of 365 days, and the two nightly jobs that anonymise and redact can't be undone.
5. **Events or assembly times entered before 271** are stored as wall-clock times and now display shifted by 1–2 hours. Re-save any that matter. Pre-live, there may be none.
6. **Multi-club later**: `role_permission_overrides` has no organisation column. That's fine with one club, but it's needed before a second.
7. **Stored reason texts** (e.g. "Compra en barra") appear in Spanish in the English panel.

## C. Before go-live (ops, yours)

- `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`
- `TRUSTED_PROXIES` set to your load balancer's addresses, not `*`
- Private, encrypted documents bucket (`AWS_DOCUMENTS_SSE`, KMS)
- Sentry DSN; Horizon and the scheduler cron supervised; SSL/HSTS
- **Everyone sets a fresh, distinct PIN** (existing duplicates can't be detected from the hashes)
- Never run the demo or dev seeders in production
- Confirm the MySQL CI run passes on the release commit
- If you use a central store: create it under Sedes ("Tipo de ubicación: Almacén / cultivo")

## D. Known gaps (small)

- After the server closes the sign-up modal, the next Android Back press does nothing.
- The member app's signed-in pages were checked in code and a browser, but not by a full accessibility audit (axe isn't installed).
- Photos are resized on upload, but there's no server-side thumbnail generation.
