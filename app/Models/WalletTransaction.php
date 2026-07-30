<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\WalletTransactionType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** The member wallet ledger — per-location balances (checkpoint). Signed amounts. */
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'member_id', 'location_id', 'amount_cents', 'type',
        'balance_after_cents', 'operator_id', 'till_session_id', 'reason',
        'source_type', 'source_id', 'transfer_pair_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'balance_after_cents' => MoneyCast::class,
            'type' => WalletTransactionType::class,
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<TillSession, $this> */
    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transfer_pair_id');
    }
}
