# Admin / CMS audit — Phase C

**Starting commit:** `f14b693` (main, clean, `composer check` green — 1428 tests, Larastan 0, Pint clean).
**Branch:** `admin/audit-pass`.
**Scope:** the back-office panel — 26 resources, 17 pages, 6 widgets — audited against the brief's three
phases. There is no public site to separate this from (a Spanish CSC may not advertise), so the "admin
theming leaking into public CSS" item does not apply; the equivalent risk here is the panel theme leaking
onto the COUNTER, which is checked below.

**Deviation from the audit brief, on the owner's instruction:** the brief says *"push the branch, do not
merge"*. The owner instructed that Phase C branches are merged to main and the round continues in one
session. Recorded rather than silently followed.

---

## Summary

**Two Phase 1 findings, both about the same thing: a screen that offers an operation the domain does not
back.** The panel is otherwise in good order, and most of what the brief hunts for is already right —
reported below as verified rather than padded into findings.

Two "known gaps" this audit inherited are **stale and are corrected here**, because they have been repeated
as fact in the work order and in the security report and would otherwise be carried into Phase D.

---

## PHASE 1 — Critical

- **`MemberApplicationForm` exposes `status` as a free Select, on a reachable Edit page, to STAFF.** The
  resource registers `create`, `view` and `edit` pages; `MemberApplicationPolicy::update()` requires
  `applications.review`, which **STAFF holds** (prompt 174). The form offers `status` as every
  `ApplicationStatus` case, including `APPROVED`. → Remove `status` and `reject_reason` from the form —
  those transitions are owned by the `approve` / `reject` / `waitingList` actions on the resource, which
  call `ApproveApplication`. → **Why it matters:** it is a **second, ungated writer** to a state the
  codebase deliberately funnels through one Action, which is the same "one writer" principle enforced for
  stock and pricing.

  **Measured, not argued.** A STAFF user opened the Edit page for a submitted application whose payload
  gave the applicant's date of birth as **14 years old**, set the status to `APPROVED`, and saved:

  | | result |
  |---|---|
  | application status | `APPROVED` |
  | members created | **0** |
  | `resulting_member_id` | `null` |
  | age gate | never ran |
  | duplicate search | never ran |
  | versioned consent recorded | none |

  So the register now says a minor's application was approved, while no member exists to show for it and no
  consent record exists to justify having processed them. `ApproveApplication` enforces every one of those
  and stamps `resulting_member_id`; none of it is reachable from this path. This also goes straight through
  prompt 174's careful reasoning — `members.create` is withheld from STAFF *specifically* so they cannot
  create a member without the age gate, the duplicate search and the consent capture, and this form lets
  them mark the outcome anyway.

- **A member can be deleted — in bulk — with no route back, and "Delete" is not what it looks like.**
  `MembersTable` carries a `DeleteBulkAction` and `EditMember` a `DeleteAction`, gated on `members.edit`
  (MANAGER). `Member` uses `SoftDeletes`, so the database cascade does **not** fire and the ledger survives
  — that part is sound and is why this is not a data-loss finding. Two things follow from it that are still
  wrong:

  1. **There is no way back.** `MembersTable` has no `TrashedFilter` and `EditMember` no `RestoreAction`,
     while `Articles`, `Discounts` and `Announcements` — far less consequential records — have both. A
     deleted member simply vanishes from the panel, and `MemberPolicy::restore()` exists but nothing in the
     UI can call it. Recovering one means SQL. → Give Members the same `TrashedFilter` + `RestoreAction`
     the other soft-deleting resources already use, and drop the bulk delete (a mis-click on "select all"
     should not be able to take the member register off the screen).
  2. **It reads as erasure and is not.** After a soft delete the member's name, DNI, email, phone, photo and
     ID scan are all still in the database and on the encrypted disk. The real erasure path is
     `AnonymiseMember`, reachable **only** by creating a Data Request — nothing on the member record points
     at it. An owner told "erase this person" will use the button labelled Delete and reasonably believe
     they have complied. → Surface the erasure route from the member record itself.

  → **Why it matters:** the brief's Phase 1 names "hard-delete of consequential records with no guard" and
  "strands the owner". This is the softer version of both, and the Article 17 misreading is the part with
  legal consequences.

**Review:** _pending — fixes follow this commit._

---

## PHASE 2 — Refinement (owner UX)

- **25 of 26 resource tables have no designed empty state.** Only `MessageThreadsTable` sets an
  `emptyStateHeading` / `emptyStateDescription`; every other table falls through to Filament's stock "No
  records found". CLAUDE.md is explicit that *"empty states are INTENTIONAL (designed), never a broken/blank
  box"*. → Give the tables an owner-facing empty state saying what the screen is for and what to do first.
  → **Why it matters:** this is not a cosmetic complaint about a mature install — **on day one of a real
  club every one of these 26 tables is empty.** The first thing a new owner sees, 26 times over, is a
  framework shrug. It is the brief's own test ("could they do their weekly tasks without a manual?") failing
  at the first screen, and it is the single highest-leverage owner-UX change in the panel.

