# Admin / back-office audit — pre-live, 2026-09

> **Status (2026-09-27):** Phase 1 all FIXED — items 1–2 (owner role, sede policy) in prompt 270; items 3–8 (retention, thresholds, debt rule, duplicate prices, Publicada/Imágenes, sede timezone/aforo) in prompt 271. Phase 2/3 → prompt 273.


- **Commit:** `2d98aed` (branch `audit/pre-live`, identical to `main`). **Date:** 2026-09-27.
- **This run only reports.** It changed no production code. Following `audits/admin-audit.md` Step 1 only: no branch was made, nothing was fixed or committed.
- **Method:**
  - **Reading the code.** Every file in the focus area: `RolesPermissions`, `Permissions`, `SetRolePermission` / `RestoreRoleDefaults`, `ManageSettings`, `ManageEnforcement`, `LocationForm` + Create/EditLocation, `GeneticPricesRelationManager` + `SaveGeneticPrice` / `ResolvePrice`, the tab action on `MemberResource` + `SetMemberDebtLimit` / `Wallet` / `ResolveMemberEligibility`, `TillSessions`, and `DashboardAlert`. On top of that, a sweep of every resource and page for a policy / `canAccess()`.
  - **Using the running demo** (`127.0.0.1:8123`) as owner and as manager. Screenshots are in `storage/app/screenshots/audit-admin/`: dashboard, roles in light, dark and 390, settings in light and dark, sede edit, genetic edit + the price modal, till list + view, and the manager 403 checks.
  - **Probes against the real code.** These were Livewire/PHPUnit tests kept in the scratchpad, never added to the repo. They ran against SQLite in-memory, not the shared demo database. Every claim below marked "measured" comes from one of them.
  - **Existing guards re-run:** Localization (48), FormCompleteness + UnreachableCodeGuard + HelpGuides (21). All green.
- **Scope:**
  - The Filament panel only.
  - There is no public site (the law forbids one). What the admin feeds is the counter (`/counter/*`) and the member PWA (`/socio/*`), so "breaks the site" below means "breaks the counter or the member app".
- **Focus:** the changes in prompts ~190–269: the roles page, the settings pages, genetic prices / low-stock threshold (269), member tabs (259), till oversight and dashboard alerts.

---

## PHASE 1 — Critical

- **[Roles / Personal] If the owner gives a manager "Gestionar el personal", that manager can make themselves owner.** The same grant also lets them reset the owner's password.
  - **How it happens:**
    - The roles page is deliberately gated on the OWNER *role* rather than a permission. The reason given in `RolesPermissions.php:18-20` and DECISIONS 262 is that "a manager handed 'may edit roles' could grant themselves everything".
    - 262 tested that granting a manager `staff.manage` leaves the *page* at 403. It did not test the Users resource.
    - `staff.manage` has been grantable to MANAGER since 262. The page shows a "Sensible" warning but does not block the grant.
    - The only gate on the Users resource is `staff.manage` (`UserPolicy.php:14-35`).
    - The roles Select on the user form offers every role, OWNER included (`UserForm.php:95-100`).
    - The edit page lets the operator set a new password on *any* user row, the owner's included.
  - **Measured:**
    - Owner grants MANAGER `staff.manage`. The manager edits their own row, adds OWNER, and saves: `hasRole(OWNER)` = true, and `RolesPermissions::canAccess()` = true.
    - Same grant: the manager toggles "Establecer una contraseña nueva" on the owner's row and saves: the owner's password hash changed.
  → **Fix:**
    - Only a user who already holds OWNER may add or remove the OWNER role. Do it server-side: the Select's options, plus a check in `EditUser` / `CreateUser` before saving.
    - A non-owner may not edit an owner's row at all (`UserPolicy::update/delete` → `$model->hasRole(OWNER)` requires an owner actor). That also covers password, PIN, deactivation and roles.
    - Add the denial tests.
    - The roles page's warning for `staff.manage` should say in plain words that it lets someone create and edit staff accounts.
  → **Why:** it quietly undoes the one guarantee the roles page was built around ("owner-role only, deliberately not a permission"). The owner is shown a warning badge, not a takeover.

