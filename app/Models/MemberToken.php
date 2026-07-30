<?php

namespace App\Models;

use Database\Factories\MemberTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A revocable/regenerable QR-card token (stored hashed) — never a derivation of
 * the member id, so a card can be reissued without changing the member.
 */
class MemberToken extends Model
{
    /** @use HasFactory<MemberTokenFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'member_id', 'token_hash', 'purpose', 'issued_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
