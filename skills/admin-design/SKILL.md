---
name: admin-design
description: >
  Principles for building a CMS / admin panel that a real, often non-technical
  client can actually use — and that doesn't silently create drift on the public
  site. Use this whenever building or reviewing admin/back-office screens: CMS
  resources, settings pages, content forms, image/file upload fields, field
  schemas, validation, and the editing UX. Covers the admin quality bar (owner-
  friendly forms, sensible grouping/labels/help text), image-shape control at
  upload (crop/ratio), keeping every editable field actually consumed by the
  front end, separating admin theming from public CSS, validating the same data
  rules the site/checkout depends on, and not exposing dangerous or internal
  fields. Principle-led and transferable to any admin stack; written with
  Filament (the kit's default) in mind, with framework specifics marked as such.
  A project's own CLAUDE.md holds concrete per-project rules and takes precedence.
---

# Admin / CMS design — craft principles

The public site gets all the design attention; the admin panel is where the
client actually *lives*, and it's where quality quietly rots. A non-technical
owner edits content here every week. If the admin is confusing, unvalidated, or
lets them upload the wrong thing, the public site degrades no matter how polished
the frontend is. The admin has its own quality bar and its own recurring failure
modes. This skill encodes them. They're transferable to any admin stack;
Filament-specific notes are marked **[Filament]**.

The golden rule: **the admin is a product for a real human, usually non-technical.
Build it for them, not for you.**

## The admin is built for the owner, not the developer
- Field **labels** are plain English, not database column names (`featured_on_home`
  → "Show on homepage"). Add **help text** on anything non-obvious ("Recommended
  size, where it appears, what it does").
- **Group and order** fields logically (sections/tabs), in the order the owner
  thinks about them — not the order they sit in the migration.
- Provide **sensible defaults** so a new record isn't a wall of empty required
  fields.
- Empty states, table columns, and filters should make the common task obvious.
  The owner should be able to do the 3 things they do weekly without a manual.
- Don't expose **internal / system / computed** fields for editing: foreign keys,
  slugs that are auto-generated, status flags driven by logic, timestamps. If the
  app sets it, the owner shouldn't (and can't safely) edit it.

## Build editable singletons as resources or complete settings pages, not loose custom pages [Filament]
A lot of a content site's admin is **singletons** — one row of site settings, one
homepage-content record, one "about" record. How you stand these up matters, and
getting it wrong is a recurring source of both UX bugs and untidy code.

The failure mode: building a singleton editor as a **bare custom
`Filament\Pages\Page`** with a form bolted on but without the full machinery. It
half-works — and produces exactly the UX errors that then get blamed on Filament:
the form doesn't load the existing record on mount, Save doesn't persist (or saves
nothing), there's no validation or success notification, and the breadcrumb/nav
highlighting is wrong. It's also untidy — the editor floats outside the resource
convention instead of living in a resource folder with everything else.

Two correct patterns — pick deliberately:
- **A Resource (singleton style)** — usually the right default for a record-backed
  singleton. Make a normal Filament **Resource** for the model, disable create, and
  point the list at the single record's edit (or land straight on edit). You get
  the conventional `Resources/XxxResource/Pages/` structure and the standard edit
  page — which already loads, saves, validates and notifies correctly — and an
  admin that matches every other resource. Tidy and robust because you're using the
  framework's edit page, not re-implementing it.
- **A settings Page** — legitimate for genuine app-wide settings, but only if
  **complete**: it must use `InteractsWithForms`, define a real `form()` schema,
  `mount()` must fill the form from the stored values, and the save action must
  persist, validate, and fire a success notification — with the page registered in
  navigation correctly. A settings Page that skips any of these is the source of
  the "Filament UX errors". Follow Filament's official settings-page pattern (or a
  settings package) rather than hand-rolling a partial version.

Whichever you choose: **don't leave editors as loose custom pages outside the
resource structure**, match the project's existing resource conventions, and when
you replace a half-built page, remove it (and confirm nothing references it) rather
than leaving dead screens behind.

## Image & file uploads: control the shape at the source
This is the highest-frequency admin→frontend drift. The front end displays an
image at a fixed ratio (square card, 16:9 hero); if the CMS accepts any-shaped
image, the owner uploads a portrait phone photo and it crops badly or distorts.
- **First, decide if the image should be an upload field at all — brand chrome is
  not content.** The logo, favicon, and fixed decorative/structural artwork that's
  part of the template design are **static build assets, not CMS upload fields.**
  AI builders make everything editable; a logo/favicon upload field on a normal
  single-site brochure is a field the owner should never touch — it becomes an
  orphan and a footgun. Only make an image an upload field if it's genuine owner
  content that changes (photos, case studies, gallery, per-record images). Rule of
  thumb: "would this owner ever sensibly swap this from the admin?" — no for
  chrome, yes for content. (Multi-tenant/white-label, where the logo IS per-tenant
  editable, is the exception — judge by the project.)
- **Where the display ratio is fixed, control the ratio at upload** — give the
  owner a **crop tool** so they upload anything and position the crop to the
  required ratio (keeping faces/subjects in frame). The stored image is then
  always the right shape. **[Filament]** use the built-in image editor / crop
  with a locked aspect ratio on the field; prefer built-in over adding a package.
- **Don't just hard-reject** wrong-shaped uploads with a validation error — that
  strands a non-technical owner with no way forward. The crop tool *is* the UX.
- **Always pair with a front-end `object-cover` + `aspect-ratio` safety net** so
  even an un-cropped or legacy image degrades gracefully instead of distorting.
