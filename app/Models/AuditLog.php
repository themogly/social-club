<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Append-only audit trail. No update or delete path through the model.
 *
 * @property-read string|null $auditable_type
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'actor_id', 'action', 'auditable_type', 'auditable_id',
        'before', 'after', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * The ONLY sanctioned reason to touch an existing audit row: a RGPD-Art.17 REDACTION that masks
     * special-category values out of a `before`/`after` payload (prompt 76). It is not a general edit path —
     * only the payloads may change, the entry's identity (actor, action, subject, date, IP) is frozen — and
     * it is itself audited. Set only inside App\Actions\Members\RedactMemberAuditLogs::withRedaction().
     */
    public static bool $redacting = false;

    protected static function booted(): void
    {
        static::updating(function (AuditLog $log): void {
            if (! self::$redacting) {
                throw new RuntimeException('The audit log is append-only and cannot be updated.');
            }

            // During a redaction ONLY the before/after payloads may be masked — never the entry's identity.
            $frozen = array_diff(array_keys($log->getDirty()), ['before', 'after']);
            if ($frozen !== []) {
                throw new RuntimeException('A redaction may only mask before/after, not: '.implode(', ', $frozen));
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('The audit log is append-only and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
