<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Members\ApproveApplication;
use App\Actions\Members\FindDuplicateMembers;
use App\Actions\Members\IssueApplicationInvite;
use App\Actions\Members\SubmitApplication;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\RecordAuditLog;
use App\Enums\ConsentChannel;
use App\Exceptions\DuplicateMemberException;
use App\Http\Requests\SubmitApplicationRequest;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MembershipTier;
use App\Models\User;
use App\Support\ApplicationShape;
use App\Support\Mrz\MrzParser;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Alta at the counter (prompt 174) — sign somebody up without opening the panel.
 *
 * THE rule this lives or dies on: **it creates an APPLICATION, not a member.** The join form already exists
 * end to end — a tokenised route, `SubmitApplicationRequest`, two separate Article 9 consent ticks, the
 * encrypted ID upload (178), a spam guard — and `ApproveApplication` already does the age gate, the
 * duplicate search, the versioned consent capture naming the locale the applicant actually read, and the
 * member creation. What changes here is WHICH DEVICE the form is filled in on. Nothing else.
 *
 * So there is no second form, no second validator and no second consent capture in this file. The applicant
 * is sent to the REAL public form at the REAL public route with the REAL token — which is why a record made
 * at the counter is byte-comparable with one made from an emailed invite: it is not a parallel path, it is
 * the same path on a different screen.
 *
 * No new writer either. `IssueApplicationInvite` → `ApproveApplication` → `EnrolMembership` →
 * `RecordFeePayment` (the last via {@see CollectsMembershipFees}), each already audited, in that order.
 */
trait SignsUpMembers
{
    /** The application being reviewed after the tablet comes back, if any. */
    public ?string $altaApplicationId = null;

    /** Chosen tier at the review step. */
    public ?string $altaTierId = null;

    /** Email for the send-an-invitation path. */
    public string $altaInviteEmail = '';

    /** Set when approval hit a duplicate — the matches to show, and the decision to take. */
    public bool $altaDuplicateBlocked = false;

    /**
     * Whether the sign-up SURFACE is open (prompt 221 — it is a modal now, not an inline panel).
     *
     * The name and the meaning are unchanged on purpose: *the sign-up is open*. 210's and 215's tests drive
     * `toggleAlta()` → `toggleStaffAltaForm()` and go on working, because what those two do has not changed
     * either — the first opens the sign-up, the second enters the staff-typed route. Only where they draw
     * moved. This branch is presentation and flow; nothing it touches changes what anything DOES.
     */
    public bool $altaOpen = false;

    /**
     * Which step of the staff wizard is on screen (prompt 221): 1 Identidad · 2 Contacto · 3 Membresía ·
     * 4 Firma.
     *
     * The fields live on the component, not in the step, so stepping backwards and forwards keeps whatever
     * has been typed — a wizard that loses a half-typed application on Back is worse than the long form it
     * replaced.
     */
    public int $altaStep = 1;

    /**
     * The invitation was sent — the chooser's green confirmation row (prompt 221).
     *
     * A flash message alone said it in the wrong place: the operator's eyes are in the modal, and the flash
     * renders on the screen behind it.
     */
    public bool $altaInviteSent = false;

    /**
     * The staff-typed form (prompt 210) — open, its fields, and how consent was captured.
     *
     * **Why this route did not exist, and why the button read wrong.** The owner: *"I don't think it needs to
     * say 'hand tablet over' … the staff might do it for them as well."* He was right, and the reason is
     * sharper than the wording: `handOverForAlta()` and `sendAltaInvitation()` were the only two ways to begin
     * a sign-up, so **there was no staff-fills-it-in route at all**. A member of staff with the person in
     * front of them could reach the form only by handing the device over — and then, if they typed it
     * themselves, they were working on the applicant-facing public page with no counter chrome and a PIN to
     * get back out. The label was not badly chosen; it was describing the only mechanism there was.
     *
     * @var array<string, mixed>
     */
    public array $altaForm = [];

