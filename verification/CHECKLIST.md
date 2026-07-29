# Pre-Launch Verification Checklist (human-run launch gate)

**Purpose:** confirm that what the test suite proves in mocks actually works in REALITY, before a single real customer touches the site. Green tests cannot see the gap between a mocked payment/inbox and the real one — this checklist closes that gap. Work top to bottom; the money and email sections matter most. **Tailor this to the project** — add/remove items to match the actual features (this is a template; copy it into the repo as `verification/CHECKLIST.md` and adapt).

**Before you start:**
- [ ] Run on a near-production setup: real cache/queue store, the queue worker running, the scheduler running, queue NOT synchronous.
- [ ] Use the payment provider's **test mode** keys and test cards — never live keys for this pass.
- [ ] Have your real email inbox open (and a second address for customer-side emails).
- [ ] Note anything that fails as a bug to fix BEFORE going live — don't fix-and-continue from memory, write it down.

---

## 1. Money paths (highest risk — READ the amounts, don't assume)
For EACH paid flow (purchases, deposits, balance settlements, vouchers/credits, etc.):
- [ ] Complete the flow on the public site with a test card.
- [ ] **In the payment dashboard, confirm the charged amount is EXACTLY right** — the major/minor-unit (pounds/pence) check. £x.xx must show as the correct minor-unit value, not 100× too big or small.
- [ ] The resulting record (booking/order) appears correctly in admin with the right data, date, and status.
- [ ] Capacity/stock/availability decrements correctly; over-booking/over-selling is refused.
- [ ] Confirmation email (customer) + notification (admin) arrive with correct amounts and details.
- [ ] **Abandoned checkout:** start a flow, reach the payment page, close/cancel → no record is created, and any held slot/stock is released (immediately or by the expiry sweep).
- [ ] **Declined payment** (test decline card) → no record created.
- [ ] **Manual/offline payment** (if supported, e.g. bank transfer recorded in admin) → converts the record the same way as an online payment.
- [ ] **Credits/vouchers** (if any): created ONLY after payment succeeds; full-coverage redeems with no charge; partial-coverage charges only the remainder; a code can't be redeemed twice; abandoning mid-redeem does NOT burn the code.

## 2. Email paths (mocks never render templates — LOOK at every real one)
- [ ] Any local mail-preview route returns 404 in production config — it must never be public.
- [ ] Eyeball EVERY email template rendered for real: on-brand, no framework default text in headers, links absolute (not localhost).
- [ ] Transactional emails (confirmations, receipts, notifications, password/magic-link, etc.) each arrive and render.
- [ ] Any messaging-with-attachments feature → arrives, attachment opens, history logged.
- [ ] **Idempotency in reality:** trigger any scheduled/reminder email, then trigger it AGAIN → it does NOT send twice (prove it in reality, not just in tests).
- [ ] Newsletter (if any): double-opt-in confirm works; unsubscribe removes immediately and subsequent sends skip that address.
- [ ] Any opt-in checkbox at checkout behaves (ticked enters pipeline, unticked doesn't).

## 3. Enquiry / contact / lead paths
- [ ] Submit each public form → lands in admin, acknowledgement to customer, notification to admin.
- [ ] Admin reply → customer receives it; thread stored.
- [ ] Any "payments-off / enquiry-first" mode toggles correctly and creates no payment session when off.

## 4. Feature toggles & empty states
- [ ] Each feature toggle ON/OFF behaves (menu, routes, links all respect it).
- [ ] Fresh/zero-data states render intentionally (no broken/empty-looking sections); create one record → the relevant UI appears.

## 5. Domain rules & validation
- [ ] Exercise every business rule with real input (clash/overlap validation, min/max constraints, date logic, capacity) → confirm refusals show clear, correct messages.

## 6. Public site sweep (design + content)
- [ ] Walk EVERY page at mobile (~390) and desktop (~1440): all marketing pages, every form, every step of any multi-step flow, success/empty/404.
- [ ] No off-palette/off-brand elements; hierarchy reads; no awkward empty gaps where optional content is blank.
- [ ] Fill the EMPTY content fields the design depends on (hero images, portraits, gallery, real copy) — placeholders must be replaced with real owner content.
- [ ] Images optimised, no layout shift, no broken images.
- [ ] Run the CMS field usage check (see `../gates/`) so no admin field is orphaned and no expected-editable content is hardcoded.
- [ ] Admin sanity as the owner: create/edit a record end to end, upload a non-matching-shape image to a fixed-ratio field (it should crop, not distort), and attempt an invalid save (it should be blocked, not silently break the site). Consider running the admin audit (`../audits/admin-audit.md`) if the admin hasn't been reviewed.

## 7. Production-readiness (do NOT skip — these break SILENTLY)
- [ ] **The scheduler cron is set** to run every minute on the production server — without it, sweeps, reminders, and dispatchers never fire. The #1 silent-failure risk.
- [ ] The queue worker runs under a supervisor that restarts it on crash/deploy.
- [ ] Payment webhooks registered at the PRODUCTION URL with ALL required events (e.g. completed AND expired/cancelled). Send a test event and confirm receipt.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL` (email links depend on it), real app name.
- [ ] All real keys present and correct (payment, email, cache/queue). Use LIVE payment keys only when truly ready.
- [ ] Email sending domain verified (SPF/DKIM) or mail lands in spam.
- [ ] A real admin user exists; default/seeded credentials removed or changed.
- [ ] Config cached; one full deploy rehearsal (migrate, build, cache) on a staging copy first.
- [ ] HTTPS enforced (payment + secure cookies depend on it).
- [ ] A database backup exists AND you have tested restoring it.

## 8. The flip to live
- [ ] Swap test payment keys for LIVE keys ONLY after sections 1–2 pass in test mode.
- [ ] Do ONE real low-value transaction with a real card end to end → confirm it lands in the real payment account and the real confirmation email arrives → then refund it.
- [ ] Watch the queue worker and logs for the first few real transactions.

---

**If anything in sections 1, 2, or 7 fails, you are not ready to go live.** Sections 3–6 failing are bugs to fix but not money/trust/silent-failure risks. Fix the criticals, re-run that section, then proceed.

**Why this exists:** tests and audits gate *code*; this gates *launch*. It is run by a human, by hand, because no automated check can verify a real card charged the right amount in the real dashboard or a real email arrived looking right in a real inbox. Never skip it because the suite is green.
