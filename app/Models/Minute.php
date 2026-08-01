<?php

namespace App\Models;

use App\Enums\MinuteBook;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MinuteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** An acta. Once signed it is immutable — no update or delete through the model. */
class Minute extends Model
{
    /** @use HasFactory<MinuteFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'book', 'number', 'type', 'held_on', 'location_id',
        'agenda', 'resolutions', 'attendees', 'quorum_present', 'quorum_required',
        'body', 'signed_at', 'signed_by', 'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'book' => MinuteBook::class,
            'number' => 'integer',
            'held_on' => 'date',
            'agenda' => 'array',
            'resolutions' => 'array',
            'attendees' => 'array',
            'quorum_present' => 'integer',
            'quorum_required' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Immutable once signed. Signing (setting signed_at the first time) is allowed;
        // any later change or delete of a signed acta is refused.
        static::updating(function (Minute $minute): void {
            if ($minute->getOriginal('signed_at') !== null) {
                throw new RuntimeException('A signed minute (acta) cannot be modified.');
            }
        });

        static::deleting(function (Minute $minute): void {
            if ($minute->signed_at !== null) {
                throw new RuntimeException('A signed minute (acta) cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The user who signed (closed) this acta. Null for actas signed before signatories were recorded,
     * or whose signatory's account was later deleted — reported honestly as "no consta", never invented.
     *
     * @return BelongsTo<User, $this>
     */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** @return BelongsTo<Minute, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
