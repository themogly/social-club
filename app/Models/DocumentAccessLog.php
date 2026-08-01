<?php

namespace App\Models;

use Database\Factories\DocumentAccessLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "Who opened whose passport scan" — one query, separate from the general audit trail. Prompt 113 widened it
 * beyond MemberDocument: a member photo or a POS signature view records a polymorphic (subject_type,
 * subject_id) instead of a member_document_id, so every Article-9 file view is logged the same way.
 */
class DocumentAccessLog extends Model
{
    /** @use HasFactory<DocumentAccessLogFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'actor_id', 'member_document_id', 'subject_type', 'subject_id', 'viewed_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<MemberDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'member_document_id');
    }

    /**
     * The photo/signature subject (Member or Dispensation), when not a MemberDocument view.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
