# Pre-live audit round — summary (2026-09-27)

Run at `2d98aed` (main after prompt 269). Report-only: six parallel passes, nothing fixed. Each item below is
de-duplicated across the reports and was verified against the code; the lead findings were re-checked by hand
(file:line quoted). Detail, evidence and suggested fixes live in the individual reports:

| Report | Phase 1 | Phase 2 | Phase 3 |
|---|---|---|---|
| [security](2026-09-pre-live-security.md) | 5 | 1 | 2 |
| [admin](2026-09-pre-live-admin.md) | 8 | 7 | 5 |
| [code-style](2026-09-pre-live-code-style.md) | 4 | 7 | 7 |
| [design](2026-09-pre-live-design.md) | 5 | 10 | 4 |
| [accessibility](2026-09-pre-live-accessibility.md) | 6 | 9 | 5 |
| [timezone](2026-09-pre-live-timezone.md) | investigation — real bug + a wrong harness check | | |

Clean: `composer audit`, `npm audit`, Pint, Larastan L6, full suite, and 33/34 integrity-harness checks (the one
failure is the timezone item below). SEO was not run — there is no public surface by law; the security pass
re-checked noindex/robots.

## Blockers — fix before go-live

### A. Counter sign-in and permissions (one branch)
1. Till handover switches the operator but not the login — `TillSession.php:315` calls `CounterOperator::set`, never `SignInOperator`; staff inherit the owner's panel.
2. Any correct PIN clears the device lockout — `UnlockOperator.php:73`; a staff member can guess the owner's PIN indefinitely by interleaving their own. (Touches prompt 120's decision.)
3. PINs are not unique and the first match wins — `UserForm.php:81-93`, `UnlockOperator.php:69-72`.
4. `staff.manage` lets a manager make themselves owner / reset the owner's password — `UserForm.php:95`, `UserPolicy.php:14-35`.
5. After an idle lock, a replayed panel Livewire request still runs as the last PIN person — `RedirectCounterOnlyAccounts.php:36` guards page loads only.
6. `avaladorFeedback()` is public with no operator check and returns a full member row (DNI, DOB, address, `is_therapeutic`) — `SignsUpMembers.php:758`.
7. A manager can edit and create sedes they are not assigned to — `LocationPolicy.php:15-33`.

### B. Compliance and money correctness
8. **Monthly gram cap uses the wrong month boundary** for a real (Europe/Madrid, 06:00 cut-off) sede — `ResolveMemberLimits.php:94` starts the month at local midnight, not the cut-off; the counter hub (`CounterHome.php:249`) and dashboard charts (`DashboardChart.php:72`) use the UTC calendar day; receipts and the Registro print UTC times. Demo sedes are UTC/00:00, which hid all of it. The harness check (`integrity-harness.php:314-321`) asserts the wrong contract and needs correcting too.
9. Till cash fields (blind count, float, movements, petty cash, handover, fees) read `1.250` as €1.25 — `TillSession.php:950`, `CollectsMembershipFees.php:368` use lenient `Money::fromEuros`; only the tender field got 268's strict parser.
10. "Añadir a la cuenta" ignores a price override — `DispensaryPos.php:1734` vs `HandlesTender.php:112`.
11. `wallet_debt_limit_cents = 0` means "no cap" in `Wallet.php:70` and "no debt allowed" in `ResolveMemberEligibility.php:84` — an approved tab works once, then the member is blocked.
12. Two base prices can exist for one variety + sede; `ResolvePrice.php:66` picks one with an unordered `first()`.
13. Legal thresholds and retention periods save at 0 / negative — `ManageSettings.php:153-172,249-256`; retention drives nightly irreversible anonymisation.
14. Emptying a sede's timezone 500s; an empty aforo silently disables the capacity check — `LocationForm.php:123-137`.
15. Add-strain confirmation shows 250 g as "25 g" (display only; stored value correct) — `CreateGenetic.php:206`.

### C. Counter usability and accessibility
16. Counter top bar: club name/title truncated to "C." / "D.." at ≥1280 wide; horizontal scroll and the panic button off-screen at 390.
17. Dark mode: white text on amber/red buttons fails AA (3.19:1 / 2.77:1) — `<x-button>` warning/danger + 7 hand-rolled buttons.
18. Dark mode: tapped tiles / weight presets keep the light hover background (1.9–2.4:1).
19. PIN pad: Enter on a focused key submits the partial PIN and burns a lockout attempt — `counter-surface.blade.php:74`.
20. Tender field has only a placeholder for a label; "Cambio" green 4.43:1 on dark; Roles y permisos "por defecto" 2.62:1.
21. Admin dashboard tables cut euro figures at 1440/1280 and collapse at 1024; an empty card shows for a blocked member on the POS.
22. The variety "Publicada" toggle and "Imágenes" upload are read by nothing — `GeneticForm.php:131,158`.

## Decisions needed from the owner
- **PIN uniqueness** (A3): enforce unique PINs per sede? Existing duplicates would have to be reset.
- **Lockout** (A2): should a correct PIN still clear the device's failure count (prompt 120), or should the lockout only expire with time?
- **Debt limit at 0** (B11): "no tab at all" or "no club-wide cap"?
- **Publicada / Imágenes** (C22): wire them up or remove them?
- Legal inputs: retention periods and minimum age (on legal advice), real timezone / cut-off / aforo per sede.

## Owner / ops (from all reports)
`APP_ENV=production` (`.env.example` ships `local`), tighten `TRUSTED_PROXIES` from `*`, private + encrypted documents bucket,
Sentry DSN, SSL/HSTS, Horizon and the scheduler cron supervised, kiosk mode on tablets, everyone sets a new distinct PIN after
fix A, never run the demo/dev seeders in production, MySQL CI green on the release commit.

## Not covered
The member PWA's signed-in pages were reviewed from code only (minting a member login in the shared DB was declined). axe was
unavailable (`node_modules/@axe-core` empty); accessibility used an in-page script plus screenshots. The till's closed state was
not screenshotted (shared till).
