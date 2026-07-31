<?php

namespace App\Actions\Till;

use App\Enums\CashMovementType;
use App\Exceptions\TillClosedException;
use App\Models\CashMovement;
use App\Models\TillSession;
use App\Support\CounterOperator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Record a cash movement on an open session. Stored SIGNED (IN adds, OUT/BANKED/
 * PETTY_CASH subtract) so the expected drawer cash is a plain sum. Attributed to
 * the PIN-identified operator.
 */
class RecordCashMovement
{
    /**
     * @param  array{reason?: ?string, operator_id?: ?string}  $options
     */
    public function handle(TillSession $session, CashMovementType $type, int $magnitude, array $options = []): CashMovement
    {
        $signed = $type === CashMovementType::IN ? abs($magnitude) : -abs($magnitude);

        // Contend on the session row lock (prompt 77) so a movement can't be inserted against a session that
        // CloseTill is mid-close on: either this commits first (counted in the arqueo) or the re-read status
        // is CLOSED and it is refused — never silently excluded from the immutable expected figure.
        return DB::transaction(function () use ($session, $signed, $type, $options): CashMovement {
            $locked = TillSession::withoutGlobalScopes()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->value !== 'OPEN') {
                throw new TillClosedException('Cannot record a movement on a closed till session.');
            }

            return CashMovement::create([
                'till_session_id' => $locked->id,
                'amount_cents' => $signed,
                'type' => $type,
                'reason' => $options['reason'] ?? null,
                'operator_id' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
            ]);
        });
    }
}
