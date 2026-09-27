# Pre-live audit: which day is "today"? (Madrid, 06:00 cutoff)

> **Status (2026-09-27):** FIXED in prompt 271 — BusinessDay owns every window (the monthly cap included), naive `Period` callers resolve the sede, half-open queries, display times in the sede's timezone, harness check corrected, a Madrid/06:00 demo sede. See DECISIONS.md, prompt 271.


Date: 2026-09-27 · Branch `audit/pre-live` @ 2d98aed · Report only, no code changed.

## Verdict: both a stale check and a real bug

- **The harness check is wrong (stale).** `audits/integrity-harness.php:314-321` checks that
  `BusinessDay::window($loc)` starts at *calendar midnight in `app.timezone`*. That can only pass when a sede's
  cutoff is `00:00` and its timezone equals `app.timezone` (UTC). So it tests how a sede is configured, not what
  the code does. Prompt 114 added it (DECISIONS.md:4055-4067). Prompt 105 then turned it green by switching the
  demo seed to UTC with a `00:00` cutoff (DECISIONS.md:4115-4119, `DemoDataSeeder.php:116-128`), not by changing
  what the check compares. The contract prompt 105 actually shipped is **report day == business day**
  (`Period::businessWindow`, `tests/Feature/Reports/BusinessDayPeriodTest.php:29`). Under that contract a
  Madrid/06:00 sede *should* be 4 h (summer) or 5 h (winter) away from UTC midnight. The Echevarría de Salgado
  failure is the check being wrong.
- **The bug underneath is real.** Prompt 105 only moved the *location-aware* entry point
  (`Period::fromKey($key, $location)`) onto the business day. Nine call sites still use the naive UTC calendar
  constructors `Period::today()` / `thisMonth()`. On top of that, the **monthly cap** uses a third definition of
  a day: local midnight, not the cutoff. For a production Madrid sede, the same dispensation lands in different
  days or months depending on which screen you look at. The demo never shows this because every demo sede is
  UTC with a `00:00` cutoff.

## Evidence (probe run, Europe/Madrid sede, 06:00 cutoff, app.timezone UTC)

**Day scenario.** Dispensations at 26 Sep 23:30, 27 Sep 01:30 and 27 Sep 05:00 Madrid time, all one night
(business day 26 Sep). Clock set to 27 Sep 05:30 Madrid.

| Surface | How it computes "today" | Window (Madrid time) | Result |
|---|---|---|---|
| Daily gram cap: `ResolveMemberLimits.php:33` (called from `CommitDispensation.php:112`) | `BusinessDay::window` | 26 06:00 → 27 06:00 | 300 cg used (all 3) ✔ |
| Admin dashboard cards: `Filament/Pages/Dashboard.php:121` | `Period::fromKey(key, loc)` → business day | 26 06:00 → 27 06:00 | 3 tx / €30 / 3 g ✔ |
| Reports and Registro de dispensación: `ReportPage.php:177`, `RegistroDispensacion.php:148` | `Period::fromKey(key, loc)` | 26 06:00 → 27 06:00 | 3 ✔ |
| Counter hub "check-ins today": `Dashboard.php:124` | `BusinessDay::window` | 26 06:00 → 27 06:00 | ✔ |
| **Counter hub transactions and takings**: `CounterHome.php:249` → `Dashboard::for($user, Period::today())` | naive UTC midnight | **27 02:00 → 28 02:00** | **1 tx / €10 / 1 g** ✘ |
| **Counter hub "On shift"**: `Dashboard.php:144-145` | `Period::today()` | 27 02:00 → 28 02:00 | ✘ |
| **Admin dashboard charts**: `Filament/Widgets/DashboardChart.php:72` | `Period::fromKey($key)` with **no location** | 27 02:00 → 28 02:00 | ✘ (cards and charts on the same page disagree) |
| Dashboard trailing series and days-of-inventory: `DashboardCharts.php:525-526`, `Dashboard.php:215` | UTC midnight | shifted | ✘ (minor) |
| Hourly chart axis: `DashboardCharts.php` `periodBuckets` labels | `format('H:00')` on UTC instants | "04:00" shown for 06:00 local | ✘ (label only) |
| Till / Z-report: `TillSummary.php:72-104`, `ZReport.php` | keyed on `till_session_id`, not on a date | n/a | ✔ cash reconciliation not affected |

At 01:30 Madrid the counter hub's "today" total has already reset to zero: the UTC day rolled over at 02:00
local in summer (01:00 in winter). This is the exact failure prompt 105 set out to remove. The daily cap is
correct.

**Month scenario.** Dispensations of 100 cg each at 30 Sep 20:00, 1 Oct 01:30 and 1 Oct 05:00 Madrid. All three
fall in business day 30 Sep, so all three belong to business-month September.

| Surface | Month window (Madrid time) | Result |
|---|---|---|
| **Monthly gram cap**: `ResolveMemberLimits.php:77-96` | `startOfMonth()` of the business date, at **local midnight** (00:00), not the 06:00 cutoff | at 1 Oct 05:30: **100 cg** (September window; the two post-midnight rows are invisible). At 1 Oct 12:00: **200 cg** counted against **October** |
| Reports "month": `Period::fromKey('month', loc)` | 1 Oct 06:00 → 1 Nov 06:00 | October = **0**, September = 300 |
| **Naive month**: `Period::thisMonth()` in `Dashboard.php:178` (new members), `Dashboard.php:305` (members-over-limit alert), `DashboardCharts.php:282` (limit distribution), `ConsumptionReport.php:207` (over-limit table), `Filament/Pages/Dashboard.php:344` ("Este mes" sub-line) | 1 Oct 02:00 → 1 Nov 01:00 | October = **100** |

