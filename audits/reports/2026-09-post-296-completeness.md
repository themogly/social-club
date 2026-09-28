# Completeness check: post-296 round (2026-09)

Report only; no tracked file was changed. Range `2d98aed..1dcc453` (prompts 270–296).

# Completeness audit — prompts 270–296 (`2d98aed..1dcc453`, branch `audit/post-296`)

Report-only. No tracked file was changed. The full suite was not run.

## Verdict

The range is in good shape. Every Action, page, report, widget, alert, setting, permission, mailable and command that
DECISIONS promises for 270–296 has a real production entry point. Nothing is built but unreachable. There are no code
leftover markers, both dependency audits are clean and the lang files are in parity. What is left: one seed defect that
DECISIONS already knew about, one missing device checklist, a few pieces of dead code made orphans by 292, one latent
flash bug that 279 flagged and nothing followed up, and a list of owner tasks.

## 1. Leftover markers (TODO / FIXME / XXX / placeholder / stub / dd / dump / ray / console.log)

- `TODO`, `FIXME`, `XXX`, `coming soon`, `not implemented`, `stub`, `dd(`, `dump(`, `ray(`, `console.log`: **0 hits**
  in `app/`, `resources/`, `routes/`, `config/` or `public/*.js`.
- `placeholder`: every hit is legitimate. They are HTML/Filament placeholder attributes, Filament's `Placeholder`
  component, CSS for `.fi-ta-placeholder`, the neutral photo placeholder on the socio menu (a designed fallback),
  Monolog's `replace_placeholders`, and docblocks that describe these.

## 2. Prompt by prompt: promised entry point vs code

| Prompt | Promised entry point | Found |
|---|---|---|
| 270 | PIN hardening, `EnsureRoleChangeIsAllowed`, `CounterLockConfinement`, `EndInactiveSessions`, `/csp-report` | All wired (UserPolicy, User pages, AppServiceProvider, bootstrap/app.php, routes/web.php:31) |
| 271/275 | business-day windows, `Money::parseTyped`, debt rule | Code paths present; not re-verified beyond callers |
| 277 | `TransferBatch` → "Trasladar" / "Asignar a sede" | `BatchesTable`; org ceiling alert `Dashboard.php:278` |
| 278 | `SetBatchPrice` → "Precio" action; `BatchPriceBackfill` | `BatchesTable`; migration `2026_09_28_100000` |
| 280 | `ProductType::HASH`; 276's `ProductTypeChoice` removed | No references left |
| 281 | ClockIn / ClockOut / Annul; counter prompts; Registro de jornada page | Top-bar events `counter-clock-out` / `counter-my-hours` → `#[On]` handlers; till-close offer `TillSession.php:930`; panel page actions |
| 282 | `RenameBatchLote` | `EditBatch` |
| 283 | store form hides hours/accent | `LocationForm` plus the model `saving` guard |
| 284 | `PanelReturnUrl` | `LocationSwitcher` |
| 285 | Horas del personal report, dashboard widgets, two alerts | Page discovered (Informes); `StaffHoursChart` on dashboard.blade; the two report charts in the report partial; alerts `Dashboard.php:238` |
| 286 | `csc:pin-upgrade-status` | Auto-discovered command; documented in SETUP.md:99 |
| 287 | `SendApplicationInvite` | Panel Invitar/Reenviar, counter invite and counter Reenviar (`alta-modal.blade.php:361`) |
| 288 | every mailable sent | Each of the 10 mailables has a production sender (table below); `csc:mail-test` exists |
| 289 | Registered counter terminals | Top-bar "Este dispositivo" → `counter-terminal` event → `beginRegisterTerminal`; panel Sistema → Mostradores registrados with Revocar; `terminals.manage` in Permissions; Help topic and manual runbook step present |
| 290 | `/counter.webmanifest`, install button | Route behind `AuthenticateCounter`; linked only from the counter layout; icons exist; the button handles a `beforeinstallprompt` that fires before Alpine starts (`!!window.cscInstallPrompt`) |
| 291 | Descuentos y ajustes report, `DISCOUNTS_ABOVE_THRESHOLD`, setting | Page discovered; alert `Dashboard.php:263`; `discount_alert_threshold_pct` on Ajustes and read by `DiscountsReport` |
| 292 | per-sede calculator setting | `LocationForm::SETTING_TOGGLES` persists it; `DispensaryPos::calculatorEnabled()` reads it |
| 293 | islands, client-side filters | `RendersIslandsOnChange` used by both POS views; none of the removed server methods is referenced anywhere |
| 294 | article `location_id` field | `ArticleForm` |
| 295 | camera/file on every upload; below-cost warning; *Productos*; back to list | All 11 panel `FileUpload`s are wrapped by `CameraOrFile::field` except the CSV import and the logo, which are file-only by design. Counter and applicant use `x-counter.file-field`; `photo-buttons.js` is loaded by `app.js` and `socio.js`. `WarnsBelowCost` is on CreateGenetic, CreateBatch and the batch Precio action |
| 296 | limits switch | Toggle and modals `ManageSettings.php:161/347/367` → `SetConsumptionLimits`; `limitsEnabled()` honoured by `Settings::enforcement`, `ResolveMemberLimits::shown`, PWA, infolist, consumption report, dashboard over-limit alert and histogram, Socios over-limit list, enforcement page |

