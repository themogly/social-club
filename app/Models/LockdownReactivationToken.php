<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use owner reactivation token (prompt 121) — the "owner trust" way back. Only the sha256 hash is
 * stored; the raw token lives solely in the emailed link, so possessing the row (a stolen DB) does not let you
 * reactivate. Consumed inside a locked transaction so a link cannot be replayed.
 */
class LockdownReactivationToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'organisation_lockdown_id', 'user_id', 'token_hash', 'expires_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<OrganisationLockdown, $this> */
    public function lockdown(): BelongsTo
    {
        return $this->belongsTo(OrganisationLockdown::class, 'organisation_lockdown_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
