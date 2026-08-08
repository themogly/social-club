<?php

namespace App\Http\Requests;

use App\Support\ApplicationShape;
use App\Support\DocumentUpload;
use App\Support\MemberEligibility;
use App\Support\MrzPrefill;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a prospect's application, submitted on their phone via a tokenised invite
 * link. The invite token itself is authorised at the route/controller (a valid,
 * unconsumed token), so this request only shapes the payload. The AUTHORITATIVE age
 * gate re-runs server-side at approval (App\Actions\Members\ApproveApplication) — this
 * reuses the same MemberEligibility helper for immediate feedback, never a second copy.
 */
class SubmitApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The rules for an application's FACTS, shared with the counter's staff-typed route (prompt 210).
     *
     * Static and separate so there is genuinely ONE validator: 174's argument was that the audited route is
     * the open one, and a staff form with its own copy of these rules would be a second route wearing the
     * first one's name. The counter's form calls this and adds only the consent-channel rules it needs; the
     * two consent ticks below stay HERE because they are the public form's own artefact.
     *
     * @return array<string, mixed>
     */
    /**
     * The rules for an application's FACTS, shared with the counter's staff-typed route (prompt 210) and
     * **declared once** since prompt 215.
     *
     * `App\Support\ApplicationShape` is now the single source. There used to be two hand-written field
     * lists — these rules and `BLANK_ALTA_FORM` in `SignsUpMembers` — with nothing making them agree, so 210's
     * staff route reached the same writer with a third of the form missing. Both derive from the declaration
     * now, and a test reads both templates and fails if either drifts from it.
     *
     * @return array<string, mixed>
     */
    public static function factRules(): array
    {
        return array_merge(ApplicationShape::facts(), ApplicationShape::files());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(self::factRules(), [
            // Two SEPARATE consents (prompt 97): data processing and the statutes are different agreements,
            // and two ticks are stronger evidence than one bundled box. Both are required.
            'consent_data' => ['accepted'],
            'consent_statutes' => ['accepted'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Prompt 179 — the form cannot be submitted while a prefilled field is UNCONFIRMED, and this is
            // the server-side half of that. The browser marks each field it filled; the applicant confirms
            // or corrects each one. Enforcing it only in the page would make the confirmation decorative,
            // and the confirmation is the entire reason an imperfect reader is safe to ship.
            $confirmed = (array) $this->input('mrz_confirmed', []);

            foreach (MrzPrefill::fields((string) $this->route('token')) as $field) {
                if (! filled($confirmed[$field] ?? null)) {
                    $validator->errors()->add($field, __('Comprueba este dato leído del documento y confírmalo.'));
                }
            }
        });

        $validator->after(function (Validator $validator): void {
            $dob = $this->input('date_of_birth');
            if (is_string($dob) && $dob !== '' && ! MemberEligibility::isOldEnough($dob)) {
                $validator->errors()->add('date_of_birth', __('Debes ser mayor de :age años para solicitar el alta.', [
                    'age' => MemberEligibility::minimumAge(),
                ]));
            }
        });
    }

    /**
     * The applicant is on a phone, on an emailed link, with nobody to ask. A rejected photo has to
     * say what was wrong and what to do about it (prompt 164) — and it must read as a sentence
     * whatever the state of the framework's validation lines, which is why it is spelled out here
     * rather than left to `validation.max.file`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.max' => __('La foto es demasiado grande (máximo :size). Prueba con una foto más pequeña.', [
                'size' => DocumentUpload::limitLabel(),
            ]),
            'document_scan.max' => __('El documento es demasiado grande (máximo :size). Prueba con un archivo más pequeño.', [
                'size' => DocumentUpload::limitLabel(),
            ]),
            'document_scan.mimes' => __('El documento debe ser una imagen (JPG, PNG, WEBP) o un PDF.'),
        ];
    }

    // Field names moved to lang/*/validation.php `attributes` (prompt 169). They lived here and were
    // INERT — `:attribute` is interpolated from the validation lines, and that file did not exist — so
    // the work was invisible. In the shared file they apply to every form in the product, not just this
    // one, which is what a prospect reading "El campo número de documento es obligatorio" needs.
}