Mail senders (288): Invite → `SendApplicationInvite`; Approved → `ApproveApplication`; Rejected → panel Rechazar
closure; Card → `SendMemberCard`; LoginLink → `IssueMemberLoginLink`; Receipt → `DispensaryPos:1342`; Reminder →
`memberships:sweep`; Convocatoria → `IssueConvocatoria`; Lockdown → `InitiateLockdown`. Every mailable is also in
`DevMail`.

## 3. Scheduler and commands

- No prompt in 270–296 promises a scheduled job, and `routes/console.php` is unchanged in the range. That is consistent.
- The two new commands are manual, which is correct:
  - `csc:pin-upgrade-status` is documented in SETUP.md:99.
  - `csc:mail-test` is **not documented for a human**. See item P3.

## 4. Lang parity

- `lang/es.json` and `lang/en.json` have 3061 keys each, the same key set. `es` maps every key to itself and `en` has
  no empty values.
- A rough scan of every `__('…')`, `__("…")`, `trans` and `@lang` literal in `app/`, `resources/views`, `routes/` and
  `config/` found **0 keys missing from es.json**, out of about 4,400 call sites.
- The added Blade in the range contains no hardcoded visible copy.
- Exception, polish: the two new console commands print hardcoded Spanish (`PinUpgradeStatus.php:26-34`). This is
  operator-facing CLI output, so it is not a parity breach.

## 5. Dependency audit

- `composer audit`: **"No security vulnerability advisories found."** No abandoned packages were reported.
- `npm audit --omit=dev`: **"found 0 vulnerabilities"**.

## 6. Findings

### Real defects

**D1. The demo seeder hand-writes member numbers without advancing the sequence.**
- Where: `database/seeders/DemoDataSeeder.php:479` (`'member_no' => sprintf('M-%05d', $number)`) in `makeMember()`.
- What's wrong: the real writer is `MemberNumber::next()` (`app/Support/MemberEnrolment.php:22`). The seeder never
  calls `MemberNumber::advanceAtLeast()`. So on a demo-seeded DB the first real approval or alta collides on the
  unique `(organisation_id, member_no)`.
- 288 found this and said "worth its own prompt", but it is still open. It is exactly the "fixtures bypass the writer"
  drift CLAUDE.md forbids.
- Fix: call `MemberNumber::advanceAtLeast($orgId, $number)` after seeding, or allocate through `MemberNumber::next()`.
  Add a test that approves an application after `DemoDataSeeder`.
- Severity: real defect (demo/dev only; production has no demo data).

**D2. The device checklists for 289 and 290 do not exist.**
- Where: `verification/real-device-checks.md`.
- What's wrong: DECISIONS 289 ("see `verification/real-device-checks.md`", the overnight tablet check) and 290 (six
  checks: install, no bar in both orientations, scope keeps login/lockdown/panel in-app, registered tablet reopens on
  the PIN pad, install offered without a service worker, member app unaffected) both point to this file. It only has
  sections for 293 and 295. It was created in 293, after 289 and 290 were written, and never backfilled.
- These are exactly the checks that DECISIONS says cannot be proven headless ("false-green §9"), so right now nothing
  tracks them.
- Fix: add `## 289` and `## 290` sections with the checks and an `Answer: (pending)` line.
- Severity: real defect (process/verification gap); owner and Shane then run them.

**D3. The "same confirmation twice shows nothing the second time" bug is still live on four counter screens.**
- Where:
  - `resources/views/livewire/counter/partials/counter-flash.blade.php:37`: the key is `md5($flashMessage)`, plus a
    nonce only when one is passed.
  - Only `till-session.blade.php` passes `nonce`. `bar-pos`, `dispensary-pos`, `membership-counter` and
    `check-in-screen` include the partial without one.
- What's wrong: 279 proved in the browser that a second identical flash morphs onto the first one's hidden element and
  never shows. It called this "the double-record trap" and deferred it as "worth a follow-up". No later prompt picked
  it up.
- Fix: give each of the four components the same locked `$flashSeq` bumped in `flash()`, and pass it as `nonce`.
- Severity: real defect (low; UX that can invite a double tap).

### Polish / dead code

