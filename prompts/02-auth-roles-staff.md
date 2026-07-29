# 02 — Auth, roles, permissions, location assignment & counter PINs

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01 merged.

`git checkout main && git pull` → `git checkout -b feat/auth-roles`.

## Build

**Staff/owner login** → the Filament panel (mounted at `/`). `User` implements `FilamentUser` with a
real `canAccessPanel()` gate: has a staff role **and** is active. Standard Laravel auth, kept vanilla
so the member guard in prompt 15 is additive. **MFA (TOTP) available on admin accounts.**

> Guard trap to avoid: a `canAccessPanel()` that silently rejects looks identical to a wrong
> password. Return a clear, distinct failure and log it, so nobody debugs a role problem as a
> credentials problem.

**Roles (spatie/laravel-permission), org-scoped:**

- **OWNER** — assignable to more than one person. Org-wide: all locations + the org rollup
  (`reports.view.all`), set member limits and debt (`member.limits.set`), assign custom discounts
  (`member.discount.assign`), record overheads (`expenses.overheads`), manage expense categories,
  organisation settings, locations, staff, and **view ID documents** (`member.documents.view`).
- **MANAGER** — per assigned location(s): manage members, memberships, genetics, batches, stock,
  articles, expenses; view that location's reports; override membership fees
  (`membership.fee.override`); **authorise a consumption-limit override** (`limits.override`);
  approve expenses; open/close and reconcile tills (`till.close`); transfer members between managed
  locations; void a dispensation (`dispensation.void`).
- **STAFF** — per assigned location(s): `pos.use`, `pos.bar`, `checkin.manage`, `members.view`,
  `members.create`, record till petty-cash expenses, `till.open` and record cash movements.
  **Cannot** close a till, override any check, void, or view ID documents.

**Permission keys — the complete list. Every permission any later prompt references must be here.**

*Reports & data* — `reports.view`, `reports.view.all`, `reports.export`
*Members* — `members.view`, `members.create`, `members.edit`, `members.transfer`, `members.import`,
`member.limits.set`, `member.discount.assign`, `member.documents.view`, `member.sanction`,
`applications.review`
*Membership* — `membership.fee.override`, `carencia.waive`
*Attendance* — `checkin.manage`, `checkin.override`
*Counter* — `pos.use`, `pos.bar`, `dispensation.void`, `order.void`, `limits.override`
*Catalogue & stock* — `genetics.manage`, `prices.manage`, `stock.manage`, `stock.merma`,
`stock.transfer`, `stock.take`, `articles.manage`, `discounts.manage`
*Money* — `wallet.adjust`, `till.open`, `till.close`, `cash.bank`, `expenses.record`,
`expenses.approve`, `expenses.overheads`, `expenses.categories`, `purchases.manage`
*Governance* — `documents.generate`, `minutes.manage`, `register.view`
*Privacy* — `data.request.handle`, `data.erase`
*System* — `locations.manage`, `staff.manage`, `settings.manage`, `settings.manage.location`,
`audit.view`

> Two distinctions that matter and are easy to get wrong: **`limits.override`** authorises a
> *consumption-limit* breach at the counter; **`checkin.override`** authorises a *door* check
> (aforo, age, sanction, debt) — they are different powers held by different people in some clubs.
> And **`settings.manage.location`** lets a manager configure their own premises without granting
> them the org-wide compliance thresholds.

Enforce on Filament resources/pages **and** policies — server-side, never a hidden button. Every
Livewire counter component authorises on mount.

**Location assignment:** staff/managers assigned to one or many locations (`location_user`). A
**location switcher** in the panel header sets the session active location, filtered to the user's
assignments; OWNER additionally gets **"All locations"**. The active location drives `LocationScope`.
Persist the choice; default to the user's first/only location.

**Counter PIN + fast user switching:** hashed `pin` on User. The dispensary POS, bar POS and check-in
screens run under one authenticated device session, with a **PIN unlock to identify the operator**
for every transaction — so a till can switch operator without a full re-login. Rate-limited, never
logged in plain text, never shown in the UI. The PIN identifies the operator recorded on every
dispensation, order, cash movement and check-in.

**Staff admin:** a Filament resource for users — invite, assign roles and locations, set/reset PIN,
deactivate (never hard-delete a user with transaction history — soft-delete and block login).

**Password reset:** Laravel's built-in flow via Resend; tokens expiring and single-use.

## Rules

- No passwords or PINs in logs, exports, or the repo. Rate-limit login and PIN entry.
- A deactivated user's history stays intact and attributed.
- Role and permission changes write an `AuditLog` entry.
- Seeder assigns the three pinned dev users (prompt 00) their roles, locations and PINs.

## Tests (required)

- Each role's permission matrix: a STAFF user is refused `limits.override`, `dispensation.void`,
  `member.documents.view` and `expenses.overheads` — as 403, at the policy, not by hiding a button.
- A user assigned only to location A cannot reach location B's records by id in a URL.
- OWNER can switch to "All locations"; MANAGER and STAFF cannot.
- PIN unlock succeeds on the correct PIN, is rejected and rate-limited on wrong; the resulting
  transaction records the *unlocked operator*, not the device session's user.
- `canAccessPanel()` refuses a user with no role and a deactivated user.
- MFA challenge is required when enabled.

## Finish

`composer check` green. Record the role/permission matrix and the member-guard seam in DECISIONS.md.
Push the branch; **do not merge**.