    /**
     * The operator's explicit confirmation that the club holds this person's consent — see the consent
     * decision on {@see self::submitStaffAlta()}. Required, and deliberately not a default.
     */
    public bool $altaConsentHeld = false;

    public bool $altaStaffFormOpen = false;

    /** The photo the counter nags about on three screens, and the ID document. Both optional (prompt 215). */
    public mixed $altaPhoto = null;

    public mixed $altaDocumentScan = null;

    /**
     * The member's signature over the consent text, once drawn (prompt 220).
     *
     * Held as the raw data URL rather than a stored path, deliberately: on the staff route the application
     * does not exist until submit, and writing bytes to the vault for a form somebody abandons would leave
     * orphans nothing owns. `SubmitApplication` stores it at the same moment it stores the record.
     */
    public ?string $altaSignaturePath = null;

    /**
     * Which fields prompt 179's reader filled, so the operator can see what to check.
     *
     * @var list<string>
     */
    public array $altaMrzFilled = [];

    /**
     * WHERE each field of the shared application shape is asked (prompt 221).
     *
     * Declared once, here, because two things read it — the wizard's markup and `altaNext()`'s validation —
     * and a third thing GUARDS it: `SignupWizardTest` asserts this covers `ApplicationShape` exactly, so a
     * field added to the shared declaration cannot reach the public form and quietly miss the wizard. That is
     * the 215 defect in a new costume, and it is the only reason this is a constant rather than markup.
     *
     * The two uploads and prompt 179's reader sit on **Identidad** deliberately: the MRZ scan reads the
     * document file the operator has just chosen (`mountStaffMrzScan` binds the trigger to `[data-alta-scan]`,
     * so they must render together), and it PREFILLS four identity fields — putting it anywhere else would
     * mean scanning on one step to fill another. The face photo joins them because a photo compared with the
     * person at the counter is identity too, not contact detail.
     *
     * @var array<int, list<string>>
     */
    public const WIZARD_STEPS = [
        1 => ['first_name', 'last_name', 'date_of_birth', 'document_type', 'document_number', 'photo', 'document_scan'],
        2 => ['email', 'phone', 'address', 'avalador_ref'],
        3 => ['is_therapeutic', 'declared_monthly_g'],
        4 => [ApplicationShape::SIGNATURE_FIELD],
    ];

    /**
     * The stepper's labels, keyed by step.
     *
     * Here rather than in the markup so the labels and {@see self::WIZARD_STEPS} cannot end up describing
     * different wizards — and because a trait CONSTANT is not addressable through the trait's own name in
     * PHP, so a template reaching for one gets a fatal rather than a number.
     *
     * @return array<int, string>
     */
    public function altaStepLabels(): array
    {
        return [
            1 => __('Identidad'),
            2 => __('Contacto'),
            3 => __('Membresía'),
            4 => __('Firma'),
        ];
    }

    /** The last step — derived from the map, so adding a step is one edit. */
    public function lastAltaStep(): int
    {
        return (int) array_key_last(self::WIZARD_STEPS);
    }

    public function toggleAlta(): void
    {
        $this->altaOpen ? $this->closeAlta() : $this->altaOpen = true;
    }

    /**
     * Close the sign-up and leave nothing half-held.
     *
     * The CONFIRM is not here — it is rendered onto the closing controls only when there is something to
     * lose ({@see self::altaHasEnteredData()}). 206's lesson: a guard that fires when nothing would be lost
     * teaches the operator to dismiss guards.
     */
    public function closeAlta(): void
    {
        $this->altaOpen = false;
        $this->altaStaffFormOpen = false;
        $this->altaInviteSent = false;
        $this->resetAltaForm();
        $this->altaConsentHeld = false;
        $this->reset(['altaApplicationId', 'altaTierId', 'altaDuplicateBlocked']);
        $this->resetValidation();
    }

