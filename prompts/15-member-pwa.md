# 15 — Member PWA & club communications

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–14 merged.

`git checkout main && git pull` → `git checkout -b feat/member-pwa`.

> **Build a PWA, not a native app.** An installable web app sidesteps app-store review entirely —
> which for a cannabis product is a real, recurring problem (the market leader had to describe itself
> as "private member association solutions" and carries a 17+ drug-content rating). A PWA gets ~90%
> of the member-app value with none of the platform risk, and ships as part of this codebase.

## Build

**Auth (the second guard)**
- A `member` guard, separate from the staff guard. Passwordless is the better fit here: magic link
  by email, or an OTP code. Sessions are long-lived on a trusted device; logout is easy to find.
- The seam was left clean in prompts 02–03; this is the additive step. Staff and members must never
  share a session or a panel.

**Home**
- Digital **QR membership card** — the same signed, revocable token as the printed/emailed card
  (prompt 04), large, bright, and readable by the door scanner. Works offline once cached.
- Membership status, tier, expiry, and member number.
- **Consumption allowance** — today and month-to-date against their limits, from
  `ResolveMemberLimits` (never recomputed here), as the same gauge the counter shows.
- **Wallet balance** and recent movements.

**Menu (private, authenticated only)**
- The location's **published** genetics: name, photos, THC/CBD %, terpenes, cultivation type, price
  per gram **at that member's tier**, and availability. Articles too, if the bar is in scope.
- **This must never be publicly reachable.** No unauthenticated route, no shared link, no
  server-rendered preview, no OG image, no indexable path. Advertising is the legal line. Test it.

**History**
- Dispensations: date, genetic, grams, contribution. Bar orders. Wallet movements. Visits.
- Downloadable as their **RGPD data export**.

**Club communications — build BOTH sides here**

The member-facing half is useless without the admin half, and the admin half has no home elsewhere
in the build. Both belong in this prompt.

- **Admin (Filament — add the "Comunicaciones" nav group, per prompt 14's note):** an Announcement
  resource (title, body, location scope or all, publish/expire dates, author) and an Event resource
  (title, description, start, capacity) with its RSVP list and attendee count. Permissioned.
- **Member:** the announcements feed, and events with RSVP.
- **Push** via the Web Push API — opt-in, subscription stored per member, with a **per-channel
  opt-out the member controls**. Triggers: low wallet balance, membership expiring, new announcement,
  event reminder. Queued through Horizon.
- **Transactional email inventory** — this prompt owns the complete list and its tests: QR card
  (prompt 04), application approved/rejected (04), renewal reminder (05), plus the pushes above.
  Every mailable gets a permanent render test and a `/dev/mail` preview, per the kit's mail rule.
  Marketing sends are out of scope entirely: the club may not advertise. Operational club-to-member
  messages are fine; anything resembling promotion is not.

**Applications**
- The tokenised invite link (prompt 04) opens the application form inside the PWA, so a sponsor can
  hand a prospective member a link and they complete it on their own phone.

**PWA mechanics**
- Manifest, icons, offline shell, installable on iOS and Android. The QR card must render from cache
  with no network. Everything else may require connectivity.

## Rules

- **Read-mostly.** A member can top up nothing, order nothing and change no money in this phase. They
  view, and they identify themselves. Reservations and top-ups are prompt 18.
- Every query is scoped to the authenticated member. Object-ownership checks on every route —
  a member must not be able to reach another member's anything by changing an identifier.
- **UUID/token identifiers only** in URLs and payloads. No sequential ids, ever.
- No secrets in the client bundle. No member data in query strings.
- Limits, prices and balances come from the same resolvers the counter uses. No second arithmetic.
- Spanish default, English available; the whole PWA is translated, not half.

## Tests (required)

- The menu, card, history and every member route are **401/redirect when unauthenticated** — assert
  on the response, for each route.
- Member A cannot load member B's card, history, wallet or export (403/404). Test every endpoint.
- The QR token from the PWA resolves at check-in identically to the emailed one; a revoked token fails.
- The allowance figures match `ResolveMemberLimits` exactly.
- Magic-link/OTP tokens are single-use and expire; rate-limited.
- No member route appears in the sitemap or is reachable without the guard; `noindex` is served.
- The installed PWA renders the QR card offline.

## Finish

`composer check` green. Screenshot the PWA at 390 and 1024, light and dark. Record the member-guard
decision and the auth method in DECISIONS.md. Push the branch; **do not merge**.
