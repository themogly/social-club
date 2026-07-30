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
            'is_therapeutic' => ['sometimes', 'boolean'],
            'declared_monthly_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'avalador_member_no' => ['nullable', 'string', 'max:64'],
            'consent' => ['accepted'],
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
            'consent' => __('consentimiento'),
        ];
    }
}
