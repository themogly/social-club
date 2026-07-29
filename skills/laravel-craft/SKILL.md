---
name: laravel-craft
description: >
  Backend coding philosophy and idioms for writing Laravel applications that are
  idiomatic, simple, and maintainable rather than over-engineered or fighting the
  framework. Use this whenever writing, structuring, reviewing, or refactoring
  Laravel/PHP code — controllers, models, Actions/Services, jobs, events,
  mailables, validation, queries, migrations, or deciding where logic should
  live. Covers convention-over-configuration, the right amount of abstraction
  (usually less), fat-models/thin-controllers, where business logic belongs,
  caching discipline, fail-loud error handling, testing, and the anti-patterns
  that signal someone is writing Java/Spring in PHP. Principle-led and
  transferable; a project's own CLAUDE.md holds the concrete per-project rules
  and always takes precedence.
---

# Laravel craft — backend coding philosophy

Good Laravel code is **idiomatic, simple, and works with the framework rather
than against it.** The most common way to write bad Laravel is to import patterns
from other ecosystems (repositories, heavy service layers, interfaces for
everything) that the framework neither needs nor uses. This skill encodes the
taste; a project's CLAUDE.md holds its concrete rules and named reference
implementations and always wins where they differ.

## Core philosophy

- **Convention over configuration.** Follow Laravel's conventions (naming,
  directory structure, resourceful controllers, Eloquent relationships) so the
  framework does the wiring for you. Fighting conventions creates work and
  surprises.
- **Use what the framework gives you** before writing your own. Str/Arr helpers,
  collections, `data_get`, casts, accessors, scopes, notifications, events,
  queues, gates/policies, form requests, mailables — reach for these first.
  Reinventing them is a tell.
- **The best abstraction is often no abstraction.** Don't add layers for
  hypothetical futures. A bit of duplication is cheaper than the wrong
  abstraction. YAGNI: build for what's needed now. "It's not Spring" — Laravel
  is expressive and direct; keep it that way.
- **Explicit over magic (where it aids clarity).** Prefer visible intent over
  invisible global behaviour. A query scope you can see beats a global scope that
  silently changes results; clear is better than clever.
- **Expressive, readable code.** Code should read close to prose. Good names over
  comments; comments explain WHY, not WHAT. Small methods that do one thing.

## Where logic lives

- **Thin controllers.** A controller resolves input, delegates, and returns a
  response. No query-building, no branching business logic, no data assembly
  inline. If a controller method is growing conditionals, the logic belongs
  elsewhere.
- **Fat models / domain on the model.** Query scopes, accessors/mutators,
  relationships, casts, and small domain methods live on the model. This is the
  Laravel-native home for a lot of "business logic".
- **Action / single-purpose classes** for a discrete unit of work that doesn't
  belong on one model (e.g. `ConvertEnquiryToBooking`, `SendPaymentReceipt`).
  One public method, clear name, easy to test and reuse. Prefer Actions over a
  sprawling "Service" class with many unrelated methods.
- **View models / dedicated classes** for assembling the data a page needs, so
  controllers and views stay clean.
- **No repository pattern.** Eloquent IS the data layer. Wrapping it in
  repositories adds indirection, loses Eloquent's expressiveness, and is not how
  Laravel is written. Query through models/scopes directly.
- **Don't build a service layer by reflex.** Many "services" are either a model
  method or an Action in disguise. Add a layer only when it genuinely earns its
  place (e.g. wrapping a third-party SDK behind a small seam).

## Validation

- **Form Requests** when rules are reused or non-trivial. **Inline
  `$request->validate()`** is perfectly idiomatic for simple, single-use rules —
  don't extract a Form Request for three simple fields just for ceremony. Match
  the weight of the tool to the rule.

## Eloquent & queries

- Use relationships and eager-load to avoid N+1 (`with()`); be deliberate about
  what you load.
- Casts for types (dates, booleans, enums, money, arrays/JSON) rather than manual
  conversion scattered around.
- **Use the CURRENT framework version's idioms, not superseded ones.** AI builders
  and older tutorials/training data emit outdated Laravel patterns — modernise them
  to the installed version's conventions. Most common: the **`casts()` method** over
  the `protected $casts` property (Laravel 11+); middleware/exception/routing config
  in **`bootstrap/app.php`** over a hand-kept `Http/Kernel.php`. Heuristic: match what
  a fresh `laravel new` on the project's version generates — and mixing old and new
  idioms across files is itself a tell that ported/generated code wasn't modernised.
- Scopes for reusable query constraints; keep them named and intention-revealing.
- Guard mass assignment: never `$guarded = []`; list `$fillable`. Set sensitive
  fields (status, role, balance, approved flags) server-side, never from request
  input.

## Caching discipline

- Cache **plain arrays/primitives**, never Eloquent objects (modern Laravel
  refuses to unserialize cached objects — this causes hard 500s).
