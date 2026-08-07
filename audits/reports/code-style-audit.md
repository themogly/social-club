# Code-style audit (Laravel idiom & consistency)

**Branch** `chore/code-style-audit` · **from** `6a7759f` (main, after the accessibility, admin and design
audits) · **date** 2026-08-07

Scope: `app/` (475 PHP files), `routes/`, `database/`, `config/`, `tests/`. Behaviour identical before and
after — this is not a redesign.

## Summary

| | count |
|---|---|
| PHASE 1 — correctness & convention | 3 |
| PHASE 2 — simplification | 1 |
| PHASE 3 — polish | 1 |
| Checked and found correct | 6 |

**This is a short report because the codebase is in good shape, and that is the honest result.** The
framework idioms are current (no `Http/Kernel.php`, no `$casts` property, config in `bootstrap/app.php`),
Larastan runs at level 6 with generics on every relation and scope, there is **no query building in any
Blade view** (0 occurrences of `::query()` or `::where(` under `resources/views`), money and weight never
leave their casts, and every one of the 15 `catch` sites that returns a default has a documented reason —
`Settings` degrading rather than throwing is a written CLAUDE.md requirement, and the rest are parse helpers
where invalid input is an ordinary outcome. None is error-swallowing.

The Phase 1 findings are the same shape as everything else this round has surfaced: **one operation with more
than one implementation of the rule**, where the rule decides something that matters.

---

### PHASE 1 — Correctness & convention

- **Four resolvers answer "which open till is this?", in two different versions.**

  | caller | rule |
  |---|---|
  | `DispensaryPos::openTillSession()` | terminal-matched (normalised key, prompt 84), else **oldest** open |
  | `BarPos::openTillSession()` | byte-identical copy of the above |
  | `CheckInScreen::openTill()` | **newest** open (`latest('opened_at')`), terminal-blind |
  | `MembershipCounter::openTill()` | **newest** open, terminal-blind |

  All four feed the SAME operation: `CollectsMembershipFees::collectFeeThrough()` → `RecordFeePayment`,
  which posts a CASH fee into `$session`. → One resolver, using the scope that already exists. → **Why it
  matters:** `Membership`, `Order` and stock all funnel through one writer precisely so a rule cannot be
  written twice and drift; the *selection* of the till never got the same treatment, and it is written four
  times in two versions.

  **Scoped honestly, because the first draft of this finding overstated it.** With ONE open till — the
  common case, and every case in the seed and the tests — all four agree and nothing is wrong. The
  divergence only appears on a sede running two or more tills at once, and even then neither rule is
  *wrong*: the POS legitimately knows its own terminal and the door legitimately does not have one. What is
  genuinely wrong is narrower and is recorded as its own defect below: **the door and Socios pick a drawer
  arbitrarily and never say which.**

- **A cash fee taken at the door or on Socios posts to a silently-chosen drawer.** Neither screen has a
  terminal, and neither surfaces which till it used — `inline-fee.blade.php` and the Socios panel take only
  a boolean (*is a till open*). On a two-till sede the money lands in whichever session was opened last,
  with nothing on screen naming it. → Name the drawer where the fee is about to be posted. → **Why it
  matters:** `TillSummary` derives expected cash from the ledger, so at the blind close the drawer that took
  the money is over and the other is short, and the operator has no way to reconstruct why. This is a
  product change rather than a refactor, so it is reported for its own branch and NOT made here — a
  code-style audit does not get to redesign a money flow.

  Worse in the small: **`TillSession::scopeOpen()` already exists** (`app/Models/TillSession.php:119`) and
  none of the four uses it — six callers in the codebase do, all of them elsewhere.

- **`ApplicationController::store()` carries the domain logic of submitting an application** — ~110 lines
  assembling the payload, resolving the avalador, converting declared grams to centigrams, capturing the
  consent version AND the locale the applicant actually read, rate-limiting and vaulting two encrypted
  uploads, recording MRZ corrections, then updating the record. → Extract to
  `App\Actions\Members\SubmitApplication`; the controller resolves the invite, guards it, and returns. →
  **Why it matters:** `CLAUDE.md` is explicit — *"Controllers/Livewire components are thin: resolve + return
  only"*, *"Business logic lives in fat models … or single-purpose Action classes"* — and this is the one
  place in the codebase that breaks it. It is also the worst place to break it: an **unauthenticated** route
  that writes Article 9 material to the encrypted vault and stamps the consent record the club later relies
  on. Every comparable write in this product (`CommitDispensation`, `CommitOrder`, `ApproveApplication`,
  `RecordFeePayment`) is an Action with its own tests; this one is reachable only through HTTP.

