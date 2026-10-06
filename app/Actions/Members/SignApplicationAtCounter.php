<?php

namespace App\Actions\Members;

use App\Actions\RecordAuditLog;
use App\Enums\ConsentChannel;
use App\Models\MemberApplication;
use App\Support\DocumentVault;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Prompt 361 — «Firmar ahora»: the tablet came back from a handover unsigned, and the member signs on the staff screen
 * there and then. The drawing is attached to the application exactly as a submitted one is — `signatures/{ulid}.png`
 * in the vault, encrypted on the private disk — and, being the member's own act, it makes the consent SIGNED, as
 * `SubmitApplication` does for a signature drawn on the form.
 */
class SignApplicationAtCounter
{
    public function handle(MemberApplication $application, string $dataUrl): void
    {
        $prefix = 'data:image/png;base64,';
        $binary = str_starts_with($dataUrl, $prefix) ? base64_decode(substr($dataUrl, strlen($prefix)), true) : false;

        if ($binary === false || $binary === '') {
            throw new RuntimeException(__('Falta la firma.'));
        }

        $path = 'signatures/'.Str::ulid().'.png';
        DocumentVault::put($path, $binary);

        $payload = $application->payload ?? [];
        $payload['signature_path'] = $path;
        $payload['consent_channel'] = ConsentChannel::SIGNED->value;
        unset($payload['consent_attested_by']);

        $application->update(['payload' => $payload, 'signature_missing' => false]);
        (new RecordAuditLog)->handle('application.signed_at_counter', $application, null, ['signature_path' => $path]);
    }
}
