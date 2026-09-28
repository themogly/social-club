# Security & privacy audit: post-296 round (2026-09)

**Range:** `2d98aed..1dcc453` (prompts 270–296; the pre-live round covered everything up to `2d98aed`).
**Date:** 2026-09-28. **Branch:** `audit/post-296`.

**Method:** report only; no production code changed. The work was split three ways:
- the counter access slice (tablets, PIN, sessions, mail), in Appendix A;
- the admin panel and data slice, in Appendix B;
- a probe of the 293 islands, written up below.

Every "proven" item was shown by a throwaway test that drove the real Livewire update endpoint through the HTTP kernel (or
Filament's test helpers). All probes were then deleted.

**Test runs:** `tests/Feature/Security` 118 passed; SecurityHeaders / MemberPwa / RgpdCompleteness 25 passed.
`composer audit` found no advisories. `npm audit --omit=dev` found 0 vulnerabilities. Nothing was run against MySQL
(that is CI's job, and `gh` is not installed here, so CI on `1dcc453` still needs checking on GitHub).

---

## Triage — fix in this order

### Phase 1 — exploitable / data exposure

| # | finding | proven | fix |
|---|---|---|---|
| 1 | **Non-owner with more than one location has no location scope.** It covers every sede, including Article-9 dispensation lists, repricing and moving another sede's stock. Prompt 277's Almacén made this common. (B · P1-1) | yes | Default a non-owner to a real location, or scope them to their assigned ones. `SetBatchPrice` and `TransferBatch` check the actor's sede. Denial tests. |
| 2 | **Bulk delete/restore skip the per-record policy.** A manager can delete their own sede; a manager with `staff.manage` can delete the OWNER, which reopens pre-live A4. (B · P1-2) | yes | `authorizeIndividualRecords()` on every bulk delete/restore; `deleteAny`/`restoreAny` in the policies; consider `strictAuthorization()`. |
| 3 | **A tablet with nobody at the PIN still sends member data.** `WhosInside` sends the names inside (and its `locationId` is not locked, so another sede's). `selectMember`/`submitLookup` render the member card. The till shows cash figures. (A · 1) | yes | Lock `locationId`; nothing member-related before an operator; `requireOperator()` on lookup and select. |
| 4 | **The 293 islands expose a socio's dispensing history with nobody at the PIN.** `islandView()` / `islandChanged()` are public, so the browser can call them, breaking prompt 260's rule. The island data builds *Su habitual* directly, skipping 260's `theirUsual()` operator gate. (See below.) | yes | Make both methods `protected` (260 proved a view can still call them); gate *Su habitual* on an operator; add a denial test over HTTP. |
| 5 | **A revoked tablet stays signed in** as whoever last used a PIN, even after a lock. The revoke dialog and the manual promise otherwise. (A · 2) | yes | Keep the terminal id in the session at PIN sign-in; log out when it is revoked or no longer matches. |
| 6 | **"PIN already taken" reveals PINs.** With `staff.manage` (owner-grantable, 262), a manager can find the owner's PIN and act as owner at the counter. (A · 3) *An earlier note of mine dismissed this as owner-only; that was wrong.* | yes | Server-generated PINs, or throttle and audit the check. |
| 7 | **Owner decision:** a registered tablet plus a PIN opens the whole panel with no password and no MFA. (A · 4) | yes | Ask for the password (and MFA) the first time a PIN session opens a panel page. |

### Phase 2 — privacy / GDPR
- **Failed mail jobs keep personal data indefinitely** (receipts with grams, addresses, tokens) in `failed_jobs`. Fix: schedule `queue:prune-failed` and have erasure reach it. (A · 5)
- **Staff clock events are kept forever.** RAT-08 says "4 años como mínimo" with no end. Fix: set a maximum, and add an audited purge or anonymisation. (B · P2-1)

### Phase 3 — hardening
- **The Precio "below cost" confirmation trusts client-editable arguments** and can reprice a different batch. It is bounded by `prices.manage` and the location scope (so it is unbounded under #1). Fix: take the batch from the parent action's record. (B · P3-1)
- **Staff-hours helpers ignore `organisation_id`**, breaking the multi-org keying rule. (B · P3-2)
- **Registro de jornada's public `reportRows()` returns raw models**, including staff e-mails and addresses. Fix: make it protected, or return plain arrays. (B · P3-3)
- **A manager can add or annul their own hours.** It is audited, but nobody approves it. (B · P3-4)
- **The PIN lockout counter is read-then-write**, so parallel requests get extra attempts. Found by reading the code, not proven. (A · 6)
- **`/csp-report` starts a session per report**, and its per-IP throttle is bypassable with `TRUSTED_PROXIES=*`. (A · 7)
- **No rate limit on sending or resending invitations.** (A · 8)

**Owner / ops:** #7 above is a decision for the owner, not a defect. Also: `TRUSTED_PROXIES` must not be `*` in production
(it defeats every per-IP throttle), and switch the CSP to enforce once its log is clean.

---

## The 293 islands finding (probed in this session)

**Setup.** A staff user is signed in on the device, and the dispensary is loaded with an operator. Then the operator is cleared
(`CounterOperator::clear()`: the locked / unidentified state), and a raw update is posted to the real Livewire endpoint:
`updates: {memberId: <a socio's id>}` and `calls: [islandView('genetics'), islandView('header')]`.

**Result.**
- HTTP 200.
- `returns` carried the catalogue rows priced for that socio.
- `returns` also carried the socio's `usual` genetics, i.e. what they have been dispensed. Before 293, prompt 260 withheld
  this when nobody is at the PIN (`theirUsual()` returns `[]` without an operator).
- The same request with an operator also answered `islandChanged()`. That is harmless in itself, but it is a public method
  that exists only to feed the view.

**Cause.** `RendersIslandsOnChange::islandView()` / `islandChanged()` are `public`. `DispensaryPos::catalogueData()` calls
`usualGenetics()` directly rather than through the operator-gated `theirUsual()`. It is a regression introduced by prompt
293 (mine).

**Not a finding.** The socio's name in the locked render is intended: the basket survives a lock (198), and the opaque
surface covers it. #3 covers the case where no socio was ever legitimately selected.

---

# Appendix A — counter access slice

# Security slice: counter tablets, PIN, session, CSP, mail (prompts 270–296)

**Range:** `2d98aed..1dcc453`, branch `audit/post-296`. **REPORT ONLY.** No production file was changed.

**Method:** I read the code for every file in the slice, checked it against `audits/reports/2026-09-pre-live-security.md` and the DECISIONS entries for 267, 270, 286, 289 and 290, and **proved every Phase 1 item by request**. The probes were five throwaway tests in `tests/Feature/ZzAuditProbe/Counter/`, using the real Livewire update endpoint through `PostsLivewireOverHttp`. They all ran and passed, and the directory has been deleted. Nothing ran against MySQL.

Counts: **Phase 1: 4** (one of them is an owner decision), **Phase 2: 1**, **Phase 3: 3**.

---

## PHASE 1: exploitable / data exposure

### 1. On a registered tablet, the lock surface only hides data on screen. Member data still reaches a browser with nobody signed in

After 289, a tablet with only the `csc_terminal` cookie mounts every counter screen. DECISIONS 289 says: "Nothing member-related before a PIN. Proven screen by screen". That is wrong. Its test (`test_with_no_operator_no_screen_sends_member_data`) uses a member who is **not checked in** and never selects one, so it misses three reads:

- **a. Recepción's "Dentro ahora" list.** `WhosInside` renders the full names of everyone checked in, and polls every 15 s, with no operator check (`app/Livewire/Counter/WhosInside.php:79-99`; the view prints `fullName()`). The host screen renders it whenever there is a sede, operator or not (`check-in-screen.blade.php:247`).
  - **Proven:** with the terminal cookie only (`Auth::check()` false, the surface is `unidentified`), `GET /counter/checkin` contains the checked-in member's name.
- **b. The same list, for another sede.** `WhosInside::$locationId` is **not `#[Locked]`** (`WhosInside.php:28`), unlike every other counter component (prompt 75). `resolveLocation()` finds any location in the organisation.
  - **Proven:** a tablet registered at Centro posted `updates: {locationId: <Norte>}` to the update endpoint, and the response listed the names of people inside **Norte**.
  - This also works for any signed-in device user; that part predates this range.
- **c. The member card, by id or by scanned card.** `FindsMembers::selectMember()` and `submitLookup()` (`app/Livewire/Counter/Concerns/FindsMembers.php:66,113`) have no `hasOperator()` guard. Prompt 260 added one only to `lookupResults()`.
  - **Proven:** with the terminal cookie only, calling `selectMember(<member ULID>)` rendered that member's name and number. This happened on `/counter/checkin`, `/counter/pos` and `/counter/members`. Those screens also show the wallet balance, the sanction, the door verdict and the limits, and Socios shows age.
  - Getting the ULID is the hard part. The realistic path is `submitLookup` with a member's QR card scanned on a locked tablet.
- **Lesser, same cause: the till screen.** It shows the opener's name, the float, every cash line and **"Efectivo esperado en el cajón"** before a PIN (`till-session.blade.php:~355-425`).
  - **Proven:** the float appeared in `GET /counter/till` with the terminal cookie only.

**Why it matters.** Anyone who picks up a locked tablet (a member, a visitor, a thief) can read who is at the club right now, at any sede, with no credentials. For a cannabis club, the fact that someone attended is itself sensitive, and the member card carries consumption limits. 289 turned "a locked device that still has a login" into "a tablet with no login at all", so every such read now needs no credentials.

**Fix:**
- Mark `WhosInside::$locationId` `#[Locked]`.
- Render `<livewire:counter.whos-inside>` only when there is an operator (`@if ($this->hasOperator())`). Also return an empty list from its `render()` when `CounterOperator::id()` is null, because it is a separate component with its own snapshot.
- Guard `selectMember()` and `submitLookup()` with `requireOperator()`. Make `resolveMember()` return null with no operator, so a `memberId` already set before a lock is not re-rendered.
- Hide the till summary block until there is an operator.
- Extend the 289 test: a checked-in member, a `selectMember` call over HTTP, and a `locationId` update.

### 2. Revoking a stolen tablet does not end the session signed in on it, and after revocation the idle lock stops signing people out

`RevokeCounterTerminal` only stamps `revoked_at`. `RecogniseCounterTerminal` then clears the cookie, but a PIN sign-in (267) is an ordinary Laravel login. Nothing ties that session to the terminal, so it survives. In addition, `lockCounter()` signs the person out only `if (CounterTerminals::current() !== null …)` (`IdentifiesOperator.php:254`). Once the terminal is revoked, the tablet counts as an "ordinary browser" and the lock **keeps** the login.

**Proven:** a manager signs in by PIN on a registered tablet, and the owner revokes the tablet. After that, `GET /counter/till` still returns 200, `Auth::id()` is still the manager, and `lockCounter` leaves the manager signed in.

The panel's confirmation text says "Su próxima petición irá al inicio de sesión normal" (`app/Filament/Pages/CounterTerminals.php:74`), and the Manual's lockdown runbook tells the club to revoke a stolen tablet. Both are false whenever someone was signed in when the tablet was taken. The session is sliding, 120 min (`SESSION_LIFETIME`), so a thief who keeps using it keeps it.

**Why it matters.** This is the incident the feature's own runbook addresses, and the documented remedy does not work. The thief keeps the last PIN person's full login, which is the panel too if that person was a manager or the owner.

**Fix:**
- In `SignInOperator`, store `counter.terminal_id` in the session when `CounterTerminals::current()` is set.
- In `RecogniseCounterTerminal`, when the session carries a `counter.terminal_id` whose terminal is revoked, missing, or no longer the cookie's, log out and invalidate. This check runs on the web group and the panel stack, and Livewire updates go through the web group.
- Test it over HTTP: sign in by PIN, revoke, then the next request lands on the login.

### 3. The "PIN already taken" check tells anyone holding `staff.manage` which PINs exist, so they can find the owner's

`UserForm`'s PIN rule calls `User::pinIsTaken()` with no throttle (`app/Filament/Resources/Users/Schemas/UserForm.php:96`), and the check is organisation-wide, owners included. If the same form also carries an invalid email, the create fails, so nothing is written. The error then appears only when the candidate PIN belongs to someone.

**Proven:** a MANAGER granted `staff.manage` has `can('update', $owner) === false` (270's `mayTouch`). They submitted `CreateUser` five times with `email: 'not-an-email'` and candidate PINs `0000, 1234, 4826, 4827, 4828`. The PIN error appeared only for `4827`, the owner's PIN, and the user count did not change. The full 10⁴ space is about 10,000 Livewire calls, with no throttle or audit. The pad then signs in as whoever owns each taken PIN (267).

The previous report's reasoning, "saying choose another leaks nothing new", assumed the admin already controls every PIN. Since 270 they do not control the owner's.

**Why it matters.** It rebuilds the `staff.manage` → owner escalation that 270 closed. The precondition is that the owner granted a SENSITIVE permission, which is not a default, so the severity is conditional. But the grant's warning does not say this is possible.

**Fix (pick one):**
- **Preferred:** the server **generates** the PIN ("Generar PIN nuevo", shown once, retried on collision). An admin never chooses one, so there is nothing to ask about.
- Alternatively, rate-limit and audit the uniqueness failure per actor (for example 5 per hour, then refuse), and skip the check entirely when the form has other errors. That makes it a slow oracle, not a closed one.

### 4. Owner decision: a registered tablet plus a 4–8 digit PIN opens the panel without a password or MFA

289's premise is: "the tablet is what you have, the PIN what you know". Since 267 the PIN is a full login, so on a registered tablet a PIN alone reaches the whole panel. That includes an account with TOTP enrolled, because `SignInOperator` calls `Auth::login()` and never goes through Filament's MFA challenge.

**Proven:** the owner has `mfa_secret` set. With the terminal cookie and PIN `1111`, `GET /users` returns 200 and `GET /roles-y-permisos` returns 200.

The PIN is typed in front of other people at a counter, so shoulder-surfing is realistic. The prize is the Article-9 register, erasure and roles. This follows from two accepted decisions (267 and 289), so it is the owner's call, not a defect. But MFA is the one control the owner can switch on, and here it silently does not apply.

**Suggested fix:** keep the counter PIN-only. When a session was established by PIN (a session flag set in `SignInOperator`), the first panel page asks for step-up: the password, plus TOTP when enrolled. Mark the session as stepped-up afterwards. The idle lock already clears it on a terminal.

---

## PHASE 2: privacy / GDPR

- **Failed mail jobs keep personal data indefinitely.**
  - 288 routes every lost email to `failed_jobs` after 4 tries. Those payloads carry plaintext constructor values:
    - `DispensationReceiptMail` holds name, date, **grams** and total (consumption data, Article 9);
    - `ApplicationRejectedMail` holds name and reason;
    - `ConvocatoriaMail` holds name;
    - the recipient address;
    - raw tokens: `MemberCardMail`, `LockdownReactivationMail`, and the invite URL.
  - Nothing prunes `failed_jobs`. `routes/console.php` has no `queue:prune-failed`, and Horizon's 7-day trim covers only its Redis copy. RGPD erasure does not reach the table.
  - `ClubMail::failed()` itself is clean: the audit row stores only the mailable class and the exception class.
  - → Schedule `queue:prune-failed --hours=168`, or shorter. Add `failed_jobs` to the RAT/retention note.
  - → **Why it matters:** a copy of consumption data sits outside every retention and erasure path.

---

## PHASE 3: hardening

- **The PIN throttle is read-then-write, so parallel requests exceed `max_attempts`** (code reading, not proven by request).
  - `isLockedOut()` (`UnlockOperator.php:~94`) and `registerFailure()`'s `Cache::get(...)+1; Cache::put(...)` (`:178`) are not atomic. Laravel does not block concurrent requests on one session.
  - A burst of N parallel `unlockOperator` posts from a registered tablet all pass the lockout check before any failure is written, so each window allows about N guesses (N = PHP-FPM workers) instead of 5–10.
  - → Serialise PIN checks per sede with `Cache::lock('counter-pin:<sede>:check')->block(3, …)`. Or increment first with `Cache::increment` and refuse when the result is over the limit.

- **`/csp-report` runs inside the `web` group, and its throttle is keyed on a spoofable IP.**
  - `routes/web.php:31` is loaded under `web`, so every report starts a session. **Proven:** the response sets `csc-platform-session`, which means a `sessions` row with `SESSION_DRIVER=database`. The controller's docblock says "no session", which is wrong.
  - `throttle:60,1` is per IP. With the default `TRUSTED_PROXIES=*`, **proven:** rotating `X-Forwarded-For` got 130 of 130 reports accepted from one client.
  - The logged fields are bounded (path only, 300/100 chars), so the exposure is log and DB volume, not content.
  - → Register the route outside the `web` group (a separate route file with no middleware group, keeping `throttle`), and fix the docblock. The `TRUSTED_PROXIES` ops item from the last report covers the IP part.

- **No rate limit on sending or resending invitations.**
  - Counter `sendAltaInvitation`/`resendAltaInvitation` (`SignsUpMembers.php:~645-690`) and the panel's Reenviar are correctly gated (operator + `applications.review` + sede) and audited. But nothing limits how often they run.
  - Anyone holding `applications.review` (STAFF by default, 174) can send club-branded mail to any address in a loop, or flood one applicant. That burns the Resend sender reputation.
  - → Add a per-application cooldown on Reenviar (for example 1 per 10 min, 5 per day) and a per-operator hourly cap on new emailed invites.

---

## Verified OK (no action)

- **Terminal cookie.**
  - Encrypted by `EncryptCookies` (not excluded), HttpOnly, Secure (except local plain-http), SameSite=Lax.
  - The token has 64 random characters. Only its SHA-256 is stored, compared with `hash_equals`, and hidden on the model.
  - A tampered, unknown or revoked cookie is cleared and ignored. Forging one needs APP_KEY.
  - Renewed hourly. No IP stored.
- **Registration.**
  - Needs a PIN operator with `terminals.manage`, **plus that operator's own PIN again** through the shared sede throttle.
  - The sede must be in `LocationSwitcher::available()` (a manager gets only their own sedes, never the store), and it is re-checked in the Action.
  - `terminalLocationId` is not locked, but it is validated server-side.
  - Audited. The name is limited to 40 characters and escaped everywhere it is shown.
  - Revocation has the same gate. The panel page lists and revokes only reachable sedes (proven by the existing test).
- **What a terminal alone reaches.**
  - The six counter screens and `/counter.webmanifest`, which exposes only the trading name.
  - Receipts, photo, panic and the sede switch still need `auth`.
  - Panel pages redirect to the counter, and panel Livewire calls are refused by Filament's persistent `Authenticate`.
  - `AuthenticateCounter` is Livewire-persistent, so updates face the same gate.
  - Writes all go through `requireOperator()`/`userCan()`; the gaps are the reads in finding 1.
  - Lockdown gives a terminal the 503 page (drill included).
  - The sede and organisation come from the terminal. A stale `counter.location_id` from another sede in the session is overridden by `availableSedes()`.
- **PIN sign-in from a terminal-only session.**
  - `UnlockOperator` checks only the terminal sede's active users.
  - `SignInOperator` migrates the session id (no fixation), sets `CounterOperator` in the same step (270), forgets the remember cookie, drops the panel sede, and is audited.
  - The resulting scope is that person's own (see finding 4 for the panel reach).
- **PIN lookup and throttle.**
  - The throttle bucket is keyed on the component's `#[Locked]` sede, so an attacker cannot rotate it.
  - A correct PIN no longer clears it (270 holds; `PinIsASignInTest` is green).
  - Two matches refuse and audit.
  - The HMAC key is HKDF of APP_KEY with a fixed context. No bcrypt copy is stored beside it, and the legacy scan runs only on a miss and empties over time. There is a timing difference between lookup and legacy scan, but it reveals nothing that a successful sign-in does not.
  - `pin`/`pin_lookup` are hidden, and `#[SensitiveParameter]` is used throughout.
- **`EndInactiveSessions`.** On the web group before the counter guards. It signs out an inactive user and drops a stale operator id; JSON requests get 401.
- **`SetDisplayTimezone`.** Display only, fails closed to the app timezone.
- **`LocationSwitcher` (284).**
  - `returnUrl` is `#[Locked]`. The browser's URL can change only the query string of the same page.
  - `PanelReturnUrl::after()` requires the same scheme, host and port, refuses `//` and `\`, and accepts only routes named `filament.admin.*`.
  - Record pages are re-resolved under the new scope and fall back to the list.
  - There is no open redirect and no cross-sede record reach; the switch itself is validated by `Switcher::switch`.
- **Invites.** One sender. It refuses anything but an outstanding invite with an email. The audit records `queued` only, never the address or token. The counter's resend is sede-bound.
- **`csc:mail-test`.** CLI only, records nothing.
- **Manifest.** Behind `AuthenticateCounter`, no service worker, no member data.

---

## Notes for the fixer

- The existing 289 test missed finding 1 because its fixture had nobody checked in and never selected a member. Please pin the fix with those two states over HTTP.
- Findings 1c and 1b partly predate this range: `selectMember` has had no guard since before 260, and `WhosInside` has never been locked. 289 is what made them reachable with no credentials.


---

# Appendix B — admin panel and data slice

# Security & privacy audit: admin panel and data (prompts 270–296)

**Range:** `2d98aed..1dcc453` (branch `audit/post-296`). **Date:** 2026-09-28. **Method:** report only. I changed no
production code. Every finding marked "Proven" was shown by a throwaway probe under `tests/Feature/ZzAuditProbe/Panel/`
(Livewire::test with the Filament helpers, or HTTP). All probes were run on SQLite and then deleted.

**Counts:** Phase 1: 2 · Phase 2: 1 · Phase 3: 4.

---

## PHASE 1: exploitable / data exposure

### P1-1. A non-owner assigned to more than one location starts every session with NO location scope, so they see and can act on every sede

`LocationScope::apply()` (`app/Models/Scopes/LocationScope.php`) adds no filter when the active location is null. That
null state is meant only for the owner's rollup (`LocationSwitcher::canAccess(null)` is owner-only). But the default
does not respect that:

- `LocationSwitcher::defaultLocationId()` (`app/Support/LocationSwitcher.php:54-63`) returns null whenever the user can
  reach more than one location.
- `App\Livewire\LocationSwitcher::mount()` (lines 38-43) then leaves `scope.location_id` null.
- `SignInOperator` also forgets the key on every PIN sign-in.

The null state is not new. Since 148 a manager assigned to **two sedes** has started unscoped. Prompt 277, however,
changed `available()` to count the **Almacén** (`includeStores: true`, lines 60 and 72). So the configuration 277
itself envisages — a manager assigned to their sede plus the grow store — now falls into it too.

**Proven** with a default MANAGER assigned to Sede A + Almacén (and separately to Sede A + Sede A2), while Sede B exists:

- `GET /batches` lists Sede B's batches.
- `GET /dispensations` lists Sede B's dispensations, including the member's name and the reference. That is Article-9
  consumption data from a sede they do not work at. (The single-row view `/dispensations/{id}` is 403, because
  `DispensationPolicy::view` checks the assignment. The list does not.)
- From the batches table, the manager **repriced Sede B's batch** (`price` → 9900) and **moved all of Sede B's stock into
  Sede A** (`transfer`, `all`). Neither `SetBatchPrice` nor `TransferBatch` checks that the actor is assigned to the
  **source** batch's location. Both rely entirely on the global scope.

Every per-location Filament resource inherits this, because they all rely on `LocationScope`. That covers batches,
dispensations, orders, till sessions, expenses and so on. Managers cannot switch back to "all", which shows the rollup
was never meant for them.

→ **Fix:**

1. Never leave a non-owner unscoped. When the active location is null and the user cannot switch to "all", make
   `LocationScope` constrain to `whereIn(location_id, $user->locations)`. Alternatively make `defaultLocationId()` pick
   their first assigned location (sedes before the store) for non-owners.
2. Give `SetBatchPrice`, `TransferBatch` (source) and `RecordStockMovement` callers from the panel an object check: the
   actor is an owner or is assigned to `$batch->location_id`.
3. Add denial tests: a manager with 2 assigned locations sees no third sede's batches or dispensations, and cannot
   price or transfer them.

→ **Why it matters:** CLAUDE.md requires every domain query to be scoped to organisation + active location, with
cross-location access "deliberate and permissioned". Here it is the default state of an ordinary multi-location
manager. It exposes Article-9 dispensation lists and lets them move another sede's stock without anyone noticing.

### P1-2. Filament bulk Delete/Restore bypass the per-record policy: a default MANAGER can delete their sede, and a `staff.manage` holder can delete the OWNER

Filament 5's `DeleteBulkAction` and `RestoreBulkAction` authorise with `deleteAny` / `restoreAny`. They do not call
`delete($record)` unless `authorizeIndividualRecords()` is set. **No policy in `app/Policies` defines
`deleteAny`/`restoreAny`**, and when a policy method is missing and strict mode is off, Filament's
`get_authorization_response()` **allows** the action (`vendor/filament/filament/src/helpers.php:62-93`). The panel does
not enable `strictAuthorization()`.

**Proven:**

- **Locations:** a **default MANAGER** (who has `settings.manage.location` but not `locations.manage`) bulk-deleted their
  own sede from `ListLocations`. `can('delete', $sede)` was **false**, yet the sede was **soft-deleted**.
  (`LocationsTable.php:45-46`)
- **Users:** a MANAGER holding `staff.manage` bulk-deleted the **OWNER** from `ListUsers`. `can('delete', $owner)` was
  **false** (the 270 `mayTouch` guard), yet the owner was **soft-deleted**, which locks them out.
  (`UsersTable.php:44-45`) This reopens the hole that pre-live finding A4 / prompt 270 closed for `delete`/`restore`. The
  same path also lets the holder delete their own account.

The same shape applies to every table with bulk delete/restore whose record-level rule is stricter than `viewAny`.
Example: `MembersTable.php:144-145`, where `MemberPolicy::delete` needs `members.edit` but `viewAny` needs only
`members.view`. Also Genetics, Articles, Batches, Discounts, Events, MembershipTiers, ExpenseCategories and
Announcements.

→ **Fix:** globally, in a service provider:
`DeleteBulkAction::configureUsing(fn ($a) => $a->authorizeIndividualRecords('delete'))`, and the same for
`RestoreBulkAction` (`'restore'`) and `ForceDeleteBulkAction` (`'forceDelete'`). Also add explicit
`deleteAny`/`restoreAny` methods to `UserPolicy` and `LocationPolicy` (`locations.manage` / owner). Consider
`->strictAuthorization()` on the panel, so that a missing policy method fails loudly instead of allowing. Add denial
tests for the two proven cases.

→ **Why it matters:** "Authorization on every endpoint, including object-ownership checks", and "write the denial
tests". A default manager taking a sede offline, or a delegated `staff.manage` locking out the owner, are both exactly
what the policies were written to stop.

---

## PHASE 2: privacy / GDPR

### P2-1. The registro de jornada is kept forever: no retention end, and the model cannot be purged

`StaffClockEvent` (281) is append-only: the builder refuses `delete()`, and `deleting` throws. Nothing ever removes
rows, and soft-deleting a user keeps them. RAT-08 (`app/ViewModels/Rat.php`) states "4 años como mínimo" with no end.
Spanish law requires at least 4 years (art. 34.9 ET). GDPR storage limitation requires an end.

→ **Fix:**

- Give RAT-08 a maximum, for example 4 years + 1 after the business date.
- Add a scheduled, idempotent purge (copy the `MaterialiseRecurringExpenses` shape) that removes, or anonymises the
  `user_id` of, events older than that. It needs an explicit, documented carve-out through the append-only builder,
  such as a raw `DB::table()` delete in one audited command.
- Add the table to whatever RGPD coverage test exists for staff data.

→ **Why it matters:** this is staff personal data, with correction reasons that can be free text. "Minimum" with no
maximum is not a retention period.

---

## PHASE 3: hardening

### P3-1. Batch `belowCost` confirmation trusts client-editable action arguments

`BatchesTable::priceAction()` mounts `belowCost` with `['batch' => id, 'rate', 'eighth']`
(`BatchesTable.php:252-256`). `belowCostAction()->action()` then prices whatever `$arguments['batch']` says
(`:282-284`). `mountedActions` is public, unlocked Livewire state.

**Proven:** after mounting the modal for batch X, `set('mountedActions.N.arguments.batch', Y)` + Continuar repriced
**batch Y** instead of X.

It is bounded by `SetBatchPrice` (`prices.manage`; negative prices refused — also proven) and by `LocationScope`: a
batch at another sede gave `ModelNotFound` for a single-sede manager. So on its own it grants no new power. Under P1-1
it reaches any sede.

→ **Fix:** resolve the batch from the parent action's record (`$action->getParentAction()?->getRecord()`), not from
the arguments. Re-derive `rate`/`eighth` server-side, or keep them in a server-side cache keyed by the mounted
action.

### P3-2. Owner-scoped staff-hours helpers ignore `organisation_id`

`WorkedHours::viewableLocationIds()` returns `Location::withoutGlobalScopes()->sedes()` for an owner — every sede in
**every** organisation (`app/Support/WorkedHours.php:177-178`). `canManageAt()` returns true for an owner at **any**
location (`:183-187`). `AnnulClockEvent` / `addOut` load events `withoutGlobalScopes()` by client-supplied ULID
(`RegistroJornada.php:217, 234`). This is harmless while there is one organisation, but it breaks the "keyed for
multi-org SaaS" rule and would expose other orgs' staff names and hours.

→ **Fix:** filter both helpers by `ActiveScope::organisationId()`, and check `$event->organisation_id` in
`AnnulClockEvent`/`ClockRules`.

### P3-3. Public Livewire methods on RegistroJornada return raw models, including staff e-mails

`reportRows()`, `peopleOptions()` and `sedeOptions()` are public (`RegistroJornada.php:121, 160, 168`), so the client
can call them, and Livewire returns their value as JSON.

**Proven:** `call('reportRows')` returned each `StaffClockEvent` with its loaded `recorder` (User: **email**,
`mfa_confirmed_at`, `active`) and `location` (address, hours). The data is limited to the viewer's sedes and
`User::$hidden` holds (no password, PIN, `pin_lookup` or MFA secret). But it exposes staff e-mails to anyone with
`staff.hours.view`, where the page itself shows only names.

→ **Fix:** make these methods protected (or `#[Computed]`), or map to plain arrays before returning.
`CounterTerminals::terminals()` has the same shape (`token_hash` is hidden, so it is lower risk).

### P3-4. A manager can correct their own registro de jornada

`addPeriod` offers the actor in `staffOptions()`. `ClockRules` accepts `MANAGER_CORRECTION` for any `$user` at a sede
the actor manages, including themselves. So a manager can add or annul their own hours. This is flagged in the report
("Corregido por <self>") and audited, but nobody else approves it.

→ **Fix:** refuse `$recordedBy->is($user)` for `MANAGER_CORRECTION` unless the actor is an owner. At minimum, surface
self-corrections as their own flag.

---

## Verified OK (no action)

- **RegistroJornada / Horas del personal:**
  - Both pages are gated on `staff.hours.view`.
  - Rows are limited to `WorkedHours::viewableLocationIds` (assigned sedes for non-owners). `personId` and
    `locationId` from the URL or client only narrow.
  - `StaffHoursReportPage::resolveLocationIds()` aborts with 403 for a scope outside what the viewer may see.
  - The chart widgets' `locationIds` is `#[Locked]` and intersected again in `StaffHours::for()`.
  - STAFF never see hours on the dashboard (`visibleOnDashboard`).
  - Corrections are gated per sede (`canManageAt`). `addPeriod`'s person and sede Selects are option-validated
    server-side by Filament.
  - Clock events are immutable (model events + `AppendOnlyBuilder`). A clock-out at the counter re-asks for the
    person's own PIN.
  - Pay and rates are not stored.
- **Descuentos y ajustes (291):** inherits ReportPage's scope rules (403 beyond assigned sedes; "all" needs
  `reports.view.all`). Every query is `whereIn(location_id, $ids)`. The operator and kind filters only narrow.
  RegistroDispensacion's change is formatting and a half-open window only.
- **SetBatchPrice:** checks `prices.manage` in the action and refuses negatives. `RenameBatchLote` renames every part of
  the lote across sedes by design, with one audit row.
- **Batch edit:** a tampered `data.location_id` / `data.genetic_id` on EditBatch did **not** save (proven). Filament 5
  ignores client updates to disabled fields.
- **WarnsBelowCost:** `belowCostConfirmed` is client-settable, but it is only a warning. The `below_cost` audit flag is
  recomputed server-side. `create()` re-validates and re-authorises.
- **Consumption limits (296):**
  - `ManageSettings` is gated on `settings.manage` in `canAccess`/`mount`/`save`, and `SetConsumptionLimits` checks
    `settings.manage` again.
  - No per-location setting key can write `consumption_limits_enabled` or the default limits (LocationForm key lists).
  - ManageEnforcement forces locked cells and keeps stored modes for switched-off rows.
- **Uploads (295):** `CameraOrFile` and `x-counter.file-field` are client-only wrappers. Every wrapped `FileUpload`
  keeps its disk, visibility, `DocumentUpload` size rule and encrypted private disk (the diff touches only the wrapper
  line). The public applicant form's server rules in `ApplicationShape` (`image|mimes:jpeg,jpg,png,webp`, and
  `file|mimes:…,pdf`, with `DocumentUpload::maxRule()`) are unchanged since `2d98aed`. Batch photos are on the public
  disk by prior decision (no personal data). They have no explicit `maxSize` (Livewire's 12 MB default applies) —
  minor.
- **Users (270):**
  - Single-record edit/delete/restore of an owner row is refused (`mayTouch`).
  - The OWNER role is filtered from the select and enforced by `EnsureRoleChangeIsAllowed` on create and save.
  - A non-owner's own roles are locked.
  - PIN uniqueness is enforced (`User::pinIsTaken`).
  - Hidden attributes cover `pin`, `pin_lookup` and the MFA secrets.
  - Bulk actions are the exception: see P1-2.
- **Retention jobs:** `PurgeExpiredMembers` / `RedactExpiredAuditLogs` now clamp to `MIN_RETENTION_DAYS = 365`, which
  is safer. No new member-linked table in the range. `RgpdCompletenessTest` is green.
- **Membership / wallet relation managers:** the location options narrowed to `Location::assignableOptions()`, which is
  an improvement.

## Test runs

- `php artisan test tests/Feature/Security`: **118 passed** (1144 assertions).
- `SecurityHeadersTest`, `MemberPwaTest`, `MemberPwaFormStyleTest`, `RgpdCompletenessTest`: **25 passed**
  (115 assertions).
- Probe directory `tests/Feature/ZzAuditProbe/Panel/` deleted; working tree clean.
