<?php

namespace App\Actions\Till;

use App\Enums\CashMovementType;
use App\Exceptions\TillClosedException;
use App\Models\CashMovement;
use App\Models\TillSession;
use App\Support\CounterOperator;
use Illuminate\Support\Facades\Auth;

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
        if ($session->status->value !== 'OPEN') {
            throw new TillClosedException('Cannot record a movement on a closed till session.');
        }

        $signed = $type === CashMovementType::IN ? abs($magnitude) : -abs($magnitude);

        return CashMovement::create([
            'till_session_id' => $session->id,
            'amount_cents' => $signed,
            'type' => $type,
            'reason' => $options['reason'] ?? null,
            'operator_id' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
        ]);
    }
}