**P1. `toggleCalculator()` and `applyWeightPreset()` are orphaned by 292.**
- Where: `app/Livewire/Counter/DispensaryPos.php:460` and `:1806`.
- What's wrong: the keypad, presets and the Gramos/€ switch are client-side now. At `2d98aed` the view called both
  methods; now no view or JS does, and only tests call them (`DispensaryCalculatorTest`, `PosQuickEntryTest`).
  DECISIONS justifies keeping `applyWeightPreset` because "tests use it", which is not a production caller (CLAUDE.md:
  remove dead code). Both are public Livewire methods any client can call. They are harmless (the calculator one
  re-checks the sede setting), but they are surface area.
- Fix: delete both. Point the tests at `addLine($value, $mode)`, which is the real path.
- Severity: polish.

**P2. The Productos table still shows a "Categoría" column that can never be filled.**
- Where: `app/Filament/Resources/Articles/Tables/ArticlesTable.php:31`.
- What's wrong: 295 removed Categoría from the form because nothing can create a product category. The column stays,
  so it is always blank on a live club.
- The demo seeder still creates a bar category (`DemoDataSeeder.php:357`), so the demo shows category chips and tiles
  that a real club can never get. That is a seed-vs-real divergence.
- Fix: drop the column, or hide it when no `ARTICLE` category exists (the `GeneticForm` rule from 247). Consider
  dropping the demo bar category.
- Severity: polish.

**P3. `csc:mail-test` is only mentioned in code comments.**
- Where: `SystemHealth.php:56` (docblock) and `system-health.blade.php:180` (a Blade comment).
- What's wrong: nothing a person reads mentions it. SETUP.md's mail section (lines 101–104) does not mention it, and
  the Salud del sistema Correo row does not either.
- Fix: add one line to SETUP.md's mail section, and optionally a visible hint on the Correo row when it is red or amber.
- Severity: polish.

**P4. Application rejection is inline in the resource and unaudited.**
- Where: `app/Filament/Resources/MemberApplications/MemberApplicationResource.php:150-168`.
- What's wrong: this predates the range but 288 edited it. Rejection is a status write plus a mail inside a Filament
  closure, with no audit entry. Approval, by contrast, is an Action (`ApproveApplication`).
- Fix: extract `RejectApplication` (status, audit `application.rejected`, mail) and call it from the closure.
- Severity: polish (architecture and audit consistency).

**P5. `public/counter-icons/favicon-32.png` is unused.**
- It was added in 290 and nothing references it.
- Fix: link it as the counter layout's icon, or delete it.
- Severity: polish.

**P6. Orphan docblocks predate the range (seen in passing).**
- `DispensaryPos.php:1795-1800` ("The genetics sellable at this sede…") sits on top of `applyWeightPreset`'s own
  docblock.
- `CounterHome.php` has two stacked docblocks above `alertLabel()`.
- Also test-only and predating the range: `CounterHome::canSeeTakings()`, `DispensaryPos::quickEntryPresets()`, and
  `IdentifiesOperator::closeOperatorPanel()`, which has no caller at all, not even a test.
- Severity: polish.

**P7. Inert per-sede setting rows are written for a store (known, 283).**
- Creating an Almacén still writes the hidden counter-section settings as location Setting rows. Nothing reads them.
  DECISIONS acknowledges this.
- Severity: polish.

### Owner tasks (from DECISIONS, still open; nothing to build unless the owner decides)

1. Flip `CSP_ENFORCE` after the `csp.violation` log is clean (270).
2. Have everyone set a fresh, distinct PIN (270/286). Run `csc:pin-upgrade-status` after the first busy evening.
3. Register each club tablet with "Este dispositivo" (289). Install the counter app on each tablet (290).
4. Replace the placeholder counter icons with real artwork (290).
5. Run the real-device checks for 293 and 295 (pending in the file), and for 289 and 290 once D2 adds them.
6. In the panel, resend the invitations that were created at the counter before 287 (Solicitudes → Reenviar).
7. Confirm the OVERNIGHT-DEFAULTs:
   - discount alert at 10 % / 7 days / €50 (291);
   - whether the € calculator back-solves from the pre-discount rate (292);
   - whether the jornada regime covers volunteers, and its RAT basis (281);
   - batch names hidden from the PWA (282);
   - no articles at the store (294);
   - the association-wide ceiling's legal basis (277, for the gestor).
8. Decisions still to make:
   - retiring `genetic_prices` fully (278);
   - taking a real lote number at intake (282);
   - renaming "Sedes" to "Ubicaciones" (283);
   - stored Spanish reason strings such as `'Compra en barra'` (`CommitOrder.php:114`) rendering raw in the English
     panel (273);
   - `role_permission_overrides` has no `organisation_id` (273);
   - whether to make `Money::fromEuros` strict (273).
9. Tell the gestor if consumption limits are switched off (296).
10. Known gaps, for the record:
    - Android Back after the server closes the sign-up modal (272/275);
    - the operator chip's same-page staleness (281);
    - "Cobrar visita" latency not profiled (286);
    - event and convocatoria times typed before 271 display shifted (271/275, a data check).
