# SETUP — step by step, cold start to live

Follow this in order. Read `README.md` and `NOTES-decisions-and-compliance.md` first.

---

## Step 0 — Before you touch a keyboard

**On your Mac**
- PHP 8.x, Composer, Node + npm, Git, and Claude Code installed and logged in.
- **Laravel Valet** working, with `~/Sites` parked (`cd ~/Sites && valet park`). Redis running
  (`brew services start redis`) — Horizon needs it. SQLite is fine for local dev; MySQL for staging.
- The starter kit at **`/Sites/starter-kit`**.
- A new empty GitHub repo for the project.

**Accounts** (stub for early dev, but have them before the feature that needs them)
- **Resend** — API key **and** a verified sending domain with DKIM. Needed by prompt 04, when members
  get emailed their QR card; without the domain it won't deliver properly.
- **AWS S3** — two buckets or two prefixes: one general, one **private** for ID documents, member
  photos, signed documents and receipts. Local disk is fine for dev.
- **Ploi** (or equivalent) with MySQL + Redis for staging/prod. Optional: Sentry DSN.

**From the client — this is the real blocker.** Prompt 01 pauses until you have the answers in
**NOTES section C**: association identity, the premises list, the owners, the limits (age, carencia,
daily/monthly grams), the aforo per premises, the wallet/debt policy, the pricing model, the avalador
rule, whether there's a bar, languages, and the retention period. Also get their **existing member
list as a CSV** if you're importing on day one, and confirm whether the club **cultivates or acquires**.

---

## Step 1 — Create the project

```bash
cd ~/Sites
laravel new csc-platform      # or: composer create-project laravel/laravel csc-platform
cd csc-platform
git init && git add -A && git commit -m "chore: initial Laravel skeleton"
git remote add origin git@github.com:you/csc-platform.git
git push -u origin main
```

Drop this prompt folder in at **`prompts/`**, commit, and push.

```bash
cp -R /path/to/csc-prompts ~/Sites/csc-platform/prompts
git add prompts && git commit -m "docs: add build prompts" && git push
```

Confirm the site resolves: `ping -c 2 csc-platform.test` should answer on `127.0.0.1`. If not,
`valet install` and try again.

---

## Step 2 — Bootstrap (Phase A)

Point Claude Code at the kit's `bootstrap.md`, choose the **Full app** profile, and give it
`prompts/00-bootstrap-brief.md` as the project brief.

> "Run the bootstrap at `/Sites/starter-kit/bootstrap.md` exactly as written. Use the **Full app**
> profile. The project brief is `@prompts/00-bootstrap-brief.md` — follow it, including the no-payment-
> provider and no-public-site differences. Stop when the bootstrap finish conditions are met."

**Verify before moving on — do not skip this:**
- `composer check` green
- Horizon runs; `/dev/mail` works
- `CLAUDE.md` reads right: euros in cents, weight in centigrams, org + location scope, **panel at
  `/`**, no public site, Spanish default locale, blue/white
- You can log in at `http://csc-platform.test/` as **`owner@club.test` / `password`**

**If login fails**, it's almost always one of these three, in this order:
1. `SESSION_SECURE_COOKIE=true` in `.env` while you're on `http://` — the session cookie is never
   sent, so login silently bounces back to the form with no error. Set it to `false` locally.
2. Stale config cache — `php artisan config:clear` after any `.env` change.
3. The panel gate — the user needs a role assigned and `email_verified_at` set. Check with:
   ```bash
   php artisan tinker --execute='$u = App\Models\User::where("email","owner@club.test")->first(); dump(["pw" => Hash::check("password", $u->password), "verified" => $u->email_verified_at, "roles" => $u->getRoleNames()->toArray()]);'
   ```

Then **stop**. First feature is prompt 01.

---

## Step 3 — Run the feature prompts, one at a time (Phase B)

**The loop, for every prompt 01 → 17:**

1. Branch off fresh main:
   ```bash
   git checkout main && git pull && git checkout -b feat/<name-from-the-prompt>
   ```
2. Tell Claude Code, one prompt only:
   > "Read `CLAUDE.md` and `DECISIONS.md`, then complete the task in `@prompts/01-schema-and-scope.md`
   > exactly. Ship the tests, run `composer check`, and stop before merging."
3. **Answer the checkpoint** if there is one. Prompt 01 has the big one — this is where the client's
   answers from NOTES section C go in. Don't wave it through; everything sits on it.
4. Review the diff and the tests **yourself**. Run `composer check`. Click around locally.
5. Merge to `main` on GitHub. Then start the next prompt.