    /**
     * Has the operator typed anything that closing would throw away?
     *
     * Server-side, because it is the server that knows what the form holds. Deliberately narrow: a chosen
     * method with an untouched form is not "data entered", and neither is an email left in the invite box
     * after it was sent.
     */
    public function altaHasEnteredData(): bool
    {
        $typed = collect($this->altaForm)->contains(fn (mixed $v): bool => is_bool($v) ? $v : filled($v));

        return $typed
            || $this->altaPhoto !== null
            || $this->altaDocumentScan !== null
            || $this->altaSignaturePath !== null
            || (! $this->altaInviteSent && trim($this->altaInviteEmail) !== '');
    }

    /**
     * The fields asked on a step, whatever the shared declaration currently holds.
     *
     * @return list<string>
     */
    public function altaStepFields(int $step): array
    {
        return self::WIZARD_STEPS[$step] ?? [];
    }

    /**
     * Forward a step — validating ONLY what this step asked, with the rules the route already has.
     *
     * No second validator: `staffAltaRules()` is the whole rule set (which is `SubmitApplicationRequest`'s,
     * namespaced), and this takes the slice belonging to the current step. So a step cannot enforce something
     * the submit does not, or miss something it does.
     */
    public function altaNext(): void
    {
        $rules = array_intersect_key($this->staffAltaRules(), array_flip($this->altaStepRuleKeys($this->altaStep)));

        if ($rules !== []) {
            $this->validate($rules, [], $this->staffAltaAttributes());
        }

        $this->altaStep = min($this->altaStep + 1, $this->lastAltaStep());
    }

    /**
     * Jump to a step already reached — BACKWARDS only.
     *
     * The stepper is a map of where you are, and tapping a circle you have filled in is the ordinary way back.
     * Forwards stays behind `altaNext()`, because that is where the step's own rules run: a stepper that let
     * you tap straight to Firma would be a way around validation, not a shortcut.
     */
    public function goToAltaStep(int $step): void
    {
        if ($step < 1 || $step >= $this->altaStep) {
            return;
        }

        $this->resetValidation();
        $this->altaStep = $step;
    }

    /** Back a step — and from the first step, back to the method chooser. */
    public function altaBack(): void
    {
        $this->resetValidation();

        if ($this->altaStep <= 1) {
            $this->altaStaffFormOpen = false;

            return;
        }

        $this->altaStep--;
    }

    /**
     * The rule keys belonging to a step — the wizard's field names mapped onto how the rules are namespaced.
     *
     * @return list<string>
     */
    private function altaStepRuleKeys(int $step): array
    {
        return array_map(fn (string $field): string => match ($field) {
            'photo' => 'altaPhoto',
            'document_scan' => 'altaDocumentScan',
            ApplicationShape::SIGNATURE_FIELD => 'altaSignaturePath',
            default => 'altaForm.'.$field,
        }, $this->altaStepFields($step));
    }

    /** Open (or close) the staff-typed form — one of three ways to do the one job, not a separate job. */
    public function toggleStaffAltaForm(): void
    {
        $this->altaStaffFormOpen = ! $this->altaStaffFormOpen;
        $this->altaStep = 1;

        if ($this->altaStaffFormOpen && $this->altaForm === []) {
            $this->resetAltaForm();
        }

        if (! $this->altaStaffFormOpen) {
            $this->resetAltaForm();
            $this->altaConsentHeld = false;
            $this->resetValidation();
        }
    }

    /**
     * Hand the tablet over: create the application, enter 173's handover mode, and send the tablet to the
     * public form.
     *
     * The redirect goes to the ordinary tokenised route on purpose. 173's guarantees still hold around it —
     * handover is session-backed, so the counter's chrome is absent from the DOM and the back button returns
     * to a PIN rather than to a counter screen — while the applicant gets the real form, with prompt 167's
     * language switcher, which is the same audience for the same reason.
     */
    public function handOverForAlta(): void
    {
        $operator = $this->requireOperatorForAlta();

        if ($operator === null) {
            return;
        }

        $application = $this->issueApplication($operator, email: null, reference: __('Alta en el mostrador'));

        if ($application === null) {
            return;
        }

        // 173's OWN entry point, not a second way in: it records the audit entry and signs the operator out
        // (which is what makes requireOperator() refuse every write while an applicant holds the device).
        // Last thing before the redirect, so the review steps below can only run after a fresh PIN.
        // The invite URL is recorded with the handover so EnforceCounterHandover can put the applicant back
        // on their form if they leave it, rather than bouncing them to a PIN pad they cannot use.
        $this->beginHandover($application->inviteUrl());

        $this->redirect($application->inviteUrl(), navigate: false);
    }

