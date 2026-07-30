<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Bar/merch sale — its own ledger, same drawer. Tender split reconciles to the total. */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    /** @var array<string, int> Money columns are non-null (DB default 0); mirror that in memory. */
    protected $attributes = [
        'total_cents' => 0,
        'cash_cents' => 0,
        'wallet_cents' => 0,
    ];

    protected $fillable = [
        'organisation_id', 'location_id', 'member_id', 'operator_id', 'till_session_id',
        'items', 'total_cents', 'cash_cents', 'wallet_cents', 'status', 'reversal_of_id',
        'void_reason', 'voided_by', 'voided_at', 'idempotency_key', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => MoneyCast::class,
            'cash_cents' => MoneyCast::class,
            'wallet_cents' => MoneyCast::class,
            'status' => OrderStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Money columns are non-null (default 0), so no nullsafe is needed.
        static::saving(function (Order $order): void {
            $split = $order->cash_cents->cents + $order->wallet_cents->cents;

            if ($split !== $order->total_cents->cents) {
                throw new RuntimeException('Order tender split (cash + wallet) must equal the total.');
            }
        });
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

    /** @return BelongsTo<Order, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
