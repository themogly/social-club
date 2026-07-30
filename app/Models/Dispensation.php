<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DispensationStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\DispensationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/** Basket header — one multi-line withdrawal = one document, one payment, one void. */
class Dispensation extends Model
{
    /** @use HasFactory<DispensationFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    /** @var array<string, int> Money columns are non-null (DB default 0); mirror that in memory. */
    protected $attributes = [
        'total_cents' => 0,
        'cash_cents' => 0,
        'wallet_cents' => 0,
    ];

    protected $fillable = [
        'organisation_id', 'member_id', 'location_id', 'operator_id', 'till_session_id',
        'total_cents', 'cash_cents', 'wallet_cents', 'status', 'reversal_of_id',
        'void_reason', 'voided_by', 'voided_at', 'signature_path', 'idempotency_key',
        'reference', 'dispensed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_cents' => MoneyCast::class,
            'cash_cents' => MoneyCast::class,
            'wallet_cents' => MoneyCast::class,
            'status' => DispensationStatus::class,
            'voided_at' => 'datetime',
            'dispensed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Model invariant: the tender split must reconcile to the total. All three
        // money columns are non-null (default 0), so no nullsafe is needed.
        static::saving(function (Dispensation $dispensation): void {
            $split = $dispensation->cash_cents->cents + $dispensation->wallet_cents->cents;

            if ($split !== $dispensation->total_cents->cents) {
                throw new RuntimeException('Dispensation tender split (cash + wallet) must equal the total.');
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

    /** @return HasMany<DispensationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DispensationLine::class);
    }

    /** @return BelongsTo<Dispensation, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DispensationStatus::COMPLETED);
    }
}
