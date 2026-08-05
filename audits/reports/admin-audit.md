# Admin / Back-office Audit — Phase C round

**Branch:** `admin/audit-pass` off `main` @ `270088a`.
**Scope:** the Filament panel after the churn of 143/147/148/151 (topbar ×2, location form rebuilt, sede
switcher). Audited by reading every resource's form/table/infolist, the custom pages (ManageSettings,
ManageConsentText, ManageEnforcement, SystemHealth, the report pages), the dashboard, and the panel provider.

**Headline:** the panel is well-built and there is nothing to fix inline. One structural gap — the club cannot
edit its own identity — is real, feeds Phase D directly, and is written up as a recommended branch (not fixed
here: it is a feature involving owner-provided content, per the audit rules). The backup section is an
infrastructure placeholder. Everything else is clean.

---

## PHASE 1 — Critical

- **The club cannot edit its own organisation identity — there is no Organisation editor at all.** `logo_path`,
  `legal_name`, `contact_email`, `contact_phone`, `tax_id`, `address` live on `Organisation` and are read by
  `OrganisationIdentity` (every statutory PDF, the RAT, the mail letterhead) — but nothing in `app/Filament`
  writes any of them (confirmed: no `*Organisation*` resource, no reference to `logo_path`/`legal_name` under
  `app/Filament`). Combined with 153's `consent_*` texts (which got an editor — `ManageConsentText` — but only
  for the texts, not the identity), this strands the owner: they cannot set their club's name, logo, contact
  email or correct a `legal_name` typo without `tinker`. → **Recommend ONE branch: an Organisation settings
  screen** (a singleton-style resource or a settings page) editing the identity block + a logo upload
  (`ImageColumn`/`FileUpload` with an aspect ratio so the letterhead never distorts). NOT fixed inline: the
  content is the owner's, and it is a feature, not an admin defect. This is the same finding Phase D 11a/11b
  will reach — four of their six known gaps collapse to this one screen; the report agrees, one branch.
  → **Why it matters:** the identity prints as the data controller on statutory documents; a club that cannot
  set or correct it is presenting the product's fallbacks as its own legal identity.

- **No dangerous/exposed/internal fields, no orphaned editable fields found.** Checked the forms across all
  resources (Members, Applications, Locations, Genetics, Batches, Articles, Discounts, Tiers, Convocatorias,
  Announcements, DataRequests, BreachLogs, Users, Expenses, Suppliers, Purchases): status flags, tokens, hashes,
  member numbers and system timestamps are NOT form-editable (they surface only in read-only infolists); the
  editable date pickers (`held_at`, `published_at`, `expires_at`, `requested_at`, `discovered_at`) are all
  fields the owner is meant to set. Every form field maps to a consumed attribute. The integrity harness already
  proves every settings KEY is read (59 keys). Phase D 11b can confirm the attribute-level sweep — it will find
  the same: no write-only fields.

Review: One structural gap (org identity editor), recommended as a branch, not fixed inline. No inline defects.

## PHASE 2 — Refinement (owner UX)

- Nothing to fix. Forms carry plain-English labels (through `__()`), help text on the non-obvious thresholds,
  and are grouped into Sections; lists have filters and designed empty states (every resource has a Help topic —
  guarded by `HelpGuidesTest`). The rebuilt Location form (147) uses `TimePicker`s with a required cutoff — see
  the **accessibility** audit for the keyboard/SR check on that new component.

Review:

## PHASE 3 — Polish

- Nothing to fix. Framework furniture is already gone (`->widgets([])` — the stock Filament dashboard/account
  cards are not registered; the dashboard is the custom `App\Filament\Pages\Dashboard`), and the panel primary
  is set deliberately to brand blue `#2563eb` (never Filament's default amber). Destructive actions confirm;
  admin/counter/PWA theming is cleanly separated (three stylesheets — see the 151 fix, already landed).

Review:

---

## OWNER / OPS tasks (not admin defects)

- **Backup/restore is an in-app placeholder by design.** `SystemHealth::backups()`'s own docblock: "No backup
  system is wired in-app, so these are placeholders (Settings keys that stay null until a backup pipeline writes
  them)." Backups are infrastructure (Ploi/R2 snapshots + a tested restore) — Phase D §5's job, not an admin
  code defect. The panel correctly shows "—" rather than a fake green.
- **The org identity CONTENT** (the real logo, legal name, contact email, the club's own statutes wording) is
  owner-provided and must not be fabricated — it is filled once the screen above exists.

## Discussion

- Building the Organisation settings screen is the single highest-value follow-up from this round and from Phase
  D 11a. It is deliberately left as a recommended prompt rather than built in the audit, because (a) the audit
  rules forbid inventing owner content, and (b) it is a feature (upload handling, image ratio, permission gating
  like `ManageConsentText`'s `settings.consent`), not a one-line admin fix.
