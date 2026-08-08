# Go-live verification checklist — Phase D gate 13 (TAILORED to this project)

A HUMAN walk-through of the highest-risk flows on **staging with realistic data**, before real members. This
REPLACES the generic template (which assumed a payment provider + a public marketing site — this app has neither:
money is **cash + integer-cent wallet, no Stripe**, and a Spanish CSC has **no public/indexable surface at all**).
Tailored to this app's real risk profile — Article-9 health data, cash reconciliation, and compliance that must
**BLOCK, not warn**. The suite proves units work in mocks; this proves the *system* does the right thing end to end.

**Before you start:** near-production setup (real Redis, Horizon + scheduler running, queue NOT sync); real inbox +
a second member address open; `MAIL_MAILER=resend` with a verified sending domain; the private `documents` disk on
its real S3 bucket. Write down every failure as a numbered-prompt fix — never fix-and-continue from memory.

---

## A. Security & access — the leak this product exists to avoid (do NOT skip)
- [ ] Only three routes are reachable unauthenticated: the member magic-link, the tokenised application, the lockdown
  reactivation link. Everything else 302s to login.
- [ ] A **wrong-location** and a **wrong-role** operator are BLOCKED (403), not just hidden, from members, tills,
  reports, and each Filament resource. Prove a denial, not only an allow.
- [ ] An **ID scan / member photo** serves ONLY via a short-lived signed URL bound to the viewer: a copied URL fails
  for a second user (403), a guessed path 404s, and **every view writes a `document_access_logs` row**.
- [ ] MFA enables on an admin account and is enforced next login. Seeded/default credentials removed.
- [ ] **`php artisan csc:sync-permissions --check` exits 0**, and *Salud del sistema* → Permisos reads *"coinciden
      con el código"*. A club whose matrix does not match `App\Support\Permissions` is **not ready**, in either
      direction: a permission the code grants may not have arrived (an OWNER told *"ask a manager"*), and one the
      code has **withdrawn** may still be live. It is invisible until somebody is refused — or, worse, is not
      (prompt 214).
- [ ] Panic lockdown trips org-wide from the counter; the off-terminal reactivation token works once and is throttled.
- [ ] View-source a member page: **no secret / VAPID private key / API key** in the bundle. `X-Robots-Tag: noindex`
  on every response; `/robots.txt` disallows all; no local `/dev/mail` preview reachable in production.

## B. Money & weight — READ the stored values, don't assume (highest risk after security)
- [ ] A **€12,50** contribution stores **1250 cents**; a **3,5 g** dispensation stores **350 cg** — check the row, not
  the screen. No float anywhere in the amount.
- [ ] Cash + wallet tender split reconciles to the total; an **under-tender is refused**; over-tender gives change.
- [ ] The contribution receipt reads **aportación / contribución** — never *venta/precio de venta*. Bar income shows as
  **"Barra y tienda"** in reports and never lands in `cash_contributions`.
- [ ] A **price override** needs the permission + a reason; a **non-numeric** entry is rejected (never a €0 dispense).

## C. Counter compliance — must BLOCK inside the transaction
- [ ] **No member ⇒ no dispensation** (server-enforced).
- [ ] Push a member over the **daily/monthly gram cap** → refused; a `limits.override` holder forces it with a reason →
  an audit row is written. Repeat for **carencia / age / membership / sanction** per the enforcement matrix.
- [ ] **Photo (157):** `counter.photo`=OFF → a photo-less member dispenses normally; =WARN → dispenses with the warning
  shown; =OVERRIDE → blocked until a manager forces it with a reason + audit. No-photo member at the door/POS shows the
  capture prompt; a captured photo lands encrypted and renders via the signed URL.
- [ ] FEFO picks the oldest non-expired batch; an expired batch is refused. Signature captured where the sede mandates it.
- [ ] **Void / refund** returns stock to the originating batch and reverses the wallet off-till; a correction is a void +
  a fresh linked row, never a silent edit.
- [ ] Offline: the commit is refused client AND server (fail-closed); the basket survives until reconnect.

## D. Cash & till (arqueo)
- [ ] Open a till, record movements, **blind close** — the expected figure is withheld until the count is entered; a
  variance beyond tolerance demands a note; the Z-report totals match the ledger (expected cash is DERIVED, not stored).

## E. Email — mocks never render templates; LOOK at every real one
- [ ] Every member mailable renders on-brand with the **club letterhead** (logo CID-embedded, or the name wordmark with
  no logo); links absolute, no framework-default text; `contact_email` is the **Reply-To** (and is ABSENT on the lockdown
  mail). Send one live to a real inbox.
- [ ] **Idempotency in reality:** fire a scheduled reminder twice → it does NOT double-send.

## F. Member lifecycle & PWA
- [ ] Invite → application (required fields marked; submits with **no photo/ID**; underage refused) → approval creates the
  member, mails the QR card, stamps consent with the **version + locale actually shown**.
- [ ] Magic-link login (single-use, throttled); wallet + history live; offline QR card works; messages send/reply in the
  bordered padded inputs with a visible focus ring; notifications list all channels with real labels; web-push delivers
  and a per-channel opt-out is honoured.

## G. Governance, documents & organisation identity (159)
- [ ] Announcement + event publish to the PWA; RSVP works; a convocatoria mails with the letterhead.
- [ ] Statutory PDFs (libro de socios, registro de dispensación) generate; the **RAT refuses without a legal name**.
- [ ] Edit the club identity (owner only; manager/staff 403; audit row with before/after). Set a logo → it appears in the
  email + PDFs; remove it → the wordmark shows. Edit a consent text **without** a version bump → refused; bump → an
  already-consented member still resolves to the exact text they read; the new legal name shows on new documents, old
  ones unchanged.

## H. Privacy / RGPD & i18n
- [ ] A data-export request produces the member's data; **right-to-erasure** anonymises the row AND deletes their photo /
  ID scan / signature from the private disk (retention-obligation docs redacted, not destroyed).
- [ ] Switch UI EN ⇄ ES (persists to the user, effective next request); the applicant's consent is shown + recorded in
  the language they read.

## I. Production-readiness — these break SILENTLY
- [ ] **The scheduler cron runs every minute** on the host — without it, sweeps/reminders/dispatchers never fire (#1 silent
  risk). Horizon runs under a supervisor. `APP_ENV=production`, `APP_DEBUG=false`, real `APP_URL` (email links depend on it).
- [ ] Redis reachable; Resend domain verified (SPF/DKIM); Sentry DSN set + errors arrive; VAPID keys set + push delivers.
- [ ] **A DB + encrypted-`documents` backup exists AND a restore has been rehearsed** (Article-9 data — mandatory).
- [ ] Config/route/event caches built on deploy AND all caches busted on deploy. One full deploy rehearsal on staging first.
- [ ] The suite has been run once on the **production PHP/Node version** (CI is 8.3/20; prod 8.5/24 — see PRE-STAGING gate).

## J. Design / a11y spot-check on the real host
- [ ] Dark mode across admin + counter + PWA (dim interiors); tablet widths on the counter apps; above-the-fold text visible
  without JS; effects respect `prefers-reduced-motion`; AA button contrast.

---
**If any box in A, B, C or I fails, you are not ready to go live.** Everything else is a bug to fix (a numbered prompt /
branch, never a checklist edit). Re-walk A–C after every fix — those are the ones that end the association's legal
position if wrong. Tests and audits gate *code*; this gates *launch*, and only a human can run it.
