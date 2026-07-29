# Cannabis Social Club platform — build prompts (Laravel / Filament)

A complete, production-grade management system for a Spanish **asociación cannábica** (cannabis
social club) operating from **multiple locations**. Members-only, non-profit, cash + wallet,
cannabis dispensed **by weight**, with the compliance record-keeping a Spanish CSC actually needs.

Built on the starter kit's workflow (`/Sites/starter-kit`). Filament admin + Livewire counter apps.

> **Read `NOTES-decisions-and-compliance.md` before prompt 01.** It carries the legal model, the
> security requirements, and the decisions the client must confirm at the prompt-01 checkpoint.

---

## What changed from v1 (the old "members club" set)

The v1 prompts described a competent **shop till**. This set describes a **finished product**. The
difference is four things v1 had none of:

| v1 | This build |
|---|---|
| UK club, pounds/pence, UK GDPR | **Spain. Euros (integer cents). RGPD + LOPDGDD, AEPD.** Language: *socio*, *aportación*, *dispensación* — never *cliente* / *venta* |
| Whole-unit products with a flat price | **Genetics** as first-class entities (THC/CBD %, terpenes, cultivation type, batch, lab test) priced **per gram**, stock as **integer centigrams** (0.01 g) |
| Members walk in, staff searches for them | **Check-in / check-out, live "who's inside", aforo (capacity) counter**, door checks for age, card validity, carencia and debt |
| Debt limits | **Declared monthly consumption forecast, daily + monthly gram limits, hard-blocked at the counter**, month-to-date gauge on the dispensing screen, logged manager override |
| "Cash-only, attributed" | **Till sessions**: open with float, cash movements, blind **arqueo**, **cierre de turno** with variance, per-operator accountability |
| A takings report | **A real dashboard** (see prompt 14) plus member / consumption / stock / attendance / till / financial report suites, all exportable |
| — | **Libro de socios, actas, registration forms and consumption declarations generated from the data** — the artifacts a club hands a lawyer |
| — | **Member PWA**: QR card, wallet balance, consumption allowance, private menu, news, events |
| — | **Append-only audit log**, UUID keys everywhere, encrypted ID documents behind signed URLs |
| Members club "no login" | Member auth **is** built (PWA), still no public pages |

**There is no public website.** Spanish CSCs may not advertise, so the app has no marketing pages,
no public menu, no SEO surface. `/` is the **dashboard** for staff and the **member PWA** for
members — see prompt 14. That's not a convenience choice; it's the compliance-correct shape.

---

## The shape

```
Organisation  (ONE club association — keyed everywhere so multi-org is additive later)
 └── Location  (a premises — the operational scope)
      ├── Members        (org-wide directory; membership per location; avalador; states)
      ├── Check-ins      (attendance, aforo, who's inside now)
      ├── Genetics       (strain + cannabinoid data)  →  Batches  →  Stock (centigrams)
      ├── Articles       (bar / food / merch — separate catalogue, separate ledger)
      ├── Dispensations  (member, genetic, batch, grams, operator, signature, limits checked)
      ├── Wallet         (member balance: top-ups, contributions, refunds)
      ├── Till sessions  (float, movements, arqueo, cierre, variance)
      ├── Expenses       (till petty cash + overheads) / Purchases (suppliers)
      └── Reports        (per location; owner sees an org-wide rollup)
```

## The prompts

**Phase A — foundation (once)**
- **00** — run the kit's `bootstrap.md` with `00-bootstrap-brief.md` as the brief (Full-app profile,
  no payment provider at bootstrap, org+location scope, blue/white, **no public site**)

**Phase B — build (strictly in order, one branch each, merge between)**

The order is dependency-driven, not thematic. Three things come earlier than instinct suggests, and
that is deliberate: **settings (03)** before any feature that reads a threshold, **limits (06)**
before anything that displays or enforces them, and **till sessions (10)** before either POS, because
a POS that cannot attach to an open drawer cannot commit.

- **01** schema — the *whole* schema, ULIDs, scope, money-in-cents + weight-in-centigrams, `Settings`,
  `BusinessDay` *(architecture checkpoint — the one that matters)*
- **02** auth, roles + the complete permission list, staff PINs, counter user switching, MFA
- **03** settings — every threshold, seeded with defaults, before anything consumes one
- **04** member directory, applications, avalador, ID capture, QR token, states, documents, RGPD
- **05** memberships, tiers, fees + fee payments, carencia, wallet ledger, renewals & expiry
- **06** consumption model — declared forecast, `ResolveMemberLimits`, daily/monthly caps, overrides
- **07** genetics, per-location prices, batches + lab data, stock in centigrams, merma, stock takes,
  articles (bar/merch)
- **08** pricing + discounts — `ResolvePrice`, tier pricing, therapeutic, per-member custom
- **09** check-in / check-out, aforo, who's-inside, door checks, daily entry–exit sheet
- **10** till sessions — float, movements, blind arqueo, cierre de turno, variance
- **11** dispensary POS — member-first, weight entry, limits, tender split, signature, void/correct
- **12** bar POS — separate catalogue, its own ledger, same drawer
- **13** expenses + purchases + suppliers
- **14** **dashboard + navigation + reports** — the big UI prompt; `/` becomes the dashboard
- **15** member PWA + club communications (announcements, events, push) — admin and member sides
- **16** legal documents — libro de socios, actas, registration form, consumption declaration
- **17** audit log, RGPD tooling, security hardening, operational monitoring
- **18** *(optional)* cultivation, kiosk, scale integration, RFID, loyalty, card top-ups

**Phase C — quality** (kit files): accessibility-audit → ui-passes 01–04 → admin-audit →
design/code-style/security audits. Merge between each. *(Skip seo-audit — there is no public site.)*

**Phase D — launch** (kit files): completeness-check → pre-staging-gate → the human checklist,
tailored per `HOW-TO-RUN.md`.

---

## Rules carried from the kit (apply to every prompt)

- One prompt = one branch; `git checkout main && git pull` before branching; review then merge;
  **never self-merge**. Push the branch, do not merge.
- Read `CLAUDE.md` + `DECISIONS.md` first. CLAUDE.md wins over a skill.
- **Checkpoints are for ARCHITECTURE, not visual taste.** Build visual work with tight constraints
  and let the human look at the real output.
- **No repository pattern.** Fat models + single-purpose `App\Actions`. Thin controllers. Page data
  → `App\ViewModels`. No service layer by reflex.
- **Money is integer cents. Weight is integer centigrams.** Euros/grams only at the input/display
  edge, via casts. A float anywhere in either is a bug.
- **Scope every query to organisation + active location.** Cross-location access is deliberate and
  permissioned. Write the denial tests.
- Ship tests with every feature; full suite after each; `composer check` green before every commit.
- **Never cache transactional data** — takings, stock, balances and limits are always queried live.
- Layout-affecting UI changes are screenshotted across **1440 / 1280 / 1024 / 390 and a short
  laptop height**, motion reduced *and* allowed. Non-visual changes get no screenshot ceremony.
- Every editable field is consumed somewhere; no orphans. Labels are plain Spanish/English, not
  column names.