**Review:** the second is a genuine defect with a money consequence and ranks first, as the brief asks — but
it is a product change, so it is reported rather than made here. The first and third are fixed on this
branch. The third is the single largest deviation from a rule this project states in writing.

---

### PHASE 2 — Simplification & de-abstraction

- **Four copies of "the member's active membership at this sede"**, in `CheckInScreen::activeMembership()`,
  `DispensaryPos::activeMembership()`, `MembershipCounter::latestMembership()` and
  `CollectsMembershipFees::outstandingMembership()` — all four `->memberships()->withoutGlobalScopes()
  ->where('location_id', …)->where('status', ACTIVE)->latest('id')->first()`, and `Membership::scopeActive()`
  already exists. → One method on the fat model: `Member::activeMembershipAt(Location $location)`. → **Why it
  matters:** unlike the till above these four agree today, which is exactly why it is Phase 2 and not Phase
  1 — but they are the same query written four times, and the till finding is what happens when four copies
  of a rule stop agreeing.

**Review:** one item, and it is the *cause* of the Phase 1 defect rather than an aesthetic complaint.

---

### PHASE 3 — Polish

- **An orphaned docblock in `ApplicationController`** (line 163): `/** The post-submit redirect —
  byte-identical for a genuine submit and a silently-dropped bot. */` sits directly above `read()`'s own
  docblock and describes `submittedRedirect()`, which is defined 80 lines further down. → Move it to the
  method it documents. → It is small, but a comment attached to the wrong method is worse than no comment,
  and this codebase has already had a stale docblock propagate a false claim into two other documents (the
  admin audit's Phase 3).

**Review:** one item; there is no general comment rot to clean up.

---

## Checked and found correct — no action

Reported so a later pass does not re-derive them.

- **Framework idioms are current.** No `app/Http/Kernel.php`; middleware and config live in
  `bootstrap/app.php`; no model uses the superseded `protected $casts` property (all use the `casts()`
  method); enums are backed with `label()`; relations and scopes carry Larastan L6 generics throughout.
- **No logic in views.** Zero `::query()`, `::where(` or `::all()` anywhere under `resources/views`. Page
  data assembly is in `App\ViewModels` (17 classes) exactly as CLAUDE.md prescribes.
- **No error-swallowing.** All 15 `catch`-and-return-a-default sites are deliberate: `Settings` degrading
  instead of throwing is a written requirement (a stale cache must not break a queued job); the parse
  helpers (`Weight::fromGrams`, `Carbon::parse`, `parseCents`) treat invalid input as an ordinary outcome
  and return null to a caller that checks; the logo/image readers degrade to "no image" so a PDF or mailable
  still renders; and `MaterialiseRecurringExpenses` catches a `QueryException` on its unique marker, which
  IS the idempotency mechanism.
- **The Action layer is the norm, not the exception** — 78 single-purpose classes in `App\Actions`, and
  `tests/Feature/Cleanup/UnreachableCodeGuardTest` already enforces that each has a non-test caller.
- **No God controllers besides the one above.** The other 16 controllers total 1,461 lines; the largest,
  `Socio\PwaController`, is 179 lines across six thin read actions.
- **`App\Support` and `App\ViewModels` are not mixed.** Support holds genuine helpers (`Money`, `Weight`,
  `BusinessDay`, `TillSummary`, `ActiveScope`); ViewModels hold page assembly. Spot-checked both directions.

## Discussion (needs owner decision)

None. No idiom-vs-decision conflict surfaced: every documented decision this audit touched
(`withoutGlobalScopes()` on counter queries, `Genetic::grams_per_unit_cg` staying a plain integer, no
repository pattern, no `declare(strict_types=1)`) is deliberate, recorded in CLAUDE.md or DECISIONS.md, and
was enforced rather than relitigated.
