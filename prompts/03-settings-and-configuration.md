# 03 — Organisation & location settings

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md` and the kit's **`admin-design`** skill
(its singleton/settings-page section is directly relevant). Requires 01–02 merged.

> **This runs third, before any feature.** Every prompt from here on reads thresholds through the
> `Settings` accessor built in prompt 01. Building the settings surface last — which is the instinct —
> means a dozen prompts hardcode values and get retrofitted. Build it now, seed every default, and
> let the rest of the build consume it.

`git checkout main && git pull` → `git checkout -b feat/settings`.

> **Nothing in NOTES section A is a hardcoded constant.** Spanish practice varies by region and
> municipality and the case law moves; every threshold in this system is a setting with a sensible
> seeded default. This prompt is where they all live.

## Build

**Organisation settings** (owner only)
- Identity: association name, legal name, CIF/NIF, registered address, logo, contact.
- **Language**: default locale (`es`), enabled locales (English, optionally Catalan).
- **Currency display format** — default `€1.234,56`.
- **Terminology** — the member/contribution vocabulary is configurable per club (*socio* vs
  *asociado*, *aportación* vs *donación*), applied through the translation layer.
- **Member number format** — prefix/padding/sequence.
- **Compliance defaults** (per NOTES): minimum age (18), carencia days (15), daily gram limit
  (3.5 g), monthly gram ceiling (100 g), declared-forecast options (30/50/60/90 g), active-member
  soft cap (750), premises stock ceiling days (5), monthly window (calendar vs rolling 30 days).
- **Enforcement matrix** — **per rule AND per surface**: each check (age, membership, carencia,
  sanction, debt, unpaid fee, aforo, daily limit, monthly limit) is independently `BLOCK` / `WARN` /
  `OVERRIDE` **at the door** and **at the counter**. These genuinely differ — most clubs let a member
  with debt come in and sit down, but not take product. A single per-rule setting cannot express
  that, and getting it wrong is how prompts 12 and 11 end up contradicting each other.
- **Consumption gauge thresholds** — the neutral / warning / alert percentages (default 70 / 95).
- **Avalador policy** — required / manager-waivable / not required; therapeutic exemption; the
  maximum number a member may sponsor.
- **Wallet & debt** — debt allowed, default limit, the **door debt threshold** (the figure at which
  the door reacts, distinct from the hard limit), whether fees may be charged to the wallet.
- **Membership** — expiring-soon window (default 30 days), renewal-reminder lead days.
- **Stock** — default low-stock threshold, batch nearing-expiry window (default 30 days).
- **Discounts** — whether discounts stack (default no).
- **Till** — arqueo variance tolerance, blind-count enforced (yes), expense approval threshold.
- **Data retention** — period after `left_at` before purge; **audit-log retention (deliberately
  longer)**; **signed-URL TTL** for documents (default 5 minutes); consent text and its version.

**Per-location settings** (`settings.manage.location` — owner, or manager for their own)
- Name, address, **capacity (aforo)**, **timezone**, **business-day cutoff**, opening/closing time
  (drives auto-checkout), accent colour.
- **Aforo enforcement mode** — warn or block at capacity.
- Module toggles: bar on/off, signature-on-dispensation on/off, restrict-POS-to-checked-in-members
  on/off, camera scan on/off.
- Overrides for the compliance defaults where a premises genuinely runs differently — with the org
  value shown alongside so it's obvious what's being overridden.
- **Ring-fence toggle** (owner only), if the wallet model from prompt 07 uses it.

**Expense categories** — the org-wide list consumed by prompt 14, managed here.

**Active-member soft cap** — seed it *and consume it*: an alert when active members approach the
configured cap (surfaced on the dashboard in prompt 15). A setting nothing reads is a bug by this
prompt's own rule.

**Implementation (follow the kit's guidance exactly)**
- Build these as a **Resource in singleton style** (disable create, land on edit) or a **complete
  Filament settings Page** — `InteractsWithForms`, a real `form()` schema, `mount()` filling from
  stored values, and a save action that **persists, validates and fires a success notification**,
  registered correctly in navigation. A half-built custom page that doesn't load on mount or doesn't
  persist is the single most common source of "Filament UX bugs" — do not produce one.
- Group fields into sections in the order the owner thinks about them, with plain-language labels
  and help text on every threshold explaining what it does and what happens when it's hit. Never a
  flat wall of fields in migration order.

## Rules

- **Every setting is read through `Settings::get()` (prompt 01) — an accessor with a safe default**, never a raw property that
  can throw on a stale cache. This matters most in queued jobs (expiry sweeps, recurring overheads,
  auto-checkout) where a throw fails **silently** and kills the job.
- Settings changes are **audited** — who changed which value, from what, to what, when. Compliance
  thresholds especially.
- Changing a threshold is **not retroactive**: it affects future checks only, never already-committed
  transactions or issued documents.
- No secrets or credentials as editable settings fields.
- Every setting must be consumed somewhere. An orphan setting is a bug — check before adding.

## Tests (required)

- Every compliance default is honoured by the code that consumes it: change carencia to 30 and the
  block moves; change the daily limit and enforcement follows; change the aforo and the door reacts.
- A stale/missing setting falls back to its default rather than throwing — assert inside a queued job.
- Location overrides beat org defaults; where absent, org applies.
- Settings changes write audit entries.
- The settings page loads existing values on mount, validates, persists, and notifies (the four
  failure modes named in the admin-design skill — one test each).
- Locale switching renders the app in Spanish and English with no untranslated keys.

## Finish

`composer check` green. Push the branch; **do not merge**.