**Review:** _pending._

---

## PHASE 3 — Polish

- **`SystemHealth`'s docblock still claims a placeholder that no longer exists.** It says the page shows
  "backup/restore placeholders". Prompt 180 replaced that section with a statement of fact — *"Se gestionan
  fuera de la aplicación… Esta aplicación no las realiza ni comprueba su estado"* — and the reasoning is
  written out at length in the Blade. Only the class docblock was left behind. → Correct the docblock. →
  **Why it matters:** trivial as a defect, but this is exactly the sentence that made a stale claim
  propagate into the work order and the security report (below), and it is the kind of thing the next
  reader trusts.

**Review:** _pending._

---

## Verified and already correct — no action

Reported so a later pass does not re-derive them.

- **The stock discipline holds through the admin.** `BatchForm`'s `grams`/`units` and `ArticleForm`'s
  `stock` are `visible()` **only on create**; thereafter stock moves solely through the ledger
  (Ajuste / Merma / Reponer → `RecordStockMovement`). The brief's "editable internal field" trap is
  genuinely avoided at the two places it would hurt most.
- **All four singleton settings pages behave.** `ManageSettings`, `ManageConsentText`, `ManageEnforcement`
  and `ManageOrganisationIdentity` each declare `canAccess()`, `mount()` with `abort_unless` + `form->fill`
  of the saved values, and `save()` with `abort_unless` + `getState()` (which validates) + a success
  notification. This is the brief's most specific Filament Phase 1 item and none of them is a loose page
  that silently fails to persist.
- **No privilege escalation through the user form.** The `roles` Select is unrestricted, but
  `UserPolicy::create/update` require `staff.manage`, which **only OWNER holds** — MANAGER does not. The
  only person who can assign OWNER is already one. `delete()` additionally refuses self-deletion.
- **Every visible form field is labelled.** A sweep of all 24 resource forms found exactly one field with
  no `->label()`, and it is a `Hidden::make('supersedes_id')`, which needs none. No raw DB column names
  reach the owner.
- **Money and weight stay at the edge.** Every form field is `*_eur` or `*_g` (`price_eur`, `amount_eur`,
  `cost_per_gram_eur`, `daily_limit_g`, `grams_per_unit_g`), never a raw cents/centigram field — the casts
  do the conversion, as CLAUDE.md requires.
- **Framework furniture is already removed.** `AdminPanelProvider` sets `->widgets([])`, so Filament's
  stock account/info widgets are unregistered, and the panel primary is deliberately brand blue
  (`Color::hex('#2563eb')`), not the default amber. Both Phase 3 items were already done.
- **No orphaned fields.** `CMS-FIELD-AUDIT.md` (Phase D gate 11b) reports 59 settings keys and 0 orphans,
  and spot-checks here agree. The archetypes it names — `contact_email` and `logo_path` — are both wired.
- **Panel theme does not reach the counter.** The counter layout loads only `resources/css/app.css`; the
  Filament `theme-*.css` is panel-only. The browser harnesses inline `app-*.css` alone for exactly this
  reason (prompt 176 found the cascade corruption when they did not).

---

## Stale claims this audit corrects

Both were handed to this audit as known gaps. Both are false, and both have already propagated.

- **"No organisation settings screen exists, so a club cannot set its own logo, legal name, contact email or
  consent texts."** — **False since prompt 159.** `ManageOrganisationIdentity` edits trading name, legal
  name, CIF/NIF, registered address, contact email, contact phone and logo, plus the two per-locale consent
  declarations with the version-bump rule enforced; all writes go through `UpdateOrganisationIdentity`.
  `CMS-FIELD-AUDIT.md` independently records `contact_email` and `logo_path` as wired, "was an orphan
  pre-159". This claim appears in the Phase C work order and again in the security report's Phase 2.
- **"The health panel's backup section is a placeholder by its own docblock."** — **Half false.** The
  SECTION was fixed in prompt 180 and now states plainly that backups are the club's own infrastructure and
  that the application neither performs nor checks them. Only the **docblock** still says "placeholders",
  which is the Phase 3 item above.

---

## OWNER tasks (not defects)

- Real content for the empty states above is a product-voice decision, not something this audit should
  invent; the fix proposed is the pattern plus a first draft the owner can rewrite.
- Whether STAFF should be able to reject/waiting-list an application at all (as opposed to approve, which
  Phase 1 closes) is a club policy call, not a defect.

---

## Discussion — documented decisions this audit did NOT touch

`PERMISSION_CACHE_STORE=database`, the panic lockdown's ordinary-looking 503, `FILESYSTEM_DISK=local` being
inert, and the dispensation receipt's legal wording. None was changed.
