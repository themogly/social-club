# 04 — Member directory, applications, avalador, ID & RGPD

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md`. Requires 01–03 merged.

`git checkout main && git pull` → `git checkout -b feat/members-onboarding`.

## Build

**Member directory (org-wide, Filament resource)**
- List: photo thumbnail, `member_no`, name, status badge, location(s), tier, expiry, MTD grams,
  wallet balance. Filters: status, location, tier, therapeutic, expiring soon, over-limit, has debt.
  Search across name, member number, email, phone — **org-wide regardless of active location**.
- Detail page tabs, ordered the way staff think: **Overview** (photo, status, limits gauge, wallet,
  quick actions) · **Membership** · **Consumption history** · **Visits** · **Wallet** ·
  **Documents** · **Sanctions** · **Audit**.
- **Search-before-create.** Creating a member first runs a duplicate check across the org on name +
  DOB + email + phone + document number and shows near-matches with an "enrol this person instead"
  path. Duplicates split balances and consumption history — this guard is the fix.

**Applications / pre-registration**
- A tokenised invite link (no public signup page — the advertising ban) opens an application form:
  personal details, DOB, document, declared monthly grams, avalador selection, consents.
- Applications land in a **review queue**: approve → becomes a member with `carencia_ends_at` set;
  reject with a reason; or hold on a waiting list. Every decision audited and attributed.

**Avalador (sponsor)**
- Each new member records an `avalador_member_id` — an existing active member vouching for prior
  consumption. Configurable: required / waivable by a manager (logged) / not required (prompt 03).
- Therapeutic members may substitute a **medical certificate** document instead of an aval.
- The referral chain is queryable: who avalled whom, and how many each has sponsored (a cap is a
  setting; excessive sponsorship is a governance flag).

**Identity & age**
- `document_type` + `document_number` (**encrypted at rest**), DOB, and an optional document scan.
- **Age gate** at application and at every check-in: under the configured minimum age cannot be
  approved or admitted. The check is server-side and tested.
- **Member photo**: webcam capture in the panel plus upload. Cropped to a locked square ratio at
  upload with Filament's built-in image editor, converted to WebP, dimensions capped, metadata
  stripped. Shown to the operator at the counter and at check-in for visual verification.

**Document vault (private, encrypted)**
- ID scans, signed registration form, consumption declaration, medical certificates, consents.
- Stored on the **private disk**, never public, served only via **short-lived signed URLs**, path
  never guessable. **Every view writes an access log entry** (who viewed whose document, when).
- Only `member.documents.view` may open one. Test the denial.

**Membership numbers**
- Human-friendly `member_no` generated on approval, format configurable (prompt 03), unique per org,
  never reused. Distinct from the UUID and from the QR payload.

**QR membership card**
- A signed, **non-guessable** token (not the member id) encoding member + org, revocable and
  regenerable. Emailed via Resend with the QR **embedded inline via CID** (not hot-linked), PNG,
  added to the kit's mail-render test and `/dev/mail`, with a **resend-QR** action.
- The same token resolves whether scanned from the email, a printed card, the PWA (prompt 15) or a
  wallet pass. Scanning is the primary lookup at check-in and the counter.

**Member states & lifecycle**
- APPLICANT → ACTIVE → (INACTIVE | EXPIRED | SUSPENDED | EXPELLED). Transitions are actions with a
  reason, attributed and audited. A `MemberSanction` record backs suspension and expulsion, and
  feeds the *acta de expulsión* in prompt 16.

**RGPD / LOPDGDD (build now, don't retrofit)**
- **Versioned explicit consent** captured at application — lawful basis recorded, consent text
  version stored, timestamp and IP. Tacit consent is not valid.
- **Per-member data export** (portability) as a single downloadable pack.
- **Right to erasure**: anonymise-not-delete — scrub personal fields, retain the financial and
  consumption ledger in anonymised form so the books stay whole. Document the reasoning.
- **Retention**: a configurable period after `left_at`, with a scheduled purge job.
- Treat consumption data as **Article 9 special-category**; note it in DECISIONS.md.
- **CSV import** for the club's existing member list: dry-run preview, per-row validation, duplicate
  detection against the directory, and a mapping step. Import is audited.

## Rules

- Labels in plain Spanish/English, not column names. Help text on anything non-obvious.
- Never expose computed/system fields for editing (member_no, carencia_ends_at, MTD totals).
- No hard-delete of a member with transaction history — soft-delete + anonymise.
- Document and photo paths never appear in a public URL or an unsigned response.

## Tests (required)

- Duplicate detection surfaces an existing member on matching DOB + name; enrol-existing reuses the
  person record rather than creating a second.
- Under-age application cannot be approved; under-age member cannot check in.
- Document access: a STAFF user gets 403; an OWNER gets a signed URL that **expires**; both attempts
  write an access-log entry.
- Erasure anonymises the member but leaves dispensation totals and the till ledger intact and balanced.
- QR token: revoking and regenerating invalidates the old token; the token is not derivable from the
  member id; an integer id in the scan path 404s.
- The QR email renders (permanent render test) with the image inline, not hot-linked.
- CSV import dry-run reports errors without writing; a real import is idempotent on re-run.

## Finish

`composer check` green. Record the consent model, the erasure strategy and the retention period in
DECISIONS.md. Push the branch; **do not merge**.
