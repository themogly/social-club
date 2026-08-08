<?php

namespace App\Support;

use App\Enums\IdDocumentType;
use Illuminate\Validation\Rule;

/**
 * What a membership application IS — declared once, consumed by both forms (prompt 215).
 *
 * **The defect.** The applicant's public form posts 16 fields; the staff form prompt 210 built had 10. Two
 * hand-written lists — the `name="…"` inputs in `socio/application.blade.php` and `BLANK_ALTA_FORM` in
 * `SignsUpMembers` — with nothing making them agree. 210 got the WRITER right (both routes go through
 * `SubmitApplication`), so the writer was simply handed less by one caller than the other, **silently**.
 *
 * What the staff route was missing: the member **photo**, the **ID document scan**, prompt 179's whole
 * **MRZ prefill**, and **`declared_monthly_g`**.
 *
 * `declared_monthly_g` is the one nobody would notice. It feeds `declared_monthly_cg`, which the club uses
 * for its cultivation forecast and which sits behind `StockCeiling::forLocation()` — so a member signed up by
 * staff arrived with none, and the figure the club plans its legal grow against was quietly short by one
 * member every time. And the photo is the sharpest irony: the counter nags about a missing one on three
 * screens, and the form staff use to create members could not capture one.
 *
 * **Adding four fields would have fixed today.** One source consumed by both hosts fixes the class — and it
 * is the rule this project already applies to money, stock, pricing and permissions.
 *
 * **This is the third instance in a week** of a reusable piece built and wired to one of its consumers:
 * `OpensMemberships` (203, wired to Socios only; 211 wired the other two), the MRZ partial (179, included by
 * the public form and nothing else), and this list.
 */
class ApplicationShape
{
    /**
     * The FACTS an application carries, and the rule each is validated by — the single declaration.
     *
     * Consent is deliberately absent: it is not a fact about the applicant, the two forms capture it
     * differently on purpose (prompt 210's paper-consent decision, which this branch does not undo), and
     * folding it in here would make that difference look like drift.
     *
     * @return array<string, mixed>
     */
    public static function facts(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'document_type' => ['required', Rule::enum(IdDocumentType::class)],
            'document_number' => ['required', 'string', 'max:64'],
            'is_therapeutic' => ['sometimes', 'boolean'],
            'declared_monthly_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'avalador_ref' => ['nullable', 'string', 'max:255'],
            // Prompt 220. `nullable` here and REQUIRED by the club's setting in `SubmitApplication`, so a
            // club with signatures off is not asked for one it does not want.
            self::SIGNATURE_FIELD => ['nullable', 'string'],
        ];
    }

    /**
     * The two OPTIONAL uploads, and their rules.
     *
     * A face and a document are different artefacts with different purposes and different lawful bases —
     * never merged (prompt 178). Both are optional on both routes: an applicant who cannot upload must still
     * be able to apply, and a counter with no camera must still be able to sign somebody up.
     *
     * @return array<string, mixed>
     */
    public static function files(): array
    {
        return [
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', DocumentUpload::maxRule()],
            'document_scan' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,pdf', DocumentUpload::maxRule()],
        ];
    }

    /**
     * The applicant's own signature over the consent text (prompt 220), as a PNG data URL.
     *
     * Declared HERE rather than wired per form, which is the whole point of 215: it reaches the public form
     * and the staff form through the one shape, and 215's parity guard proves both render it. It is not a
     * `file()` — nothing is uploaded; the pad draws it and `SubmitApplication` puts the bytes in the same
     * vault the dispensation signature uses.
     *
     * Whether it is REQUIRED is `signature_on_application`, enforced in `SubmitApplication` — a rule about
     * the club rather than about the field.
     */
    public const SIGNATURE_FIELD = 'signature';

    /** The four fields prompt 179's reader can fill from a document. */
    public const MRZ_FIELDS = ['first_name', 'last_name', 'date_of_birth', 'document_number'];

    /**
     * The consent fields — the ONE deliberate difference between the two routes.
     *
     * The public form takes the applicant's two acceptances; the staff form takes prompt 210's single
     * *"the club holds their signed consent"* confirmation and records the channel and the operator. That is a
     * compliance decision, reasoned and recorded in 210, and this branch does not undo it — it names it, so
     * the field-parity guard can exclude it explicitly rather than silently.
     *
     * @return array{public: list<string>, staff: list<string>}
     */
    public static function consentFields(): array
    {
        return [
            'public' => ['consent_data', 'consent_statutes'],
            'staff' => ['altaConsentHeld'],
        ];
    }

    /**
     * A blank staff form — every fact, empty.
     *
     * Derived, so a field added to `facts()` appears in the staff form's state and validation with no second
     * edit. That is the half of "impossible to add a field to one route only" that code can enforce; the
     * other half — that both forms RENDER it — is enforced by `OneApplicationFormTest`, which reads the two
     * templates and fails if either drifts from this declaration.
     *
     * @return array<string, mixed>
     */
    public static function blankStaffForm(): array
    {
        $blank = [];

        foreach (array_keys(self::facts()) as $field) {
            // The signature is not a text field the operator types — it lives on its own property, drawn by
            // the pad (prompt 220). Seeding a blank one here would put an empty `signature` key in the form
            // array, and `$data + [...]` keeps the LEFT operand's keys, so the drawn one would be discarded.
            if ($field === self::SIGNATURE_FIELD) {
                continue;
            }

            $blank[$field] = $field === 'is_therapeutic' ? false : '';
        }

        return $blank;
    }
}
