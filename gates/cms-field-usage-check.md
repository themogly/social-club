# CMS Field Usage Check (report-only — catches admin↔front-end drift)

Reusable read-only check: are all the fields the admin/CMS exposes actually used on the front end (or in email/meta/logic)? READ-ONLY — changes nothing, produces one report. Read the project's `CLAUDE.md` first.

## Why this exists
Any CMS-driven project drifts over time. When a front-end section that DISPLAYED a CMS field is removed or rebuilt during UI work, that field can become ORPHANED — still editable in the admin and present in the DB, but no longer rendered anywhere. The owner edits it, saves, and nothing changes — confusing, and it makes the CMS look broken. This is the inverse of the completeness check (which finds front-end things never built); this finds admin fields whose front-end home was removed. Run it after a round of UI restructuring, and before launch.

## Method
- `git checkout main && git pull`, state the commit. Change nothing.
- Inventory every CMS-managed field: go through the admin resources/forms (Filament resources, a SiteContent/settings gateway, etc.) and list every editable field/attribute, grouped by resource (pages/sections, products, locations, team, testimonials, FAQs, news, settings, gallery, etc.).
- For EACH field, TRACE whether it's actually consumed, by searching the codebase (grep across `resources/` and `app/`): Blade views/components, view models, SiteContent/settings reads, mailables/email templates, structured-data/meta builders, and admin-internal logic (status, sort, flags).
- **Critical rule: "not in a Blade view" does NOT mean "unused."** A field may be consumed only in email, meta/SEO, or logic. Check those before calling anything orphaned — otherwise you'll flag (and risk deleting) something that quietly matters, like a field used only in the booking-confirmation email.

## Report (`CMS-FIELD-AUDIT.md`) — categorise every finding
1. **Orphaned — editable in admin but consumed NOWHERE** (front end, email, meta, or logic). The real concern. Per item: field, resource, best guess at why (e.g. "displayed in a section that was removed"). Recommend RE-SURFACE (content wanted but lost its home) / REMOVE from admin (genuinely dead) / CONFIRM-WITH-OWNER. Do NOT decide deletion — flag for the owner.
2. **Used, but only in non-obvious places** — fields not in any Blade view but legitimately used in email, structured data/meta, or admin logic. List these so they are NOT mistaken for orphans.
3. **Reverse drift — hardcoded on the front end but should arguably be CMS-editable** — visible content baked into a template that the owner would reasonably expect to edit in the admin (e.g. text that used to be a field but got hardcoded during a rebuild). List with location.
4. **Admin fields with empty/placeholder values** — wired correctly but currently empty or holding sample data (owner content tasks).

Be specific: name the field, the resource, and the file/line where it is (or isn't) used. Don't pad — a clean result (everything wired) is a good outcome; say so. End with a count of genuine orphans and a one-line recommendation each.

## Finish
Commit ONLY the report. Change nothing else — re-surfacing or removal is a separate task after the owner decides.
