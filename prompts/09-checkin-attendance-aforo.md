# 09 — Check-in, attendance, aforo & door checks

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–07 merged.

`git checkout main && git pull` → `git checkout -b feat/checkin-aforo`.

> Why this exists: a Spanish CSC has to be able to say who was on the premises, when, and that every
> one of them was a verified adult member in good standing. It also usually has a municipal capacity
> limit. This is the front door, and v1 had nothing here.

## Build

**Check-in screen (Livewire, tablet-first, its own route, PIN-identified operator)**
- **QR scan is the primary path** — supports a handheld USB/Bluetooth scanner (keyboard input, the
  usual bar-till kit) **and** camera scan in the browser. Name / member-number search is the fallback.
- On scan, show the member card: **photo (large, for visual verification)**, name, member number,
  status, tier, membership expiry, carencia state, MTD grams against their limit, wallet balance,
  and any active sanction.
- One-tap **Check in**. If already inside, the same scan offers **Check out**.

**Door checks — run automatically on every check-in, and block clearly**
1. Membership **active and in date** (lapsed → blocked, with the renewal action right there).
2. **Age** ≥ configured minimum (from DOB — belt and braces even though onboarding checked it).
3. **Status** is ACTIVE (suspended/expelled → blocked, showing the sanction).
4. **Aforo**: current occupancy < the location's capacity → otherwise warn/block per setting.
5. **Carencia**: passed, or flagged as "may enter, may not be dispensed to".
6. **Debt / unpaid fee** over the configured threshold → warn (and optionally block).

**Each check reads its behaviour from the per-surface enforcement matrix (prompt 03) — `BLOCK`,
`WARN` or `OVERRIDE`, configured independently for the door and the counter.** They genuinely
differ: most clubs let a member with debt come in and sit down, but not take product. Hardcode none
of them, and don't assume the door and the counter agree.

Each block states the reason in plain language and offers the legitimate next step (renew, pay, call
a manager). A manager may override a door check with **`checkin.override`** (distinct from the
counter's `limits.override`); the override is **always logged** with the reason. Never a silent pass.

Extract the six checks into one named `App\Actions\ResolveMemberEligibility` that returns a
structured verdict per rule for a given surface. The counter (prompt 11) calls the same Action —
two copies of this logic is exactly the bug this set forbids for limits and prices.

**Who's inside now**
- A live list for the active location: photo, name, checked-in time, elapsed, MTD grams. Searchable.
- **Aforo counter**: current / capacity, as a progress ring with a colour state (ok / near / at
  capacity). Prominent on this screen and on the dashboard (prompt 14).
- Quick **check-out** from the list; **check out all** at close.

**Automatic check-out**
- A scheduled job closes any open check-in at the location's closing time (setting), marking
  `auto_checked_out = true` so genuine dwell time isn't overstated in the reports. Idempotent.

**Daily entry–exit sheet**
- Per location, per day: member number, name, in, out, duration, operator. Viewable, printable and
  exportable (CSV/PDF). This is one of the artifacts a club is expected to be able to produce.

**Visit history**
- Per member: every visit with date, times, duration and location — on the member detail's Visits tab.
- Aggregate: visits per member per period, average dwell time, and **footfall by hour × day of week**
  (feeds the dashboard heatmap in prompt 14 and drives staffing).

## Rules

- The scanned token is the signed, non-guessable QR token from prompt 04 — never a member id.
- A check-in is scoped to the active location and records the operator who was PIN-identified.
- Occupancy is **always queried live**, never cached — it is transactional data.
- Check-in and check-out are separate audited events; a check-out cannot precede its check-in.
- The screen must work on a tablet at 1024 and stay usable one-handed at 390.

## Tests (required)

- Each door check blocks for the right reason and permits when satisfied — one test per rule.
- Aforo: at capacity the next check-in is blocked (or warned, per setting); the counter is accurate
  after a mix of check-ins and check-outs.
- A manager override is permissioned and writes an `AuditLog` entry naming the rule overridden.
- Auto-checkout closes only open sessions at that location, sets the flag, and is idempotent.
- A member cannot be checked in twice concurrently at the same location.
- The daily sheet totals match the underlying check-in rows for the period.
- A scanned integer id or a revoked token 404s.

## Finish

`composer check` green. Screenshot the check-in screen and the who's-inside list across 1440 / 1280
/ 1024 / 390 and a short laptop height, motion reduced and allowed. Push the branch; **do not merge**.
