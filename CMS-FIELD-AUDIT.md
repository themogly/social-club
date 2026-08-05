# CMS / editable-field usage audit — Phase D gate 11b

**Ran against:** `main` @ `7769d47`; **re-run @ `d54e55b`** — still GO, 59 settings keys, 0 orphans; the
`organisations.settings` column flagged below is now DROPPED (prompt 161).
**Question this gate answers:** is any club-editable field an ORPHAN — writable in an admin form but read by
nothing (so a club sets it and it silently does nothing), or read from a hardcoded default with no way to set it?
The archetype was `contact_email` (collected at install, consumed nowhere) and `logo_path` (read by the mail
letterhead, written by nothing) — both fixed in prompt 159.

**Verdict: GO.** Every configuration key is consumed; every identity/consent/branding surface is now both editable
and read through one source. No orphans.

## Settings keys (the `Settings::DEFAULTS` config surface)

- **59 top-level keys.** Each was checked for a reader across `app/`, `resources/` and `routes/`.
- **Orphans: 0.** The single key with no `app/` reader — `forecast_options_g` — is read in the Blade layer
  (`resources/views/socio/application.blade.php:109`, the applicant's guided consumption presets), so it is
  consumed, just not from PHP. Every other key resolves through `Settings::get()` / `Settings::enforcement()` /
  `Settings::photoEnforcement()` at a real call site.
- The enforcement matrix's nested cells (`door.*`, `counter.*`, `stock.ceiling`, and the new `counter.photo`) are
  read via `Settings::enforcement()` / `photoEnforcement()`, all exercised by `ManageEnforcement` + the door/counter
  verdict engine + `CommitDispensation`.

## Identity / branding / consent surfaces (the prompt-159 targets)

| Field | Editable at | Read through | Status |
|---|---|---|---|
| `name`, `legal_name`, `tax_id`, `address`, `contact_phone` | ManageOrganisationIdentity | `OrganisationIdentity` → RAT header + statutory PDFs | ✓ wired |
| `logo_path` | ManageOrganisationIdentity (public disk, ≤1 MB/512 px) | `OrganisationIdentity::mailLogo()` (email CID) + `logoDataUri()` (PDF); name wordmark fallback | ✓ wired (was an orphan pre-159) |
| `contact_email` | ManageOrganisationIdentity | `OrganisationIdentity::replyTo()` → Reply-To on **8** member mailables (not the lockdown mail) | ✓ wired (was an orphan pre-159) |
| `consent_privacy_text` / `consent_statutes_text` (per locale) | ManageConsentText (settings.consent) | `ConsentText` on the application form + consent record | ✓ wired |
| `consent_text_version` + `consent_text_archive` | ManageConsentText (version-bump enforced) | `ConsentText::privacyForVersion/statutesForVersion` — old records resolve their exact text | ✓ wired (archive added in 159) |
| Document template bodies | DocumentTemplates resource (versioned via `SaveDocumentTemplateVersion`) | member-document generation | ✓ wired |
| Announcements / events | Filament resources (comms.manage) | member PWA (`/socio/avisos`, `/socio/eventos`) | ✓ wired |

## Retired, not orphaned

- **`organisations.settings`** (JSON column) — genuinely dead (real config always lived in the `Setting` table);
  its model cast/relation/factory write were removed in the code-style audit. Empty nullable column awaits a drop
  migration. Not a live orphan (nothing reads OR meaningfully writes it).

## Residual (owner)

None that block launch. The only open item is the `organisations.settings` column drop (a follow-up migration,
tracked in the code-style audit). Every field a club can edit today changes something a user can observe.
