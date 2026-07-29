# Admin / CMS Audit (report-first, then fix in phases)

Reusable admin-panel audit. Run in a fresh session against a project with a running local site and admin panel. Read the project's `CLAUDE.md` and the `admin-design` skill first. This audits the BACK-OFFICE (CMS / admin resources / settings), not the public site — the public site has its own `design-audit`.

## Method
- `git checkout main && git pull`, branch `admin/audit-pass`. State the starting commit.
- Log into the admin panel and audit it BY USING IT — create and edit real records, open every resource and settings page, trigger uploads and saves. Look at the rendered admin, not just the code. Screenshot key screens.
- Audit EVERY admin surface: each CMS resource (list + create + edit), each settings page, every image/file upload field, and any custom admin pages or actions.
- Approach it as the non-technical owner would: could they do their weekly tasks without a manual? Where would they get stuck, confused, or able to break the public site?

## Step 1 — Report FIRST (before any fixes)
Write `audits/reports/admin-audit.md` using `- [item]: [what's wrong] → [what it should be] → [why it matters]`, organised PHASE 1 / 2 / 3, each phase ending with a `Review:` rationale. Commit the report before fixing. Be honest and tight — few items on a well-built admin is a good result, not a failure; don't invent problems.

### PHASE 1 — Critical (breaks the site, the data, or strands the owner)
- **Image fields with no shape control** feeding a fixed-ratio display slot (owner can upload a portrait photo that distorts/crops badly on the public site). Should have a crop tool locked to the display ratio + a front-end `object-cover` safety net.
- **Missing validation** the public site or checkout depends on: a field that can be saved empty/invalid and breaks a page or a booking/payment flow (price unset, date in the past, missing required relationship, money not in minor units).
- **Exposed dangerous/internal fields**: editable foreign keys, auto-generated slugs, logic-driven status flags, secrets/credentials as content fields, hard-delete of consequential records (bookings/customers/paid) with no guard.
- **Admin theming leaking into public CSS** (or vice versa) — dead/off-brand styles on the public side from admin dark-mode, etc.
- **Orphaned fields**: editable in admin but consumed nowhere (front end, email, meta, logic). (Cross-check with `gates/cms-field-usage-check.md`.)
- **Singleton editors built as loose custom pages** (settings/homepage/about as a bare `Filament\Pages\Page`) that don't behave: the form doesn't load the saved record on mount, Save doesn't persist, or validation/success-notification is missing. Should be a Resource (singleton style — create disabled, list→edit) or a complete settings page, living in the proper resource structure. **[Filament]**

### PHASE 2 — Refinement (owner UX)
- Field labels that are raw DB column names rather than plain English; missing help text on non-obvious fields (recommended image size, where it appears, what it does).
- Illogical field order/grouping (migration order instead of how the owner thinks); no sections/tabs on long forms.
- No sensible defaults; a new record is a wall of empty required fields.
- Poor list/table columns, filters, or empty states for the common task.

### PHASE 3 — Polish
- Helpful placeholders, confirmation on destructive actions, sensible success/validation messaging, consistent field components across resources, permissions scoped if multiple admin users.
- **Default framework furniture removed**: the stock Filament dashboard ships default widgets (a "Filament docs"/links card, version/account info) — unregister the ones the owner doesn't need so the dashboard isn't a framework default. Set the panel primary deliberately (see the admin-design skill) rather than leaving the default amber.

## Step 2 — Fix in phase order
Phase 1 first, gate between phases. One commit per item. `composer check` (or the project's check gate) green before EVERY commit; never commit red. Reuse shared field components/configuration so a fix lands across all resources at once. Re-test in the admin after each fix (create/edit a record, upload a wrong-shaped image, attempt an invalid save) to confirm it genuinely improved. Don't churn a working admin for taste.

## Separate: content & policy tasks
List "needs real content / real policy values / owner decision" (e.g. voucher expiry rules, which fields the owner wants editable) as OWNER tasks in their own section — not admin defects, and never fabricated.

## Finish
Update the report marking each item done/deferred. Check gate green, DECISIONS.md updated, push the branch, do not merge — owner reviews.
