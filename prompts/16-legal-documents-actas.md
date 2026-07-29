# 16 — Legal documents: libro de socios, actas & generated forms

One branch, one task. Read `CLAUDE.md` + `DECISIONS.md` and section A of NOTES. Requires 01–14 merged.

`git checkout main && git pull` → `git checkout -b feat/legal-documents`.

> **This is the module that justifies the whole product.** Every competitor sells "manage your club".
> The artifacts here — the member register, the dispensing record, the minutes — are what a club
> actually hands to its lawyer, its assembly, or a court. Ley Orgánica 1/2002 requires the books; the
> point of holding the data properly is that the books generate themselves.

## Build

**Libro de socios (member register)**
- The statutory register: member number, full name, document number, join date, leave date, status,
  and location. Ordered, complete, exportable to PDF and CSV, generated for any point in time
  ("as at" a date) so a historical assembly can be evidenced.
- Includes leavers with their leave dates — a register that silently drops departed members is not
  a register.

**Libro de actas (minutes)**
- Create a minute record: type (**asamblea general** / **junta directiva** / **extraordinaria**),
  date, location, attendees (pulled from the member directory, with quorum computed against active
  members), agenda points, resolutions with votes for/against/abstained, and free text.
- Sequentially numbered per book, and **immutable once signed** — a signed minute is closed;
  corrections are a new, linked minute. Pages must not be removable, so no delete.
- Print/export to PDF in a conventional layout with signature blocks for the secretary and president.

**Generated member documents**
- **Registration form** (*solicitud de alta*) — from the member's data, with the consent text version
  and signature block.
- **Consumption declaration** (*previsión de consumo*) — declared monthly grams, the commitment to
  personal use and non-distribution, signed, versioned on change (prompt 06).
- **Acta de expulsión / sanción** — from the sanction record.
- Each generated document is stored in the member's vault (prompt 04), versioned, and reproducible.

**Dispensing record**
- The exportable "control sheet" per day/period: date, member number, genetic, batch, grams,
  contribution, operator — the closed-circuit evidence. This is a report (prompt 14) surfaced here
  as a formal, printable document.

**Accounting export**
- A period export of all income and outgoings in a shape a bookkeeper can import (CSV with a stable
  column contract), split by income type and expense category, so the *libros contables* obligation
  can be met by the club's accountant rather than reinvented in-app.

**Templates**
- Documents render from **editable templates** (per organisation, versioned) so the club's own
  wording and letterhead are used. Template edits never retroactively change an already-generated,
  signed document — regeneration produces a new version.

## Rules

- Generated documents are **immutable artifacts**: stored, versioned, timestamped, attributed. The
  underlying data may change; the issued document does not.
- Documents live on the **private disk**, served via short-lived signed URLs, access-logged.
- `documents.generate` permission; the libro de socios and actas are owner/manager only.
- Nothing here is legal advice, and the UI should not imply otherwise. A short, plain note on the
  module: these are records the club maintains, not a substitute for its own legal counsel.
- All documents render in the club's chosen language.

## Tests (required)

- Libro de socios "as at" a past date includes members active then and excludes later joiners.
- A signed minute cannot be edited or deleted; a correction creates a linked successor.
- Minute numbering is sequential per book with no gaps under concurrent creation.
- A generated registration form contains the member's data and the consent version in force at
  generation, and is unchanged by later edits to the member or the template.
- Quorum is computed against active members at the meeting date.
- Document access is permissioned, signed-URL only, expiring, and access-logged.
- The accounting export's totals reconcile exactly with the financial report for the same period.

## Finish

`composer check` green. Push the branch; **do not merge**.
