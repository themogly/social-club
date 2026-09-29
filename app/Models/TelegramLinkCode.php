<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prompt 311 — a one-time Telegram link code, stored as its SHA-256 only: 10 minutes, single use. */
class TelegramLinkCode extends Model
{
    public const MINUTES = 10;

    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
