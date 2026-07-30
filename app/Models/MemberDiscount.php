<?php

namespace App\Models;

use App\Enums\DiscountMode;
use Database\Factories\MemberDiscountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discount assigned to a member — a linked Discount, or an inline custom value. */
class MemberDiscount extends Model
{
    /** @use HasFactory<MemberDiscountFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'member_id', 'discount_id', 'mode', 'value_bp', 'value_cents', 'assigned_by', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => DiscountMode::class,
            'value_bp' => 'integer',
            'value_cents' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
