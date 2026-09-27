# Security & privacy audit — pre-live round (2026-09)

**Commit:** `2d98aed` (branch `audit/pre-live`, identical to `main`; prompt 269 is the latest merge).
**Date:** 2026-09-27.
**Method:** REPORT ONLY (Step 1 of `audits/security-audit.md`). I changed no production code. I read the code
against the last two security reports and the post-hiatus audit (`098636d`, prompt 239), then went through the
security-relevant diff `098636d..2d98aed` (51 files under `app/`, `config/`, `bootstrap/` and the migrations, plus the
lockfiles). Every suspected hole was **proven by request**: I wrote six throwaway HTTP/Livewire tests in
`tests/Feature/ZzAuditProbe/`, ran them, and deleted them (the directory is gone). They drove the real Livewire update
endpoint through the HTTP kernel, using the existing `PostsLivewireOverHttp` helper. I also ran the existing security
suites (`tests/Feature/Security`, `RgpdCompletenessTest`, `SecurityHeadersTest`, `MemberPwaTest`): **127 tests, all
green**. `composer audit`: no advisories. `npm audit --omit=dev`: 0 vulnerabilities. Nothing ran against MySQL (that
is CI's job).
**Scope:** everything through prompt 269. The deepest look went to prompts 255–267 (PIN operator as actor, roles page,
counter-only accounts, PIN = sign-in, wallet tab). I re-checked the fundamentals quickly and list them under *Verified OK*.

---

## Summary

Prompt 267 was the right decision. Making the PIN a real sign-in closed the reported hole: a staff PIN on an
owner-logged tablet no longer gets the owner's panel. But it **raised the value of a PIN to "a full login"**, and the
code around the PIN was built when a PIN only named who did a transaction. Four of the five Phase 1 findings come from
that change in threat model:

- a second path still switches the counter's person without switching the login;
- the PIN throttle can be reset by the attacker;
- two people can share a PIN;
- a locked counter still answers panel Livewire calls as the last person who typed a PIN.

The fifth finding is on the new roles work: `staff.manage` quietly makes its holder equivalent to the owner.

Counts: **Phase 1 — 5**, **Phase 2 — 1**, **Phase 3 — 2**.

---

## PHASE 1 — Must-fix (exploitable / data-exposure)

- **Counter identity (267): a till HANDOVER switches the counter's person but not the login, so the incoming person
  works under the outgoing person's account.** `TillSession::handOver()` ends with `CounterOperator::set($incoming)`
  (`app/Livewire/Counter/TillSession.php:315`). It never calls `SignInOperator`, which is only called from
  `IdentifiesOperator::unlockOperator()` (`app/Livewire/Counter/Concerns/IdentifiesOperator.php:311`). **Proven:** the
  owner PINs in and hands the drawer to STAFF (PIN `3333`). After that, `CounterOperator::id()` is the staff member but
  `Auth::id()` is still the owner, and `GET /users` answers **200**. `RedirectCounterOnlyAccounts` lets it through
  because an operator is identified and the logged-in user has `panel.access`. That is exactly the case 267 was built to
  close, reached by a second door. Panel actions are also audited as the owner, because `CounterRequest::actorId()`
  falls back to `Auth::id()` off the counter.
  → Call `(new SignInOperator)->handle($incoming, $location)` in `handOver()` after `HandOverTill` succeeds. Better
  still, make "set the operator" and "sign in" one method, so a third caller cannot split them again. Add the handover
  case to `PinIsASignInTest`.
  → **Why it matters:** this is a staff → owner privilege escalation (Roles y permisos, Personal, audit log) that any
  ordinary shift change triggers. Nobody has to attack anything.

- **PIN throttle (120/267): the attacker's OWN correct PIN resets the lockout, so guessing is unbounded.**
  `UnlockOperator::handle()` calls `$this->clear($throttleKey)` on any match (`app/Actions/UnlockOperator.php:73`),
  which wipes attempts, lockout and strikes. There is no request rate limit on Livewire's update endpoint, and a PIN can
  be as short as 4 digits (`app/Filament/Resources/Users/Schemas/UserForm.php:87`). **Proven:** a staff member made 6
  cycles of 4 wrong guesses followed by their own PIN. That is **24 wrong guesses against `max_attempts = 5` with no
  lockout**. The owner's PIN then signed the session in **as the owner**. The whole loop is scriptable over
  `/livewire-…/update`, so 10,000 four-digit PINs take minutes. The supervisor-PIN path (`authoriserFromPin`) shares
  both the bucket and the reset.
  → Stop a success from clearing the failure tally: let the attempts decay on their own 300 s TTL, and let strikes decay
  on their hour. Add a per-session limit on `unlockOperator`/`authoriserFromPin` calls (for example 10 a minute) that no
  PIN resets. Consider a longer minimum PIN for roles holding `panel.access`. (This changes prompt 120's "a correct PIN
  clears everything"; see Discussion.)
  → **Why it matters:** since 267 a PIN is a full login, so this lets any insider take over the owner account — the
  Article-9 register, erasure, roles.

- **PINs are not unique, and a shared PIN signs in as whoever matches first.** The PIN field only checks
  `numeric`/`minLength(4)`/`maxLength(8)` (`UserForm.php:81-93`). `UnlockOperator` returns the first active user at the
  sede whose hash matches (`UnlockOperator.php:69-72`). **Proven:** an owner and a staff member at the same sede both
  have PIN `5555`. The staff member types their own PIN and is signed in **as the owner**. Before 267 this was a
  misattribution; now it is an authentication failure. With 4-digit PINs and people choosing `1234`/`0000`, it will
  happen by accident.
  → On save (Create/EditUser), refuse a PIN that `Hash::check`s against any other active user sharing a sede with this
  one. The admin setting PINs already controls them, so saying "choose another" leaks nothing new. As a fail-safe in
  `UnlockOperator`, if more than one candidate matches, refuse and audit instead of picking one.
  → **Why it matters:** anyone who shares a PIN with someone more senior gets that person's full identity just by
  typing their own PIN.

- **Roles (262): `staff.manage` is equivalent to the owner — its holder can make themselves OWNER.** `UserPolicy::update`
  checks only `staff.manage` (`app/Policies/UserPolicy.php:28`), including on your own row and on the owner's row. The
  roles `Select` offers every role, OWNER included (`UserForm.php:95`). **Proven:** with `staff.manage` granted to
  MANAGER on Roles y permisos, a manager opens their own user record, sets roles to `[OWNER]`, saves, and
  `hasRole('OWNER')` is **true**. The same holder can reset the owner's password or PIN. This defeats the stated reason
  the roles page is gated on the owner **role** rather than a permission (`RolesPermissions.php` docblock,
  `SetRolePermission.php:26`). The permission sits in `Permissions::SENSITIVE`, but the warning only says "reaches the
  compliance and privacy core"; it does not say "makes them an owner".
  → Only an owner may assign or remove the OWNER role, or edit an owner's password, PIN, `active` flag or roles: filter
  the `Select` options and enforce it in `UserPolicy::update` (and in `delete`/`restore` for owner rows). A
  non-owner cannot edit their own roles. Denial tests for both.
  → **Why it matters:** the owner is warned but not blocked, as 262 decided. The warning should describe what the grant
  actually does, and the server should hold the line the page's own design says it holds.

- **"Locked means locked" (267) does not cover Livewire: after an idle lock, a panel component still runs as the last
  PIN person.** The rule lives in `RedirectCounterOnlyAccounts` (`app/Http/Middleware/RedirectCounterOnlyAccounts.php:36`),
  on the panel's route stack only (`app/Providers/Filament/AdminPanelProvider.php:174-196`, not persistent). Livewire
  update requests re-run only the persistent middleware (Filament's `Authenticate` et al.). Filament's `Authenticate`
  passes, because the session is still signed in as the last operator. **Proven:** the owner PINs in and opens Roles y
  permisos, then the counter idle-locks. A `GET /roles-y-permisos` correctly redirects to the counter. But posting that
  page's `wire:snapshot` to the update endpoint with `toggle('STAFF','staff.manage')` returns **200 and grants it, as
  the owner**. On a shared tablet the snapshot comes from the back gesture or bfcache. Livewire snapshots are
  checksummed but not bound to a session or user.
  → Add a Livewire `before('hydrate')` hook, the same shape as `CounterHandoverConfinement`, that refuses (403 + audit)
  any **non-counter** component when the session has `counter.location_id` and no `CounterOperator`. Alternatively,
  register the middleware as Livewire-persistent. Test it over HTTP like `HandoverConfinementOverHttpTest`.
  → **Why it matters:** it needs physical access to a locked tablet with panel pages in its history, which lowers the
  likelihood. But it is exactly the "walk up to a locked counter" case 267 claims to close, and the code can close it
  (unlike plain bfcache repaint, which remains a kiosk task).

**Review:** all five FIXED in prompt 270 (`PreLiveSignInHardeningTest`). Original note — suggested order: handover sign-in and PIN uniqueness first (one-line and
small fixes, high impact), then the throttle, then the owner-role guard, then the Livewire lock hook. Each needs a
denial test over HTTP.

---

## PHASE 2 — Privacy & GDPR

- **ID scans viewed at the counter can stay in the tablet's browser cache after the link expires.** `VaultStream::respond()`
  returns the decrypted file with `Content-Type`, `Content-Disposition: inline` and `nosniff`, but no `Cache-Control`
  (`app/Support/VaultStream.php:43-47`). Symfony therefore sends `no-cache, private`, which lets the browser **store**
  the response and revalidate it later. Since 262, staff open members' ID scans from the counter's member record on
  shared tablets. The signed URL lasts 300 s, but the decrypted image can stay in the browser's disk cache for much
  longer.
  → Send `Cache-Control: no-store, private, max-age=0` (plus `Pragma: no-cache`) on every `VaultStream` response.
  Assert it in `DocumentSecurityTest`.
  → **Why it matters:** these are identity documents of cannabis-club members, linked to Article-9 data. "Encrypted at
  rest, short-lived URL" should not end with a plaintext copy in a shared device's cache.

What I checked and found holding: erasure coverage (`RgpdCompletenessTest` green). No new member-linked table since 239:
the two migrations add `members.debt_limit_cents` and `role_permission_overrides`. `debt_limit_cents` is not fillable
and has a single audited writer. The RAT's "Acceso interno" line follows the live roles (262).

**Review:** FIXED in prompt 270.

---

## PHASE 3 — Hardening & monitoring

- **Deactivating a user does not end their counter session or their operator identity.** `CounterOperator::current()`
  is `User::find($id)` with no `active` check (`app/Support/CounterOperator.php:31`). `requireOperator()` checks only
  that an id is present, and counter routes carry only `web` + `auth`. **Proven:** a manager PINs in, is then
  deactivated, and still gets `/counter/till` **200**, remains the operator, and `can('till.close')` is still true. The
  panel correctly answers 403, because `canAccessPanel()` checks `active`. Since 267 that session is signed in as them.
  → Treat an inactive user as no operator (`CounterOperator::current()`/`id()` return null, and `unlockOperator`
  already filters on active). Log out an inactive web user on counter routes: a small check in the web-group
  middleware, or `canUseTheApp()` in the counter mount gates.
  → **Why it matters:** "deactivate the account" is how a club removes someone who has just been let go. It should take
  effect on the next request, not at the next idle lock.

- **The CSP is still report-only and has no reporting endpoint**
  (`config/security.php:19`, `.env.example:127` `CSP_ENFORCE=false`; no `report-uri`/`report-to` in the policy). The
  config says to "flip once the report stream is clean", but no report stream exists: violations only reach each
  device's console. So at go-live the app effectively has no CSP.
  → Either add a reporting directive with a small endpoint (throttled, body-size bounded, stored or logged), or do a
  browser pass of panel, counter and PWA with `CSP_ENFORCE=true` and ship enforcing. The policy is already permissive
  enough for Alpine and Livewire.
  → **Why it matters:** the CSP is the second wall behind output escaping. It is cheap here, and it is off.

**Review:** both FIXED in prompt 270 (inactive sign-out; CSP report stream — enforcing stays the owner's call).

---

## Verified OK since the last audit (no action)

- **267 `SignInOperator` itself is sound.** `SessionGuard::login()` → `updateSession()` migrates the session id (no
  fixation) and keeps the data. The original remember cookie is forgotten, the panel sede is dropped, and the switch is
  audited. `PinIsASignInTest` (9, over HTTP) is green, and so is its throttle case: an attacker with **no** valid PIN
  of their own is locked out.
- **Roles y permisos (262/265):** owner-ROLE gated in `canAccess`/`mount`, and again in every public method
  (`toggle`, `grantDependency` — which only accepts listed dependencies — and `restoreDefaults`). It is gated once more
  in `SetRolePermission`/`RestoreRoleDefaults`. The OWNER row cannot be changed, and unknown permissions are refused.
- **Counter-only accounts (262):** the `Login` override still refuses inactive or role-less accounts.
  `CounterAwareLoginResponse` only honours a server-set `url.intended` whose path is `counter`/`counter/*`, so there is
  no open redirect.
- **Wallet tab (259/263):** `SpendFromWallet` locks the member row before reading the balance, and refuses a shortfall
  unless `on_tab` is set. `RecordWalletTransaction` enforces the member limit, the sede switch and the club cap
  server-side. `SetMemberDebtLimit` is the only writer (`forceFill`, never fillable) and is gated by
  `MemberPolicy::approveDebt`, reasoned and audited.
- **Handover confinement (254):** the HTTP-level tests are green, and the global hook is still in place.
- **Documents (261/262):** `op` is honoured only while it equals the session's operator. The `u` user binding and the
  policy org check are unchanged. The MIME whitelist sends `octet-stream` for anything else, with `nosniff`, so an
  upload cannot run as HTML/SVG in the new same-origin viewer iframe.
- **The supervisor/handover PIN properties are cleared** before use (`authoriserPin`, `handoverPin`). The Sentry
  scrubber matches `*pin*` keys, and `max_request_body_size` is `none`.
- **Fundamentals:** routes are unchanged since 239 (no new parameters; every one addressed by ULID or token).
  `composer audit` and `npm audit` are clean (the 242 bump holds). `robots.txt` disallows everything, `X-Robots-Tag` is
  sent globally, and both layouts carry a noindex meta, so there is no public surface; this also covers the SEO
  question. `/dev/*` is local-only. There is no `$guarded = []`, and `.env` is untracked. The magic-link and
  member-guard code is unchanged since the August pass.

---

## OWNER / OPS tasks (not defects)

- **`APP_ENV=production`** in the production `.env`. `.env.example` ships `APP_ENV=local`, and HSTS, the `/dev/mail`
  gate and `DevAdminSeeder` all key off it. Also `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` (the example already
  has both).
- **Set `TRUSTED_PROXIES` to the load balancer's addresses**, not `*`. With `*`, a client can spoof `X-Forwarded-For`,
  which weakens every IP-keyed throttle (member login, application form) and the IP written to `DocumentAccessLog`.
- **Once the PIN fixes land, have every person set a fresh, distinct PIN.** Existing duplicates cannot be detected
  from the hashes without the plaintext.
- **Tablet kiosk / guided-access mode** (carried forward). It is still the only answer to bfcache repaint on a handed-over
  or locked tablet.
- **Documents bucket:** a private bucket policy and `AWS_DOCUMENTS_SSE` (empty in `.env.example`; `aws:kms` recommended
  for Article-9 data).
- **Still open from earlier reports:** Cloudflare SSL mode and WAF; the Sentry DSN (the scrubber is ready); legal copy
  for the privacy policy and statutes; queue worker and scheduler monitored as must-run services; `composer install` on
  the server for the 242 dependency bump.

---

## Discussion — documented decisions this report touches

- **Prompt 120: "a correct PIN clears everything."** It was a fat-finger convenience, decided when a PIN only named
  who did a transaction. After 267 it is the reset button for a brute-force attack on a full login (Phase 1 above).
  The suggested fix keeps the spirit: a fat finger still costs only the 300 s attempt window. But it does change the
  documented behaviour, so it is the owner's call.
- **Prompt 124: the throttle fails OPEN on a cache outage.** With `CACHE_STORE=redis`, a Redis outage removes the only
  brute-force control on what is now a sign-in credential. Not recommending a reversal (never 503 the counter is a firm
  rule). One option to weigh: keep the throttle's keys on the `database` store, as `PERMISSION_CACHE_STORE` already
  does, so a Redis blip does not open it.
- **Prompt 262: staff may view ID scans, and SENSITIVE grants are warned, not blocked.** Both are the owner's decisions
  and neither is challenged. The Phase 1 `staff.manage` item asks only that the warning describe the grant accurately,
  and that assigning the OWNER role stay with owners — consistent with 262's own reasoning.
- Unchanged and not re-litigated: `PERMISSION_CACHE_STORE=database`, the panic lockdown's ordinary-looking 503,
  `FILESYSTEM_DISK=local` being inert, and the receipt wording.
