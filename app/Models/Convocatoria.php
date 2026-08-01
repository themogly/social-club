<?php

namespace App\Models;

use App\Enums\ConvocatoriaType;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\ConvocatoriaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A convocatoria de asamblea — the formal notice convening a general assembly (prompt 88). While a DRAFT
 * (issued_at null) it can be edited; once ISSUED it is immutable and its frozen recipient roll
 * (convocatoria_recipients) is the evidence of who was convened. Issuing is owned by
 * App\Actions\Governance\IssueConvocatoria (freezes the roll + queues one email per member) — nothing else
 * sets issued_at.
 */
class Convocatoria extends Model
{
    /** @use HasFactory<ConvocatoriaFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'location_id', 'type', 'title', 'agenda', 'body', 'venue',
        'held_at', 'second_call_at', 'notice_days', 'quorum_required', 'roll_count',
        'issued_at', 'issued_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConvocatoriaType::class,
            'agenda' => 'array',
            'held_at' => 'datetime',
            'second_call_at' => 'datetime',
            'notice_days' => 'integer',
            'quorum_required' => 'integer',
            'roll_count' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Immutable once issued: the notice has gone out and the roll is frozen, so neither the notice nor
        // its terms may change afterwards. A change of plan is a NEW convocatoria, not an edit.
        static::updating(function (Convocatoria $convocatoria): void {
            if ($convocatoria->getOriginal('issued_at') !== null) {
                throw new RuntimeException('An issued convocatoria cannot be modified.');
            }
        });

        static::deleting(function (Convocatoria $convocatoria): void {
            if ($convocatoria->issued_at !== null && ! $convocatoria->isForceDeleting()) {
                throw new RuntimeException('An issued convocatoria cannot be deleted.');
            }
        });
    }

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<ConvocatoriaRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(ConvocatoriaRecipient::class);
    }

    /** @return HasMany<Minute, $this> */
    public function minutes(): HasMany
    {
        return $this->hasMany(Minute::class);
    }
}