- Cache content/read-heavy data; NEVER cache transactional/authoritative data
  (availability, balances, payments) — query those live.
- **Stale cache must never fail hard.** A value read from cache may have been
  written by older code (e.g. a typed property added later deserializes missing
  and throws "must not be accessed before initialization"). In queued contexts
  this fails SILENTLY and kills jobs/mail. Read cached/settings values through an
  accessor with a default fallback, and clear caches on deploy.
- Bust caches via model observers on save, so content stays fresh automatically.
- **Never put closures in config files.** `php artisan config:cache` (which runs on most deploys) serializes the entire config, and closures are not serializable — deploy fails with "non-serializable ... Call to undefined method Closure::__set_state()". This bites packages like Sentry whose published config may include a `before_send` closure. `env()` reads and ternaries in config are fine; **closures are not.** If a config value needs a callback (e.g. Sentry `before_send` to scrub data), register it at runtime in a service provider's `boot()`, not in the config file. Guard env-specific behaviour with serializable expressions — e.g. enable an integration only in production by conditionally setting its credential: `'dsn' => app()->environment('production') ? env('SERVICE_DSN') : null` (no DSN = disabled), rather than a closure. After any config change, run `php artisan config:cache` locally to confirm it still serializes.

## Error handling

- **Fail loudly.** Don't catch-and-return-null or swallow exceptions to "be
  safe" — that hides bugs and produces silent wrong behaviour. Let it throw, or
  handle it meaningfully (log + rethrow, or a deliberate user-facing result).
- Validate assumptions early; throw on genuinely invalid state rather than
  limping on with bad data.
- For money/payments/webhooks especially: be strict, be idempotent, never
  silently succeed on a failed operation.

## Background work, webhooks, mail

- **Webhooks:** the controller verifies the signature and dispatches; each event
  type is a small Action. Thin dispatcher, never a fat match/if chain. Verify
  signatures, reject unsigned/replayed, and be idempotent (dedupe on event id).
- **Idempotency:** anything triggered by webhooks or schedulers must not
  double-fire under retries — per-event/per-recipient sent markers, tested.
- **Mailables:** queue them; for per-recipient batches, queue per recipient so
  one bad address can't fail the batch. Every mailable needs a render test AND a
  local preview route — mocked mail never renders, so template errors are
  otherwise invisible.
- **Silent-failure awareness:** queue workers (Horizon) and the scheduler
  (`schedule:run` cron) must be running or background work dies quietly. Treat
  them as monitored, must-be-running services.

## Money

- Store money as integer minor units (pence/cents); present/enter as major units
  via a cast/presenter. Payment-provider charges use the minor-unit value. Pin
  with an end-to-end test asserting the real charged amount (e.g. £260.00 →
  26000). Never change money handling in a way that alters the charged amount.

## Testing

- Every feature ships with tests; mock external services (never hit real Stripe/
  email APIs in tests). Run the FULL suite after each feature (regression), not
  just new tests.
- Pin behaviour with a test BEFORE refactoring; prove identical behaviour after.
- Run the suite against the SAME database engine as production (e.g. MySQL), at
  least in CI — SQLite-only testing hides driver-difference bugs (JSON columns,
  strict typing, string lengths, booleans).
- Tests prove CORRECTNESS, not COMPLETENESS — they can't catch a feature that
  was never built or was stubbed as a placeholder. Hold a separate scope check
  for that.

## Tooling

- A formatter (Pint) and static analysis (Larastan/PHPStan) run in a single
  `check` gate with the test suite; nothing commits red. Ensure the gate's exit
  code propagates (don't pipe through anything that swallows the status).
- Type-hint params and returns. (If a project disables `declare(strict_types=1)`
  as a convention, enforce that via the formatter rather than relying on memory.)

## Workflow

- One feature per branch off latest main; `git pull` immediately before branching
  (stale branches off old commits cause painful merge conflicts).
- Small, atomic, conventional commits. Record judgment calls in a DECISIONS log.
- Never `migrate:fresh`/`migrate:refresh` against data that matters.
- Keep a project CLAUDE.md with the concrete rules and NAMED reference
  implementations (the first real example of each pattern), so new work copies
  the canon rather than re-deriving it.

## Anti-patterns (writing Java/Spring in PHP — the tells)

- Repository classes wrapping Eloquent.
- A "Service" layer of classes that are really single Actions or model methods.
- Interfaces for things with exactly one implementation and no swap need.
- Business logic in controllers or in Blade views.
- DTOs/mappers everywhere for data the framework already hands you as models/
  collections.
- Catch-all try/catch that swallows errors and returns null.
- Caching Eloquent objects; caching authoritative transactional data.
- `$guarded = []` and trusting request input for sensitive fields.
- Over-abstracting for imagined future requirements (YAGNI violations).
- Comments that restate what the code does instead of why.
- Skipping/deleting a failing test to make the suite green.
