<?php

namespace App\Actions\Documents;

use App\Actions\RecordAuditLog;
use App\Models\Minute;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Sign (close) an acta. Signing sets signed_at AND the signatory (signed_by) once, in the same save;
 * from then the model refuses any update or delete (Minute::booted), so neither the signature nor WHO
 * signed can ever be changed. A correction is never an edit — it is a new minute created with
 * supersedes_id pointing back at this one, signed afresh by whoever signs it.
 *
 * Signing is gated on `minute.sign`, deliberately NARROWER than `minutes.manage` (drafting): attributing
 * a legal signature is the signing authority's act (owner-only by default), not every minute editor's.
 */
class SignMinute
{
    public function handle(Minute $minute, User $actor): Minute
    {
        if (! $actor->can('minute.sign')) {
            throw new AuthorizationException('Signing an acta requires the minute.sign permission.');
        }

        if ($minute->signed_at !== null) {
            throw new RuntimeException('This acta is already signed.');
        }

        $minute->signed_at = now();
        $minute->signed_by = $actor->id;
        $minute->save();

        (new RecordAuditLog)->handle('minute.signed', $minute, null, [
            'book' => $minute->book->value,
            'number' => $minute->number,
            'signed_by' => $actor->id,
            'signatory' => $actor->name,
        ]);

        return $minute;
    }
}