- **[Sedes] A manager can edit every sede, including ones they are not assigned to, and can create new sedes.**
  - `LocationPolicy::viewAny/view/create/update/restore` all return `$user->can('settings.manage.location')`, with no check on which sedes the user is assigned to (`LocationPolicy.php:15-33`).
  - The code documents this permission as "lets a manager configure **their own** premises" (`Permissions.php:17-18`).
  - **Measured:** a manager assigned only to sede A:
    - opened sede B, renamed it, turned off its signature requirement, set its idle lock to 0 and saved — every change stuck;
    - created a new sede (count 2 → 3).
  - The settings they can change on another sede include the counter's security settings: idle lock, PIN attempts, check-in restriction and signature requirement.
  → **Fix:**
    - `update/view/restore` require the location to be among the actor's assigned sedes, unless they hold `locations.manage`.
    - `create` moves to `locations.manage`, because adding a premises is an owner decision, like removing one already is.
    - Scope the list query the same way.
    - Add the wrong-sede denial tests CLAUDE.md requires.
  → **Why:** CLAUDE.md requires object-ownership checks, and the counter's security settings are per sede precisely so that one sede's manager does not set another's.

- **[Ajustes ▸ Privacidad y datos] Two retention fields drive nightly jobs that cannot be undone, yet they accept 0 or negative numbers.** One of them also has help text that says the opposite of what happens.
  - `data_retention_days` (`ManageSettings.php:249`) has no `minValue`.
    - It feeds `members:purge` (daily 04:00), which anonymises every departed member with `left_at < now()->subDays(N)` (`PurgeExpiredMembers.php:23-29`).
    - 0 anonymises every member who has ever left, the following night. −30 does the same.
    - Measured: both 0 and −30 are accepted and stored.
  - `audit_retention_days` (`:250`) also has no `minValue`.
    - Its help text says the audit log "no se purga automáticamente; esta cifra se comunica (panel, RAT), **no borra nada**" (`:251`).
    - In fact `audit:redact-retention` (daily 05:45) permanently nulls the before/after payloads of every entry older than N days (`RedactExpiredAuditLogs.php:33-54`).
    - 0 wipes the detail of the entire audit trail overnight. Measured: 0 is accepted.
  - For comparison, the neighbouring `message_retention_days` and `application_retention_days` both have `minValue(1)`.
  → **Fix:**
    - Set a sensible floor on both fields (e.g. ≥ 365, or the legal minimum the club's advisor gives), plus a `maxValue`.
    - Rewrite the audit help text to say what the job actually does: *"Pasado este plazo se borra el detalle (antes/después) de cada entrada; la entrada queda."*
    - Say plainly on `data_retention_days` that members who left are anonymised automatically after it.
  → **Why:** CLAUDE.md prohibits irreversible destructive operations without explicit human action. Here one typo triggers one on a schedule, with nobody watching.

- **[Ajustes ▸ Cumplimiento] The legal thresholds the counter enforces can be saved at zero, negative, or nonsensical values.**
  - **Measured, all accepted and stored:**
    - `min_age` = 0 and = −5 (`ManageSettings.php:153`). The enforcement matrix locks the age rule to "Siempre BLOQUEAR (requisito legal)" (`ManageEnforcement.php:51`), but a minimum age of 0 empties that rule.
    - `carencia_days` = −1 (`:155`).
    - `daily_limit_g` = −3 (stored −300 cg) and = 0; `monthly_limit_g` = 0 (`:157-159`). Either one stops dispensing to every member who has no personal or tier limit.
    - `daily_limit_g` 50 with `monthly_limit_g` 10.
    - `gauge_warning_pct` 99 with `gauge_alert_pct` 10 (`:171-172`, no bounds at all).
    - `signed_url_ttl_seconds` = 0 (ID scans can no longer be opened) and = 31,536,000 (a one-year link to an Article-9 document) (`:256`).
    - `active_member_cap`, `avalador_max_sponsees` and `batch_expiry_window_days` = −1; `stock_ceiling_days` = 0.
    - `min_age` = 17.5 is silently truncated to 17.
  → **Fix:**
    - Add `integer()` plus `minValue`/`maxValue` to every numeric field. Suggested floors: `min_age` ≥ 18; `carencia` ≥ 0; limits > 0; TTL 60–3600.
    - Add cross-field rules: monthly ≥ daily; warning < alert.
    - The tier form and the member-limits action already use `minValue(0)`; the org page is the only place with none.
  → **Why:** Phase 1 in the brief is "missing validation the checkout depends on". Here the counter's legal gate is what depends on it, and "compliance blocks" is only true if the thresholds themselves are sane.

- **[Ajustes ▸ Cartera y deuda / member tabs] `wallet_debt_limit_cents` means two contradictory things.** With the default of 0, an approved tab can be used once and then the member is blocked.
  - Since 259, a club cap of 0 means "no club ceiling" for the wallet writer: `Wallet::tabHeadroomCents` only applies it `if ($clubLimit > 0)` (`Wallet.php:70-73`, DECISIONS 259: "it used to mean 'no debt'").
  - But the counter's eligibility check still reads it as the block threshold: `balance >= -wallet_debt_limit_cents` (`ResolveMemberEligibility.php:80-86`), with `counter.debt` = BLOCK by default. The debtor report reads it the same way (`DebtorReport.php:64,112`).
  - **Measured:** with debt allowed, the club cap at 0, the member approved for a €20 tab and owing €5, the counter's `debt` rule returns `{"satisfied":false,"mode":"BLOCK"}`. The next dispensation is refused with "Deuda por encima del umbral" even though €15 of the approved tab is unused.
  - The settings help text for the field (`ManageSettings.php:192-193`) still describes the pre-259 meaning ("Tope duro…").
  - The "Permitir deuda" help does not mention that each member also needs a "Cuenta del socio" approval.
  → **Fix:**
    - Decide which meaning is intended (see Discussion) and make both readers use the same rule.
    - Most likely fix: the counter `debt` rule asks `Wallet` whether the member is within their approved tab, instead of comparing against the club cap.
    - Rewrite the three help texts to describe the real layers: sede switch → member's approved tab → optional club cap (0 = none).
  → **Why:** one setting has two opposite meanings in two readers, so the tab feature the owner just approved is effectively single-use at defaults. The admin form is where the owner would go to fix it, and it describes neither meaning.

- **[Genéticas ▸ Precios] Two base prices can be added for the same variety at the same sede, and nothing decides which one the counter charges.**
  - The relation manager's create form has no uniqueness rule on (sede, tarifa) (`GeneticPricesRelationManager.php:149-161`).
  - `SaveGeneticPrice` inserts a new row whenever it is not given an existing one (`SaveGeneticPrice.php:35`).
  - The table has only a non-unique index on `(genetic_id, location_id)` (`2026_07_30_000003_…:41`).
  - `ResolvePrice` takes `->first()` with no ordering (`ResolvePrice.php:57,66`). The price charged, and frozen into the dispensation snapshot, is therefore up to the database.
  → **Fix:**
    - On create, refuse a (sede, tarifa) pair that already has a row, and point the user to Editar instead.
    - Add a unique index on `(genetic_id, location_id, tier_id)`. It needs care because `tier_id` is nullable (MySQL treats NULLs as distinct, so either a generated column or the form rule on its own).
    - Test that the second attempt is refused.
  → **Why:** this is money. The "one resolver" guarantee only holds if there is one row to resolve.

- **[Genéticas] "Publicada" and "Imágenes" are orphaned fields: the owner can edit them, and nothing reads them.**
  - **Publicada** (`GeneticForm.php:158`, also shown as a table column):
    - `Genetic::scopePublished()` (`Genetic.php:102-105`) has no caller.
    - `scopeSellableAt()` — the one "is this on the menu / at the counter" definition, shared by the member menu and the POS (`DispensaryPos.php:1975-1980`) — filters on `active` and a base price only (`Genetic.php:147-153`).
    - So an owner who switches Publicada off expecting a variety to leave the members' menu gets no change.
  - **Imágenes** (`GeneticForm.php:131`, plus the "Foto" step in the create wizard, `CreateGenetic.php:130`):
    - Uploaded to the public disk, and not rendered by the counter, the PWA menu (`socio/menu.blade.php` shows THC/CBD/strain only) or anywhere else. Article images *are* consumed (`ArticleImage`).
  → **Fix:**
    - Either wire `published` into `sellableAt` (or into the member menu only, if the counter should still see unpublished varieties) and test it, or remove the toggle and its column.
    - Either render the first image where varieties are shown, or remove the field and the wizard step.
    - Whichever way, `FormCompletenessTest` should stop accepting them as consumed.
  → **Why:** it is the brief's archetypal Phase 1 item. The owner edits a field, nothing happens, and in this case they believe they have hidden something from the member menu.

- **[Sedes] Saving a sede with no time zone throws a 500, and aforo accepts negative or empty values.**
  - `Select::make('timezone')` is not `required()`, and its placeholder can be selected (`LocationForm.php:131-137`), but the column is NOT NULL.
    - Measured: time zone emptied → `SQLSTATE[23000] NOT NULL constraint failed: locations.timezone`, i.e. a server error on save.
  - `capacity` (`:123-129`) has no `minValue` and is not required.
    - Measured: −1 is saved. On production MySQL, where the column is unsigned, strict mode would throw a 500 instead. On SQLite, `current < -1` blocks every entry.
    - Measured: an empty value is saved, and `ResolveMemberEligibility.php:109` treats a null capacity as "no aforo check". The matrix labels aforo "Siempre BLOQUEAR (aforo)", but this switches it off without saying so.
  → **Fix:**
    - Make the time zone `required()->selectablePlaceholder(false)`.
    - Make capacity `required()->integer()->minValue(1)`, with a help line if "empty = no limit" is ever meant to be allowed.
  → **Why:** a save that throws strands the owner mid-edit, and an aforo control that can be silently emptied undermines a rule the UI calls fixed.

**Review:** eight items, all verified against code and six measured. Three share a root cause: the settings forms were written before the fields drove irreversible jobs or legal gates, and never gained bounds. Two are authorization. 262 moved grants into the owner's hands without re-checking what `staff.manage` and `settings.manage.location` let a holder do. Two are drift: orphaned genetic fields, and a debt setting whose meaning changed in 259. None requires a redesign; each is a rule or a guard.

## PHASE 2 — Refinement (owner UX)

- **[Roles y permisos] A panel-only permission granted to Personal does nothing, and the page does not say so.**
  - Staff have no `panel.access` by default since 262.
  - Permissions whose only consumers are in the panel have zero references under `app/Livewire` or the counter views (grep): `reports.view`, `reports.export`, `genetics.manage`, `prices.manage`, `members.edit`, `comms.manage`, `minutes.manage`, `expenses.approve`, `purchases.manage`, `stock.merma` and `wallet.adjust`. Ticking any of these for Personal changes nothing a staff member can reach.
  - `Permissions::DEPENDENCIES` (`Permissions.php:125-130`) covers the till but not panel access.
  → **Fix:** add these to the existing dependency list, each needing `panel.access`, so the prompt-265 warning and "Conceder también" appear. It is one line per permission in the list that already exists.
  → **Why:** it is the exact failure 265 was built for, a tick that silently does nothing.

- **[Ajustes] Help text is missing on the fields a non-technical owner cannot guess, and one section holds the wrong fields.**
  - No help on:
    - `% aviso` / `% alerta` (a percentage of what?);
    - `Días "caduca pronto"`, `Días de aviso de renovación`, `Ventana de caducidad de lote`, `Máx. avalados por socio`;
    - `Tolerancia de descuadre`, `Umbral de aprobación de gasto`, `Los descuentos se acumulan`;
    - `Caducidad de URLs firmadas (seg.)` — developer vocabulary; the owner meaning is "how long a link to an ID scan stays open".
  - `Límite diario (g)` says "Máximo por socio y día" (`:158`). It is actually the *default*: a tier or member limit replaces it, and can be higher (`ResolveMemberLimits.php:52-63`).
  - `Pantalla de inicio del mostrador` and `Botón principal del mostrador` sit in "Privacidad y datos" (`:259-270`).
  - The only Guardar is at the bottom of a 4,359 px page (screenshot `owner-manage-settings-light-1440.png`).
  → **Fix:** add a help line to each field above; say "límite por defecto; una tarifa o un límite personal lo sustituye"; move the two counter fields into a "Mostrador" section; keep the save action visible (a sticky footer or a header action).
  → **Why:** this is the page the owner configures compliance on, and it is guessable today only for someone who wrote it.

- **[Sedes] About 25 fields in one flat grid with no sections, and several of them have no explanation.**
  - The form mixes identity, hours, bar, dispensary, wallet and security fields (screenshot `owner-locations_…_edit-light-1440.png`).
  - No help text on "Firma en dispensación", "Restringir TPV a socios con check-in" or "Escaneo con cámara".
  - "Monedero por sede (ring-fence)" uses English jargon.
  - `counter_idle_lock_minutes` has no `maxValue` (measured: 100,000 accepted), which disables the idle lock without saying so.
  → **Fix:**
    - Group the form into Sections: Datos / Horario / Barra / Dispensario / Monedero / Seguridad del mostrador.
    - Add the three help lines.
    - Drop "ring-fence" from the Spanish label.
    - Bound the idle lock (for example 0–60).
  → **Why:** a manager configuring a sede should be able to find the security settings and understand them.

- **[Genéticas ▸ Precios] The "Aviso de stock bajo" field does not say how it works.**
  - Since 269, the figure applies to the whole sede whichever row it is typed on: the base row wins, otherwise the highest tier-row figure (`Genetic.php:186-200`).
  - Left empty, the automatic days-of-cover rule decides.
  - The help text says only "Nivel por debajo del cual avisar de stock bajo" (`GeneticPricesRelationManager.php:187-193`), and the field is offered on every tier row.
  → **Fix:**
    - Help text: *"Para toda la sede, no por tarifa. Vacío = aviso automático por días de cobertura (Ajustes ▸ Existencias). Si lo pones en la fila base, manda esa."*
    - Consider showing the field on the base row only (see Discussion — 269 chose to *count* tier rows, not to remove the field).
  → **Why:** 269's own finding was that owners typed the figure into a tier row and saw nothing happen. The form still invites that.

- **[Panel de control] The "N variedades con stock bajo" alert leads nowhere useful.**
  - It links to the unfiltered Lotes list (`DashboardAlert.php:116`).
  - Neither Lotes nor Genéticas has a low-stock column or filter (`BatchesTable.php:74-78`, `GeneticsTable.php:75-85`), so the owner cannot see *which* varieties are low.
  - The sibling articles alert lands on a pre-filtered list (`DashboardAlert.php:129-135`).
  → **Fix:** add a "Stock bajo" filter on Genéticas driven by `StockCover::verdict()` (the rule the badge and the alert already use), and point the alert at it.
  → **Why:** an alert whose destination cannot answer "which ones?" is a dead end. 207's rule for this enum is that the far end names the subjects.

- **[Sede selects] A manager can write prices, batches and wallet movements at any sede.**
  - Every sede Select in the panel lists all of the organisation's sedes (`Location::query()`): prices at `GeneticPricesRelationManager.php:151`, batches, memberships, wallet, expenses and purchases.
  - The price relation manager shows and edits every sede's rows (`:61`).
  - DECISIONS 238 recorded per-role sede filtering as not done ("no existing form does it").
  → **Fix:** one shared option source (e.g. `Location::assignableTo($user)`) used by every sede Select, plus a server check in the Actions. It pairs with the LocationPolicy fix above.
  → **Why:** it is the wrong-sede write CLAUDE.md asks to deny. It sits in Phase 2 only because 238 consciously deferred it (see Discussion).

- **[Ajustes] The org-wide `low_stock_threshold_cg` is read but cannot be edited.**
  - `Genetic.php:204` reads it.
  - No form writes it.
  - The documented exclusion in `DebtAndLocationSettingsTest.php:203-205` justifies this with "the operative low-stock threshold is set per-article on the Article resource". That was never true for varieties, whose threshold lives on GeneticPrice.
  → **Fix:** either expose it under Existencias ("Umbral fijo para todas las variedades (g), vacío = automático") or delete the setting, now that days-of-cover is the rule.
  → **Why:** a setting read and set by nobody is dead configuration, and its justification is stale.

**Review:** the Phase 2 items follow the pattern the brief predicts. The mechanics are sound, but the owner cannot tell what a control does (roles dependencies, retention and debt help text, the price threshold) or where its answer lives (the low-stock alert).

## PHASE 3 — Polish

- **[Roles y permisos, 390 px] The page does not work at phone width.**
  - The "Permiso" and "Propietario" headers overlap.
  - Permission labels wrap one word per line.
  - The top bar's avatar is clipped (`owner-roles-top-light-390.png`).
  - The "por defecto" note is 10 px text (`roles-permissions.blade.php:69`).
  - The checkboxes are native browser controls; CLAUDE.md wants branded controls on desktop.
  → **Fix:** below `sm`, show a stacked card per permission, raise the note to ≥ 12 px, and use the panel's checkbox component.
  → **Why:** CLAUDE.md's screenshot matrix includes 390.

- **[Vocabulary] The manager role has three different names.**
  - The role label is "Gerente" (`Role.php:17`).
  - The sede toggle and counter copy say "responsables" (`LocationForm.php:244`).
  - The roles blade comment says "Encargado".
  - The nav item "Artículos (bar)" sits under the "Barra y tienda" group, which hides the shop.
  → **Fix:** pick one term per role across the panel and counter and add it to the glossary; rename the nav item "Artículos".
  → **Why:** the roles page is where the owner maps words to people.

- **[Genéticas ▸ Precios] The modal's submit button says "Enviar".** → Change it to "Guardar precio". → **Why:** it should say what the button does.

- **[Tests] The settings-coverage exclusion list is out of date.** `DebtAndLocationSettingsTest.php:169` lists `data_retention_days`, `audit_retention_days`, `signed_url_ttl_seconds` and `monthly_window` as "deliberately NOT on the org settings form", but all four are on it. → Remove them from the exclusions. → **Why:** a gate whose allowlist is wrong cannot catch the next orphan.

- **[Demo data] The demo sedes store `timezone = UTC`.** The Select offers only Madrid and Canarias, so the demo edit page shows "Seleccione una opción". Saving it after Phase 1's `required()` fix would force a choice and move the demo's day boundary. → Seed a real time zone, or add UTC as an option for the demo only. → **Why:** otherwise the fix trips over its own seed. (Real installs default to Europe/Madrid — `create_organisation_and_scope_tables.php:31`.)

**Review:** cosmetic and consistency items. None of them blocks go-live.

---

## Verified OK

- **The roles page is gated correctly.**
  - `canAccess()` requires the OWNER role; the manager gets 403 (screenshot).
  - `toggle`, `grantDependency` and `restoreDefaults` each re-check, and refuse the owner column.
  - `SetRolePermission` rejects unknown permissions (`:44`).
  - Sensitive grants confirm; restore confirms; every change is audited `role.permission.changed` / `role.permissions.restored`.
  - Overrides survive deploys (they are counted in `for()`).
- **The owner-only sede toggle holds.** `managers_can_approve_debt` is disabled for non-owners *and* ignored server-side on save (`EditLocation.php:65-71`). The manager's view of the member record shows no "Cuenta del socio" while the toggle is off.
- **The tab approval flow is sound.** "Cuenta del socio" is one writer (`SetMemberDebtLimit`): policy-gated, reason required, negative refused, audited. The column is not mass-assignable, and the modal shows the total owed across sedes.
- **Every resource and page is gated.**
  - All 26 resources have a matching policy. Missing abilities deny, so till sessions have no create/edit/delete.
  - All custom pages have `canAccess()` + `mount()` `abort_unless`.
  - Manager: 403 on roles, settings, enforcement, users and health (screenshots).
- **The singleton settings pages behave.** `ManageSettings` and `ManageEnforcement` load on mount, validate via `getState()`, persist, audit, and notify. Enforcement forces the locked cells server-side.
- **Money enters in euros and is stored in cents** everywhere in scope: prices, tab limit, till and expense thresholds (`round_half_up` / `Money::fromEuros`). The eighth-price guard holds.
- **Till oversight is read-only.** No header actions, figures derived from `ZReport`, petty cash itemised (265), the variance filter present.
- **Dashboard alerts are exhaustive.** `DashboardAlert` has no `default` in any match. The articles alert lands pre-filtered, and staff see plain text rather than a 403.
- **Translations and vocabulary are clean.**
  - The ES/EN parity suite is green (48 tests).
  - No `cliente` / `beneficio` / cannabis `venta`. "venta/ticket" appears only on bar/POS copy, as CLAUDE.md allows.
  - The English for the new labels is non-commercial ("Member tab", "Low stock alert").
- **The theme is right.** The panel primary is brand blue, and dark mode renders the roles and settings pages cleanly.
- **The drift guards pass:** `UnreachableCodeGuardTest`, `FormCompletenessTest` and `HelpGuidesTest` are green. Note, though, that `FormCompletenessTest` does not catch the Phase 1 orphans: it checks that fields are on a form, not that anything reads them.

## OWNER tasks (not defects — real values / decisions only the club can give)

- **Retention periods:** `data_retention_days`, `audit_retention_days`, messages, applications. Set them on legal advice, before the Phase 1 floors are chosen.
- **Minimum age:** 18 or 21 (regional practice).
- **Carencia days and daily/monthly gram defaults:** the club's statutes.
- **Each real sede:** time zone, business-day cutoff, opening hours, aforo from the licence, terminals. The demo values are UTC / 00:00.
- **Tabs:** whether managers may approve them at each sede (`managers_can_approve_debt`), and whether a club-wide cap exists.
- **Roles page defaults:** review them, in particular staff access to ID scans (the Article-9 widening from 262) and staff panel access.
- **Variety images:** whether they should exist at all. It decides which way the Phase 1 orphan is resolved.
- **Forecast presets** (`forecast_options_g`, currently 30/50/60/90 g): the real figures, until an editor exists.

## Discussion (documented decisions this report does not overrule)

- **Which meaning of the debt setting is intended?** DECISIONS 259 made a club cap of 0 mean "no ceiling", and says the member's approved tab is now required in every case. That reads as though an approved member who owes should still be served within their tab. The counter's `debt` rule still blocks any negative balance at a cap of 0. The finding stands either way — two readers disagree — but the owner should say which behaviour is right before it is fixed.
- **Sede filtering was deferred on purpose.** Per-role filtering on sede Selects was explicitly left out by 238 ("not this branch's subject"). It is reported in Phase 2, not Phase 1, for that reason, and it pairs with the LocationPolicy finding, which no decision covers.
- **Forecast presets are not editable.** `forecast_options_g` was documented as deferred (DECISIONS ~1579: "needs a tags/repeater — later"). CLAUDE.md lists forecast options among the thresholds that must be configurable. Worth scheduling; not re-litigated here.
- **Staff can see every Z-report.** `TillSessionPolicy::viewAny = till.open || till.close` was consciously left in (DECISIONS ~4371). Since 262 staff have no panel by default, so the exposure is now opt-in via the roles page.
- **The dispensing register is gated on a reports permission.** The registro de dispensación (`RegistroDispensacion`, per-socio-number consumption lines) is gated on `reports.view`, while the libro de socios uses `register.view`. The class docblock does this deliberately. Now that the owner can grant `reports.view` to staff from the roles page, the permission label ("Ver informes de su sede") could say it includes the statutory dispensing register.
- **Role overrides are not keyed to an organisation.** `role_permission_overrides` has no `organisation_id` (migration `2026_09_26_100000`, unique on role+permission). That matches Spatie roles being global today. CLAUDE.md asks domain data to be "keyed for future multi-organisation SaaS", so this table will need the key when that day comes. Recorded, not a defect for a single-org go-live.
