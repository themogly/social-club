<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Web Push subscription. Polymorphic `subscribable` (a Member, via ULID) so the
 * web-push channel wires to it in prompt 15.
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'subscribable_type', 'subscribable_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding',
    ];

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
