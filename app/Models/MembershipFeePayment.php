<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FeePaymentMethod;
use Database\Factories\MembershipFeePaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fee income is a first-class income type — reported separately from contributions. */
class MembershipFeePayment extends Model
{
    /** @use HasFactory<MembershipFeePaymentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'membership_id', 'amount_cents', 'method', 'till_session_id',
        'paid_at', 'recorded_by', 'instalment_of',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'method' => FeePaymentMethod::class,
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /** @return BelongsTo<TillSession, $this> */
    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
