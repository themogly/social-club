# 06 — Consumption model: declared forecast, limits & enforcement

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md` and section A of NOTES. Requires 01–05 merged.

`git checkout main && git pull` → `git checkout -b feat/consumption-limits`.

> This is the prompt that turns a till into a defensible system. The principle, borrowed from the
> best-in-class competitor: **compliance that blocks, not compliance that documents.** A limit that
> only warns is a limit the club cannot rely on in front of a judge.

## Build

**Declared consumption forecast (*previsión de consumo*)**
- Each member declares a monthly quantity at application, chosen from the configured options
  (default 30 / 50 / 60 / 90 g) or a free figure within the ceiling. Stored as
  `declared_monthly_grams` in centigrams.
- The declaration is a **document**: generated from the member's data, signed (wet or electronic),
  stored in the vault (prompt 04), versioned when changed. Changing it is an audited action.
- The **sum of all active members' declared forecasts** is the club's authorised cultivation /
  acquisition volume. Expose it as a computed figure (used in prompts 14 and 15).

**Limits, resolved in a single place**
- One `App\Actions\ResolveMemberLimits` (or a model method) returns, for a member at a moment:
  `daily_limit_cg`, `monthly_limit_cg`, `daily_used_cg`, `monthly_used_cg`, `daily_remaining_cg`,
  `monthly_remaining_cg`. Resolution order: **per-member override → tier → location → organisation
  default**. Everything else — POS, PWA, dashboard, reports — reads this one place. No duplicated
  limit arithmetic anywhere.
- Defaults from settings (prompt 03): daily 3.5 g, monthly ceiling 100 g, per the NOTES table.
- **"Today" and "this month" come from `BusinessDay` (prompt 01)** — the location's timezone and
  business-day cutoff — never from `now()->startOfDay()` inline. A club open past midnight otherwise
  resets the daily cap mid-service, which is precisely the figure its legal defence rests on.
- The monthly window is a **calendar month by default**, with a rolling-30-day option as a setting.
  Whichever is chosen, state it in the UI so nobody guesses.

**Enforcement at the point of dispensation**
- Before a dispensation commits, check it against both limits **inside the same database transaction
  as the stock movement**, so two tills cannot each pass the check and jointly breach the limit.
- On breach: **hard block** by default. The message states the rule, the figure, and what remains.
- **Override** requires `limits.override` (manager+), a typed reason, and writes an `AuditLog` entry
  recording the member, the limit, the amount attempted and the authoriser. Overrides are a first-
  class report (prompt 14) — a club that overrides constantly should be able to see that.
- Settings decide per rule whether it blocks, warns, or allows-with-override.

**The gauge**
- A consumption gauge is shown wherever a member is identified — check-in, POS, member detail, PWA:
  MTD grams / monthly limit as a bar or ring with a colour state, plus today's figure against the
  daily limit. Under 70% neutral, 70–95% warning, ≥95% alert. Never colour alone — always a number.

**Aggregate ceiling**
- The 100 g/month figure is an aggregate across *all* associations a member belongs to, which no
  single club can verify. Record the member's declaration that they belong to no other club (a
  consent-style field), enforce this club's own ceiling, and be explicit in the UI that the
  aggregate figure is self-declared. Do not pretend to verify what cannot be verified.

## Rules

- Limit arithmetic exists in exactly one place. If a second copy appears, that's the bug.
- Limits are computed from the **dispensation ledger**, live — never a cached counter that can drift.
- Voided and corrected dispensations must **release** their grams from the used total, and the tests
  must prove it.
- Read every limit setting through an accessor with a safe default.

## Tests (required)

- A member at 3.4 g today with a 3.5 g daily limit: 0.1 g succeeds, 0.2 g is blocked.
- Monthly: dispensations across the month accumulate correctly; the first of the next month resets
  (calendar mode) or rolls (rolling mode) — test both settings.
- **Concurrency**: two simultaneous dispensations that individually pass but jointly breach — exactly
  one commits.
- Void releases the grams; the member can then be dispensed to again up to the limit.
- Override: refused without the permission; with it, succeeds and writes an audit entry naming the
  rule, the amount and the authoriser.
- `ResolveMemberLimits` honours the override → tier → location → org precedence, one test per level.
- The gauge figures on POS, check-in and PWA all come from the same resolver and agree.

## Finish

`composer check` green. Record the monthly-window choice, the block/warn/override matrix and the
aggregate-ceiling honesty note in DECISIONS.md. Push the branch; **do not merge**.
