<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, short-lived passwordless login token for the member PWA. Stored as a
 * SHA-256 hash only; consumed exactly once (used_at) before it expires.
 */
class MemberLoginToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'member_id', 'token_hash', 'expires_at', 'used_at', 'requested_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