    /**
     * Blank the form from the one declaration (prompt 215).
     *
     * It used to be a `BLANK_ALTA_FORM` constant listing ten fields by hand while the public form posted
     * sixteen. Derived now, so a field added to `ApplicationShape::facts()` reaches this route's state and
     * validation with no second edit — including `declared_monthly_g`, which feeds the cultivation forecast
     * and the stock ceiling, and which staff-created members arrived without.
     */
    private function resetAltaForm(): void
    {
        $this->altaForm = ApplicationShape::blankStaffForm();
        $this->altaPhoto = null;
        $this->altaDocumentScan = null;
        $this->altaMrzFilled = [];
        $this->altaSignaturePath = null;
        $this->altaStep = 1;
    }

    /**
     * The member signs, on this tablet, in front of the operator (prompt 220).
     *
     * The same `x-counter.signature-pad` the dispensation uses, and the same contract — the drawing arrives
     * as a PNG data URL. It is NOT written to the vault here: the application does not exist yet, and bytes
     * written for a form somebody abandons are orphans nothing owns. `SubmitApplication` stores it with the
     * record, in one moment.
     */
    public function saveAltaSignature(string $dataUrl): void
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return;
        }

        $this->altaSignaturePath = $dataUrl;
        $this->flash(__('Firma capturada.'), 'success');
    }

    public function clearAltaSignature(): void
    {
        $this->altaSignaturePath = null;
    }

    /**
     * Prompt 179's ID-scan prefill, on the staff form (prompt 215).
     *
     * The reader is the SAME `readMrz()` the public form uses, and the parse is the SAME `MrzParser` —
     * 179 built both as reusable and wired them to one consumer. What differs is only how the read arrives:
     * the public form POSTs the raw zone to a tokenised route because it has a token, and this form has no
     * application yet, so the browser hands the raw string straight to the component.
     *
     * The ICAO check digit still decides. `valid !== true` means a mis-read, and a mis-read must never
     * prefill a document number — 128 built the parser correct-or-invalid for exactly that. A failed or
     * absent read leaves the form untouched and usable, which is what makes an imperfect reader safe.
     */
    public function applyMrz(string $raw): void
    {
        $this->altaMrzFilled = [];

        if ($raw === '' || mb_strlen($raw) > 200) {
            return;
        }

        $parsed = (new MrzParser)->parse($raw);

        if ($parsed === null || $parsed['valid'] !== true) {
            $this->flash(__('No se ha podido leer el documento. Escribe los datos a mano.'), 'warning');

            return;
        }

        $read = array_filter([
            'first_name' => $parsed['given_names'],
            'last_name' => $parsed['surname'],
            'document_number' => $parsed['document_number'],
            // The only nullable one: a TD1/TD3 date can fail to parse while the rest of the zone reads.
            'date_of_birth' => $parsed['birth_date'],
        ], fn (?string $v): bool => filled($v));

        foreach ($read as $field => $value) {
            $this->altaForm[$field] = $value;
        }

        // Named, so the operator knows which four to check against the document in their hand — the same
        // "the person is the check" rule the public form's confirmation partial encodes.
        $this->altaMrzFilled = array_keys($read);
    }

    /**
     * **Staff type the form, here, inside the counter's chrome** — the third way to do the one job (prompt
     * 210), and the one that was missing.
     *
     * ONE WRITER, and that is the whole argument. This does not validate its own way, build its own payload
     * or capture its own consent: the facts go through `SubmitApplicationRequest::factRules()` — literally
     * the public form's rules — and the record is written by **`SubmitApplication`**, the same Action the
     * public POST calls, against an application issued by the same `IssueApplicationInvite`. The age gate,
     * the duplicate search and the versioned consent capture all still run in `ApproveApplication`
     * afterwards, unchanged: 174's argument was that the audited route is the open one, and this is that
     * route with a different keyboard in front of it.
     *
     * ---
     * **THE CONSENT DECISION, which is a compliance judgement and not a wording one.**
     *
     * The facts on the form — name, birth date, document, contact — are the same facts whoever types them.
     * The consent is not. `SubmitApplication` stamps a versioned consent text and locale at submit, and that
     * record is the club's evidence that the applicant agreed to the processing of their data **including
     * Article 9 health data** (the medicinal-usage flag). If a member of staff ticks that box on someone's
     * behalf, the artefact stops being a record of consent GIVEN and becomes the club's assertion that it
     * WAS — a materially different thing to hold in an inspection, and it is the club that carries it.
     *
     * **So this route cannot produce the public form's artefact, and does not pretend to.** Choosing to type
     * it here IS the choice: the consent row is stamped `PAPER` and **names the operator who recorded it**,
     * so an inspection can tell the two apart and the club knows which records are which. The two ways that
     * end in the applicant's own tick are still on the same panel, one tap away — hand the tablet over, or
     * send a link — and the screen says which artefact each produces.
     *
     * There is deliberately no option where a member of staff ticks on the applicant's behalf and the record
     * reads as an applicant tick. The operator must confirm explicitly that the club holds the consent, and
     * that confirmation is what the row then attributes to them.
     *
     * **RESOLVED (prompt 218).** Whether `PAPER` is acceptable for Article 9 explicit consent without a scan
     * of the signed form was the owner's call, and he made it: **no scan is required.** The club already takes
     * the signature on the paper form, so the evidence is that filed form PLUS this row — the channel, the
     * named attesting operator, and the `consent_text_version` that says which text was signed. Requiring a
     * scan was considered and **deferred**, not rejected, on his judgement about counter friction.
     *
     * **Standing instruction: do not add a scan requirement, a signature-pad consent step, or any other
     * tightening of the `PAPER` channel without the owner asking for it** — same force as a withdrawn prompt.
     * The row-level pieces below are load-bearing and stay, because they are the half of the evidence pair
     * this method is responsible for. See prompt 218 in `DECISIONS.md` for the reasoning and the tripwire.
     */
    public function submitStaffAlta(): void
    {
        $operator = $this->requireOperatorForAlta();

        if ($operator === null) {
            return;
        }

        // The signature is validated alongside the facts but lives on its own property, so it is pulled out
        // rather than read from the `altaForm` array.
        $this->validate($this->staffAltaRules(), [], $this->staffAltaAttributes());
        $data = $this->altaForm;

        // The public route reaches `SubmitApplication` through `ConvertEmptyStringsToNull`, which turns an
        // unanswered optional field into null; Livewire hands over the empty string as typed. Normalising
        // here keeps the two callers handing the ONE writer the same thing — which is this branch's whole
        // point, and without it an unanswered "consumo mensual" reached `Weight::fromGrams('')` and threw.
        $data = array_map(fn (mixed $v): mixed => $v === '' ? null : $v, $data);

        $application = $this->issueApplication($operator, email: $data['email'] ?: null, reference: __('Alta en el mostrador'));

        if ($application === null) {
            return;
        }

        (new SubmitApplication)->handle(
            $application,
            $data + [
                // `SubmitApplication` promotes this to SIGNED when a signature is present — the member's own
                // act outranks the club's attestation, and understating captured evidence is the one wrong
                // answer (prompt 220).
                'consent_channel' => ConsentChannel::PAPER,
                'consent_attested_by' => $operator->id,
                ApplicationShape::SIGNATURE_FIELD => $this->altaSignaturePath,
            ],
            // The photo and the ID document, through the SAME writer and therefore the SAME vault: encrypted
            // before write, on the private disk, served only by signed access-logged URL (prompt 215). 177's
            // rule that no scan is RENDERED at the counter is untouched — capturing is not displaying.
            files: [
                'photo' => $this->altaPhoto?->getRealPath() !== null ? $this->altaPhoto : null,
                'document_scan' => $this->altaDocumentScan?->getRealPath() !== null ? $this->altaDocumentScan : null,
            ],
            token: (string) $application->invite_token,
            ip: request()->ip(),
        );

        (new RecordAuditLog)->handle('counter.alta.staff_entered', $this->resolveLocation(), [
            'application_id' => $application->id,
            'consent_channel' => ConsentChannel::PAPER->value,
        ]);

        $this->resetAltaForm();
        $this->altaConsentHeld = false;
        $this->altaStaffFormOpen = false;
        $this->flash(__('Solicitud creada. Revísala para dar de alta.'), 'success');
    }

    /**
     * The public form's rules, namespaced onto `altaForm`, plus the one rule this route adds.
     *
     * File uploads are deliberately absent: they are optional on the public form and a counter form has no
     * file picker in front of an applicant. The consent confirmation is `accepted` rather than a checkbox
     * with a default — a default is exactly how a staff assertion would end up wearing an applicant tick's
     * clothes.
     *
     * @return array<string, mixed>
     */
    private function staffAltaRules(): array
    {
        // With signatures on (prompt 220) the member's own signature IS the consent evidence, so the paper
        // attestation is neither shown nor required — it would be asking staff to assert something the member
        // has just done for themselves. With signatures off, 210's rule returns exactly.
        $rules = (bool) Settings::get('signature_on_application', true)
            ? [ApplicationShape::SIGNATURE_FIELD => ['required', 'string']]
            : ['altaConsentHeld' => ['accepted']];

        foreach (ApplicationShape::facts() as $field => $rule) {
            $rules['altaForm.'.$field] = $rule;
        }

        // The two uploads live on their own properties (Livewire holds a TemporaryUploadedFile, not a form
        // array), and are validated by the SAME rules the public form uses — prompt 215's declaration.
        foreach (ApplicationShape::files() as $field => $rule) {
            $rules[$field === 'photo' ? 'altaPhoto' : 'altaDocumentScan'] = $rule;
        }

        // The signature lives on `$altaSignaturePath`; the rule above names the shared field, so map it.
        if (isset($rules[ApplicationShape::SIGNATURE_FIELD])) {
            $rules['altaSignaturePath'] = $rules[ApplicationShape::SIGNATURE_FIELD];
            unset($rules[ApplicationShape::SIGNATURE_FIELD]);
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function staffAltaAttributes(): array
    {
        return [
            'altaForm.first_name' => __('Nombre'),
            'altaForm.last_name' => __('Apellidos'),
            'altaForm.email' => __('Email'),
            'altaForm.date_of_birth' => __('Fecha de nacimiento'),
            'altaForm.document_type' => __('Tipo de documento'),
            'altaForm.document_number' => __('Número de documento'),
            'altaConsentHeld' => __('Consentimiento'),
            'altaSignaturePath' => __('Firma'),
        ];
    }

    /** Send an invitation instead — the same record and token shape, picked up on their next visit. */
    public function sendAltaInvitation(): void
    {
        $operator = $this->requireOperatorForAlta();

        if ($operator === null) {
            return;
        }

        $email = trim($this->altaInviteEmail);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash(__('Escribe un email válido para enviar la invitación.'), 'error');

            return;
        }

        if ($this->issueApplication($operator, email: $email, reference: null) === null) {
            return;
        }

        $this->altaInviteEmail = '';
        $this->altaInviteSent = true;
    }

    /**
     * Applications submitted at this sede and waiting to be finished.
     *
     * @return Collection<int, MemberApplication>
     */
    public function pendingAltaApplications(): Collection
    {
        if ($this->locationId === null || ! $this->userCan('applications.review')) {
            return collect();
        }

        return MemberApplication::query()->withoutGlobalScopes()
            ->where('location_id', $this->locationId)
            ->awaitingReview()   // the same scope the hub's alert counts (prompt 207)
            ->latest('submitted_at')
            ->limit(10)
            ->get();
    }

    public function reviewAltaApplication(string $applicationId): void
    {
        $this->altaApplicationId = $applicationId;
        $this->altaTierId = null;
        $this->altaDuplicateBlocked = false;
    }

    public function cancelAltaReview(): void
    {
        $this->reset(['altaApplicationId', 'altaTierId', 'altaDuplicateBlocked']);
    }

    /**
     * Approve the application and enrol the membership. Payment is NOT part of this — see below.
     *
     * @param  bool  $allowDuplicate  an explicit, deliberate override, never a default
     */
    public function approveAlta(bool $allowDuplicate = false): void
    {
        $operator = $this->requireOperatorForAlta();
        $application = $this->altaApplication();
        $location = $this->altaLocation();
        $tier = $this->altaTier();

        if ($operator === null || $application === null || $location === null) {
            return;
        }

        if (! $this->userCan('applications.review')) {
            $this->flash(__('No tienes permiso para aprobar solicitudes.'), 'error');

            return;
        }

        if ($tier === null) {
            $this->flash(__('Elige una cuota antes de aprobar.'), 'error');

            return;
        }

        try {
            $member = (new ApproveApplication)->handle($application, $operator->id, $allowDuplicate);
        } catch (DuplicateMemberException $e) {
            // Surfaced as a DECISION, never as a default. The matches are re-resolved read-only for display
            // because the exception carries them only inside its message.
            $this->altaDuplicateBlocked = true;
            $this->flash($e->getMessage(), 'warning');

            return;
        } catch (RuntimeException $e) {
            // Underage, or a payload missing a required name. Both are ordinary things that happen with a
            // person standing at the counter, so they get the action's own readable sentence — never a stack
            // trace — and the application stays PENDING so a responsable can decide what to do with it.
            $this->flash($e->getMessage(), 'error');

            return;
        }

        (new EnrolMembership)->handle($member, $location, $tier);

        // Approval and payment are DELIBERATELY not one transaction. If the fee cannot be taken — no cash,
        // a card machine that will not talk — the member still exists and owes it, which is an ordinary
        // state this product already represents and the counter already surfaces. Rolling back an admission
        // over a payment failure would be worse.
        $this->feeMemberId = $member->id;
        $this->reset(['altaApplicationId', 'altaTierId', 'altaDuplicateBlocked']);
        $this->altaOpen = false;

        $this->flash(__('Socio dado de alta. Cobra la cuota cuando quieras.'), 'success');
    }

    /** @return Collection<int, MembershipTier> */
    public function altaTiers(): Collection
    {
        return MembershipTier::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->altaLocation()?->organisation_id)
            ->orderBy('name')->get();
    }

    public function altaApplication(): ?MemberApplication
    {
        if ($this->altaApplicationId === null) {
            return null;
        }

        return MemberApplication::query()->withoutGlobalScopes()->find($this->altaApplicationId);
    }

    /**
     * The matches that blocked approval — read-only, resolved for display only.
     *
     * @return Collection<int, Member>
     */
    public function altaDuplicateMatches(): Collection
    {
        $payload = $this->altaApplication()?->payload;

        return is_array($payload) ? (new FindDuplicateMembers)->handle($payload) : collect();
    }

    private function altaTier(): ?MembershipTier
    {
        return $this->altaTierId !== null
            ? MembershipTier::query()->withoutGlobalScopes()->find($this->altaTierId)
            : null;
    }

    private function altaLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    /**
     * The sede comes from the counter's resolved location, never from the client, and the actor is the
     * PIN-identified operator rather than the device session user.
     */
    private function issueApplication(User $operator, ?string $email, ?string $reference): ?MemberApplication
    {
        try {
            return (new IssueApplicationInvite)->handle($operator, $this->locationId, $email, $reference);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');

            return null;
        }
    }

    private function requireOperatorForAlta(): ?User
    {
        if (! $this->requireOperator()) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
