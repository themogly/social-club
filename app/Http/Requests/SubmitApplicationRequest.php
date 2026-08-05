<?php

namespace App\Http\Requests;

use App\Enums\IdDocumentType;
use App\Support\MemberEligibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
    public function rules(): array
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
            // Optional identity photo (prompt 157) — NEVER required (an applicant who cannot upload must still
            // be able to apply). It is checked against them at the counter; the form copy says so.
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            'is_therapeutic' => ['sometimes', 'boolean'],
            'declared_monthly_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            // Sponsor by NAME or number (prompt 97): a prospect knows the person, not their member number.
            // Never required at the form — the avalador_policy is enforced (and waived) at approval, so an
            // applicant who cannot supply one is not silently blocked here.
            'avalador_ref' => ['nullable', 'string', 'max:255'],
            // Two SEPARATE consents (prompt 97): data processing and the statutes are different agreements,
            // and two ticks are stronger evidence than one bundled box. Both are required.
            'consent_data' => ['accepted'],
            'consent_statutes' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => __('nombre'),
            'last_name' => __('apellidos'),
            'email' => __('correo'),
            'date_of_birth' => __('fecha de nacimiento'),
            'document_type' => __('tipo de documento'),
            'document_number' => __('número de documento'),
            'consent_data' => __('consentimiento de tratamiento de datos'),
            'consent_statutes' => __('aceptación de los estatutos'),
        ];
    }
}
