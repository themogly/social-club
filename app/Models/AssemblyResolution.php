<?php

namespace App\Models;

use App\Enums\ResolutionResult;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\AssemblyResolutionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agenda item's resolution at an assembly — the outcome and the show-of-hands counts. Keyed by position
 * within the convocatoria, so re-recording an item corrects it rather than duplicating. Written only through
 * App\Actions\Governance\RecordResolution, and snapshotted into the acta by DraftAssemblyMinute.
 */
class AssemblyResolution extends Model
{
    /** @use HasFactory<AssemblyResolutionFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'convocatoria_id', 'position', 'title', 'result',
        'votes_for', 'votes_against', 'votes_abstain', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'result' => ResolutionResult::class,
            'position' => 'integer',
            'votes_for' => 'integer',
            'votes_against' => 'integer',
            'votes_abstain' => 'integer',
        ];
    }

    /** @return BelongsTo<Convocatoria, $this> */
    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
