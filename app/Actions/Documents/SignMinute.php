<?php

namespace App\Actions\Documents;

use App\Actions\RecordAuditLog;
use App\Models\Minute;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Sign (close) an acta. Signing sets signed_at once; from then the model refuses
 * any update or delete (Minute::booted). A correction is never an edit — it is a
 * new minute created with supersedes_id pointing back at this one.
 */
class SignMinute
{
    public function handle(Minute $minute, User $actor): Minute
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Signing minutes requires the minutes.manage permission.');
        }

        if ($minute->signed_at !== null) {
            throw new RuntimeException('This acta is already signed.');
        }

        $minute->signed_at = now();
        $minute->save();

        (new RecordAuditLog)->handle('minute.signed', $minute, null, [
            'book' => $minute->book->value,
            'number' => $minute->number,
        ]);

        return $minute;
    }
}
