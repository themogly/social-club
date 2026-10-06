<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Models\MemberApplication;
use App\Models\User;
use App\Support\ManagerApproval;
use RuntimeException;

/**
 * Prompt 361 — «Seguir sin firma». Ben: "if it's playing up, let the staff override if there's no signature when it's
 * handed back". Staff may (`applications.review`, checked by the caller); a one-tap reason is required, except from a
 * holder of `reasons.optional` (356), whose waiver records «Aprobado por responsable». Who, when and why are stored on
 * the application and audited; the member record then reads «Sin firma digital — autorizado por …», so it can be
 * collected later. Only the signature is waived — the consent ticks and their text versions are untouched.
 */
class WaiveApplicationSignature
{
    public const REASONS = ['TABLET', 'PAPER', 'OTHER'];

    /** @return array<string, string> the one-tap reasons, keyed */
    public static function reasons(): array
    {
        return [
            'TABLET' => __('La tableta no funcionaba'),
            'PAPER' => __('Firmará en papel'),
            'OTHER' => __('Otro'),
        ];
    }

    public function handle(MemberApplication $application, User $actor, ?string $reasonKey, ?string $text = null): void
    {
        if (! $application->awaitsSignature()) {
            throw new RuntimeException(__('Esta solicitud no está pendiente de firma.'));
        }

        $reason = match ($reasonKey) {
            'TABLET', 'PAPER' => self::reasons()[$reasonKey],
            'OTHER' => trim((string) $text) !== '' ? mb_substr(trim((string) $text), 0, 255) : null,
            default => null,
        };
        $byPermission = $reason === null && ManagerApproval::allows($actor);
        $reason ??= $byPermission ? ManagerApproval::reason() : null;

        if ($reason === null) {
            throw new RuntimeException(__('Indica por qué sigue sin firma.'));
        }

        $application->update([
            'signature_override_by' => $actor->id,
            'signature_override_reason' => $reason,
            'signature_override_at' => now(),
        ]);

        (new RecordAuditLog)->handle('application.signature_waived', $application, null, array_filter([
            'reason' => $reason,
            ManagerApproval::AUDIT_KEY => $byPermission ? ManagerApproval::PERMISSION : null,
        ]));
    }
}