- Only lock ratios where the display needs it; leave free-form fields (inline
  article images) unrestricted.
- **Optimise every upload automatically — the owner must not be able to bloat the
  site.** A non-technical owner will upload a 5MB phone photo without a second
  thought. The pipeline should, on upload: convert to **WebP** (or AVIF),
  **resize/cap dimensions** to what the largest display slot actually needs
  (don't store a 4000px image for a 600px slot), and strip metadata. Combined with
  the crop tool, the owner physically cannot produce a wrong-shaped or oversized
  image. Set reasonable size/type limits with clear messages as a backstop.
- Generate the responsive variants the front end needs (or store at a sensible max
  and let the front end request sizes) so pages can serve `srcset` — see the
  frontend-design skill's image-performance section.

## Every editable field must be consumed somewhere
If the admin lets the owner edit a field, the front end (or email, or meta, or
logic) must actually *use* it. **Orphaned fields** — editable but rendered
nowhere — are a real and common drift: a section gets redesigned, its render is
removed, the settings field is left behind. The owner edits it, nothing happens,
trust erodes.
- When you remove or restructure a front-end section, remove (or repurpose) the
  admin fields that fed it in the same change.
- "Not in a Blade view" ≠ "unused" — a field may legitimately feed email, meta/
  SEO, structured data, or scoping logic. Check those before deleting.
- Periodically reconcile admin fields against their consumers (the kit's
  `gates/cms-field-usage-check.md` does this).

## Validate the rules the site and checkout actually depend on
The admin is an input surface for data the public site and money flows rely on.
- Validate at the **admin form** level the same constraints the frontend assumes:
  required fields, formats, ranges, enum values, money as integer minor units,
  sensible min/max.
- If the checkout/booking logic assumes a price is set, a date is in the future,
  or a relationship exists, the admin must not let the owner save a record that
  violates it. Fail at edit time, not at customer time.
- Required-for-publish vs required-always: let the owner save a draft, but
  validate completeness before something goes live.

## Keep admin theming separate from the public site
**[Filament]** The admin panel has its own theme and often its own dark mode. Do
**not** entangle admin theming with public CSS:
- Don't let admin dark-mode variables/utilities leak into the public stylesheet
  (and vice versa) — it creates dead, off-brand CSS on the public side.
- When cleaning public CSS, confirm you're not removing something the admin theme
  needs; when theming the admin, don't touch public tokens.
- The public brand palette and the admin's functional theme are different concerns.
- **Set the admin primary colour deliberately — don't ship on the default by
  accident.** Filament defaults to amber; if no one chose it, that orange is an
  un-decided default, not a design. Pick the panel's primary as a conscious call
  (a neutral grey ramp for a clean black-and-white admin, or the brand colour if it
  works functionally) via `->colors(['primary' => ...])`. Two rules when choosing:
  (1) the admin primary is a *functional* colour for buttons/active-states/focus,
  judged on **legibility** (button-text contrast must pass AA) — not on matching the
  public brand; a vivid, light, or high-chroma brand colour (e.g. a bright cyan)
  often fails as a button background and is the wrong choice here even though it's
  "on brand". (2) **Never set the primary to pure black or pure white** — Filament
  generates a hover/focus/disabled shade ramp from the primary, and a pure value
  leaves no room for those states; use a proper neutral *ramp* (Slate/Zinc/Gray/
  Neutral/Stone) instead, which reads as black-and-white while keeping working states.
- **Strip default Filament dashboard furniture the owner doesn't need** — the
  stock dashboard ships info/links widgets (e.g. a "Filament docs" card, version
  info). Unregister the defaults and show the owner something useful (or a clean
  dashboard), so the back-office looks built-for-them, not a framework default.

## Don't give the owner footguns
- No hard-delete of records with downstream consequences without a confirmation
  (or use soft deletes). Bookings, customers, paid records especially.
- Don't expose destructive bulk actions casually.
- Permissions: if there will be multiple admin users, scope who can do what; don't
  give every user the ability to change pricing, delete data, or edit settings.
- Secrets/credentials are never editable content fields.

## Anti-patterns (the tells of an admin built for the dev, not the client)
- Raw DB column names as labels; no help text on non-obvious fields.
- Any-shaped image uploads feeding fixed-ratio display slots (distortion/bad crops).
- Editable fields that render nowhere (orphans).
- No validation — the owner can save data that breaks the public site or checkout.
- Internal/computed/system fields exposed for editing.
- Admin theming bleeding into public CSS.
- Singleton editors built as bare custom pages — record doesn't load on mount,
  save/validation/success-notification missing — instead of resources or
  complete settings pages. **[Filament]**
- Hard-delete with no guard on consequential records.
- A flat wall of ungrouped fields in migration order.

## Process
1. For each resource/settings page, list the fields and ask, per field: does the
   owner understand the label? do they need help text? should they even be editing
   this? is it grouped sensibly?
2. For each image field: what ratio does the front end display it at? Lock that
   ratio with a crop tool; add the `object-cover` safety net.
3. For each field: where is it consumed? If nowhere, it's an orphan — fix.
4. Validate the constraints the site/checkout depend on, at the form.
5. Confirm admin theming is isolated from public CSS.
6. Test as the owner: create and edit a record start to finish, upload a wrong-
   shaped image, try to save something invalid — does the panel guide you or
   strand you?

A project's `CLAUDE.md` holds the concrete per-project specifics (which fields,
which ratios, which roles) and always takes precedence over this generic guidance.