**Order: 01 → 02 → … → 17, strictly.** Never two at once, never concatenated. Prompt 18 is a menu —
only if asked.

Three placements feel early and are deliberate: **03 settings** before any feature reads a threshold,
**06 limits** before anything displays or enforces them, and **10 till sessions** before either POS,
because a POS that can't attach to an open drawer can't commit. Don't reorder to "do the POS first" —
it won't build.

Effort guide: **01 is the one to get right** — it defines the entire schema, and a later prompt
needing a missing column means coming back and fixing it there, not scattering migrations across the
build. 02–08 are foundation. 09–13 are the operational core. **14 is the one to budget real time
for** — it's the screen everyone judges the product by. 15–17 are what make it finished rather than
merely working.

---

## Step 4 — Quality passes (Phase C)

Once features are in and stable, each on its own branch, merging between each:

`accessibility-audit` → `ui-passes` 01 → 02 → 03 → 04 → `admin-audit` → `design-audit` →
`code-style-audit` → `security-audit`

**Skip `seo-audit`** — there is no public site, by design.

---

## Step 5 — Launch gate (Phase D)

Run `completeness-check` (skip `cms-field-usage-check` — there is no CMS in this build), fix what it
flags, then `pre-staging-gate` until it's a clean GO. Then the human `verification/CHECKLIST.md` by
hand.

**Tailor the checklist to this domain.** Walk, for real:

- A member **application** → approve → carencia set → QR email arrives in a real inbox
- **Check in** by QR scan; the photo shows; the aforo counter increments
- Try to check in a **lapsed** member, an **under-age** member, and a member **in carencia** — each
  blocked, each with the right message
- A **dispensation**: 3.50 g at €10/g → €35,00, stock down 350 cg, gauge updates, receipt says
  *aportación*
- Push a member **over their daily limit** — blocked; then a manager **override** — permitted and
  logged (check the audit log)
- A **wallet top-up** in cash, and a dispensation paid from the wallet
- A **bar sale**, member-less, with a reference
- A **void** of a dispensation — stock returns to the same batch, grams released, money reversed
- A **till petty-cash** expense showing in the cash-up variance; an **owner overhead** that does not
- **Close the till**: blind count, variance revealed only after submitting, note required over tolerance
- The **dashboard** at `/`: every stat card, the period toggle, the alerts panel, dark mode
- Export a **report**; generate the **libro de socios** and one **acta**
- Install the **PWA** on a phone, show the QR card offline, scan it at the door
- Attempt to reach a member route while logged out, and another member's data while logged in — both refused

---

## Step 6 — Deploy

Point Ploi at MySQL + Redis. Set every env var: Resend, both S3 disks (the private one **must not**
be public-read), `APP_URL`, `APP_LOCALE=es`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (correct
in production, over HTTPS), Sentry. Run migrations. **Do not run the dev seeder in production.**

**Three silent killers — wire these as monitored services:**
1. **Queue worker / Horizon** — QR emails, push notifications and audit writes queue through it.
2. **Scheduler cron** (`schedule:run`) — membership expiry sweeps, auto-checkout, recurring
   overheads, retention purge. If it isn't running, these fail quietly and nobody notices for weeks.
3. **Backups** — automated daily, and **actually restore one** before go-live. An untested backup is
   a rumour.

**Go-live data.** A club switching from an incumbent needs more than a member CSV. Plan for, in this
order: members (prompt 04's import), then **opening stock** as INTAKE movements per batch, then
**opening wallet balances** as ADJUSTMENT transactions with a reason (never free-typed — the ledger
is the truth), then an **opening till float**. Reconcile each against the old system's closing
figures before taking the first real transaction, and keep the old system readable for a month.

Then, before real member data goes in: confirm the club's lawyer has seen how the system records
consumption, limits and the member register, and that the retention period and consent text are
theirs, not the defaults.

---

## Watch-outs

- Don't concatenate prompts or let one run roll into the next. Review and merge between each, every time.
- Run the suite against **MySQL** too before staging, not just SQLite — they diverge.
- Money is integer cents; weight is integer centigrams. A float in either is a bug, and it will be
  a bug you find in a cash-up at 11pm.
- The prompt-01 checkpoint is the moment to lock scope, identifiers and the money/weight wiring. Get
  it right there and the rest follows cleanly.
- Every threshold is a setting. If you find yourself hardcoding 3.5, 100, 15 or 18 anywhere outside
  the seeder, stop.
