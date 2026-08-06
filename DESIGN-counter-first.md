# Counter-first: one place staff work, and member intake that happens there

Agreed design, with the owner's decisions folded in. Written against `main` at `d54e55b`; everything
asserted about the current code was checked in the repo.

---

## The idea

Staff live in the counter app. The admin panel becomes a back-office for the owner and managers, reachable
from the counter's overflow menu but never part of the routine. Member intake moves to the counter as a
wizard the applicant fills in themselves on the club's tablet, and the staff member on shift finishes it.

## Where things stand today

Closer than it looks. The counter is already five real screens behind one shared header — **Recepción**,
**Socios**, **Dispensario**, **Barra**, **Caja** — each permission-gated, with a sede switcher, an idle
lock with a PIN pad, a panic button and operator identification. It has been through three rounds of
portrait-tablet layout work.

A staff member can already do more there than expected: check-in shows live limits and wallet balance, the
till screen records petty-cash expenses, Socios collects membership fees through the same single writer the
till uses. The `STAFF` role holds `pos.use`, `pos.bar`, `checkin.manage`, `members.view`,
`expenses.record`, `membership.fee.collect`, `till.open` and `lockdown.initiate` — and every one of those
except `members.view` already has a counter home.

So "staff never open the panel" is not a rebuild. It is one nav change and one missing screen.

## The nav change

The panel sidebar carries **four** separate links into the counter, scattered across four different groups:
*Acceso / Check-in* under Socios, *TPV dispensario* under Dispensario, *TPV barra* under Barra y tienda,
*Terminal de caja* under Caja. That is what makes the counter feel like four tools reached from four
places.

It becomes one link. The counter is one application; once inside it, its own five-tab strip is the
navigation. The front door is **Recepción**, because that is where a shift starts.

## Intake

### The wizard creates an application, not a member

The application flow already exists end to end: a tokenised route, a validated payload
(`SubmitApplicationRequest`), two separate Article 9 consent ticks, an ID-photo upload into the encrypted
vault, a spam guard, and an audited `ApproveApplication` that does the age gate, the duplicate search, the
versioned consent capture and the member creation.

What changes is **which device the form is filled in on**. That is a delivery change, not a governance one,
and framing it that way is what keeps it cheap and keeps the compliance intact.

### The flow

Staff tap **Alta** and get two ways to start the same record.

**Hand the tablet over.** The counter creates an invitation — the same `MemberApplication` and token that
"Copiar enlace" produces today, with the sede pre-filled — and drops the tablet into **handover mode**:
full screen, wizard only, no counter chrome, no way back. The applicant fills in their own details in their
own language, photographs their DNI and themselves with the club's camera, ticks the two consents, and taps
Done. The tablet returns to the counter and asks for the operator PIN.

**Or send it.** Staff take an email address, the invitation goes out, the applicant does it at home, and it
lands in the same list to be picked up on their next visit.

Either way the staff member then completes it in one sitting: review the submitted details, pick the
membership tier, approve, take the fee. Three existing single-writer actions in sequence —
`ApproveApplication` → `EnrolMembership` → `RecordFeePayment`. No new writer, and no second copy of the
join form.

### Handover mode is already half-built

The counter has a lock overlay with its own PIN pad that paints an opaque full-viewport surface so an
unattended tablet stops showing member data. Handover mode is the same primitive pointed the other way:
instead of hiding the counter behind a PIN, it hides the counter behind the **wizard**, and the PIN is how
you get back.

While the applicant holds the tablet there must be no member data on screen, no route out of the wizard, an
idle timeout that lands on the lock screen rather than the counter, and no trace of the previous
applicant's draft.

### Alta lives inside the Socios tab

Not as a sixth tab. The strip took three prompts to fit five destinations on a portrait tablet and a sixth
would reopen that. It also belongs there on the merits: Socios today is thin — find a member, see what they
owe, take the money — and "add a new one" is the same job.

---

## Decisions taken

**Staff approve. No manager needed.** There is normally one member of staff in the club; requiring a
manager would mean nobody could be signed up. So `applications.review` is granted to `STAFF`, reversing the
overnight default recorded in prompt 122.

Worth being precise about what that opens, because it is narrower than it sounds. Staff can admit somebody
**who applied** — through the audited path, with the age gate, the duplicate search, the consent capture
and the recorded locale all enforced by `ApproveApplication`. `members.create`, the panel's direct-enrol
form that bypasses an application entirely, **stays manager-only**. That line is defensible and worth
keeping: a staff member can approve a person who filled in a form, not conjure a member out of nothing.

**The fee is collected after approval, inside the same wizard.** Not a separate errand — staff would not
sign someone up if they could not be approved, so approval and payment are consecutive steps in one flow.
If payment fails or is deferred, the member exists and owes a fee, which is an ordinary representable state
the system already handles.

**The panel link stays in the counter's overflow menu.** Staff can still reach a member's full record —
history, documents, sanctions — when they need it. Nothing routine sends them there.

---

## The work

Three branches, in order:

1. **Nav collapse** — four sidebar links become one. Small and independent; touches `AdminPanelProvider`,
   so it wants to land after the tablet-layout branch rather than beside it.
2. **Handover mode** — the counter's lock primitive turned around, with its own tests for the
   "applicant is holding the tablet" state. Real security surface; deliberately not in the same branch as
   the form.
3. **The Alta wizard inside Socios** — invitation creation at the counter, the existing application form
   rendered in handover mode, and the review → tier → approve → collect finish.

None of it duplicates an existing writer, and the only permission that moves is `applications.review` —
which is the strongest signal the shape is right.