So one member's October usage reads three different ways: the cap says 200, the business-month report says 0,
and the dashboard alert and the over-limit table say 100. With a monthly limit of 200 cg, the cap blocks the
member while `membersOverLimit()` returns 0 (verified in the probe).

**Monthly-cap gap (compliance).** Between local midnight and the cutoff on the 1st of each month, the cap still
checks the *previous* month's window, which ends at local midnight. It therefore ignores grams dispensed
earlier in that same gap. A member can keep drawing, limited only by the daily cap, and those grams are later
charged to the new month. The same midnight-versus-cutoff mismatch affects `rolling30` (`ResolveMemberLimits.php:87-88`).

**Lesser findings**
- `ResolveMemberLimits::usedBetween` (`ResolveMemberLimits.php:104`) and `RegistroDispensacion.php:156` still
  use inclusive `whereBetween`. A row stamped exactly on a boundary counts in both adjacent periods. For the cap
  this errs toward blocking; for the Registro it can print the row twice.
- `Period::custom()` (`Period.php:58`, used by `ReportPage.php:169` and the dashboard) is a UTC calendar span,
  not a business-day span. A custom range of "27/09–27/09" is a different window from the "today" key.
- Several date checks use UTC calendar dates: sanction start/end at the counter (`DispensaryPos.php:2266`,
  `CheckInScreen.php:370`), the "stock counted today" nudge (`TillSession.php:612`), and batch expiry
  (`Batch.php:155`, `SelectBatch.php:38`). Each is off by 1–2 h around local midnight. Low impact.
- **Nothing converts displayed times to local time.** Nothing in `app/` or `resources/` calls
  `setTimezone`/`->timezone()` for display, so every timestamp renders in UTC. That includes the contribution
  receipt (`receipts/receipt.blade.php:82`), the Registro de dispensación rows (`RegistroDispensacion.php:124`),
  till open/close times (`till-session.blade.php:349,645`) and report DATETIME columns (`ReportColumn.php:96`).
  A Madrid sede will print times 1–2 h early on legal records. This is a separate issue but has the same root
  cause, and it is a go-live issue.

## Severity for go-live (production sedes are Europe/Madrid)

- **Compliance limits: HIGH.** The daily cap is correct. The monthly cap's day definition disagrees with both
  the business day and every report, and it has a gap on the 1st in which the monthly cap is effectively not
  applied (the daily cap still bounds it). Because the club's legal defence is "the cap blocked it", the cap and
  the documents must agree.
- **Cash reconciliation: not affected.** Till, Z-report and arqueo are keyed by session, not by date.
- **Counter and dashboard figures: MEDIUM.** The counter hub's takings and transaction count reset mid-session
  at UTC midnight. The admin dashboard's charts and "Este mes" disagree with its own cards. The over-limit alert
  and the over-limit report disagree with the cap.
- **Displayed timestamps in UTC: MEDIUM-HIGH** for the Registro and receipts. Legal records must show local time.

## Recommended fix: one source of truth

1. **`BusinessDay` owns every day, week and month window.** Add `BusinessDay::monthWindow($loc, $at)`, which
   starts the month at `startOfMonth()->setTime(cutoff)` exactly as `Period::businessWindow` does. Have
   `ResolveMemberLimits::monthWindow` (both the calendar and rolling30 branches) call it, and have
   `Period::businessWindow` delegate to `BusinessDay` so there is one definition. Pin it with a test showing a
   01:30 dispensation on the 1st counts in the previous month for both the cap and the report, and a test
   covering the gap on the 1st.
2. **Retire the naive constructors from domain code.** Replace every `Period::today()` / `thisMonth()` /
   `fromKey($k)` with no location with a location-aware call. Sites: `CounterHome.php:249`,
   `Dashboard.php:144,145,178,215,305`, `DashboardCharts.php:282,526`, `ConsumptionReport.php:207`,
   `Filament/Pages/Dashboard.php:344`, `DashboardChart.php:72`. The cleanest way is to give `Dashboard`,
   `DashboardCharts` and `AbstractReport` a `Period::sibling('month')` built from `$this->period->location`, so
   "this month" is always the business month of the same sede. Consider making `Period::today()` require a
   location, or deleting it, so the naive path cannot come back.
3. **Half-open everywhere.** Change `ResolveMemberLimits.php:104` and `RegistroDispensacion.php:156` to use
   `>= start AND < end`.
4. **Custom ranges.** Have `Period::custom()` take the location and map dates to business days
   (`start@cutoff → (end+1)@cutoff`).
5. **Display timezone** (separate prompt). Render timestamps in the sede's `timezone` at the edge: a shared
   formatter plus Filament `->timezone()`. Keep storage in UTC.
6. **Harness correction.** Replace the `dayboundary` "today agrees" check with a check that, for each location,
   `Period::fromKey('today', $loc)` and `Period::fromKey('month', $loc)` equal `BusinessDay::window` and the
   new `BusinessDay::monthWindow`, and that the cap's month window equals the report's month window. Also add a
   grep-style assertion that no `Period::today()` / `thisMonth()` / location-less `fromKey()` remains under
   `app/`. Separately, give the demo seed at least one Madrid/06:00 sede so the naive paths show up in the demo.
