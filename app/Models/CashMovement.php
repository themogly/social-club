<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CashMovementType;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'till_session_id', 'amount_cents', 'type', 'reason', 'operator_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'type' => CashMovementType::class,
        ];
    }

    /** @return BelongsTo<TillSession, $this> */
    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
