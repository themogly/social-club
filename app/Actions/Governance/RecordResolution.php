<?php

namespace App\Actions\Governance;

use App\Enums\ResolutionResult;
use App\Models\AssemblyResolution;
use App\Models\Convocatoria;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Record (or correct) one agenda item's resolution — the outcome plus the show-of-hands counts. Keyed by
 * position within the assembly, so re-recording the same item corrects it rather than duplicating. Only on an
 * issued convocatoria. Votes are show-of-hands totals, not per-member ballots (§design: show of hands only).
 */
class RecordResolution
{
    public function handle(
        Convocatoria $convocatoria,
        int $position,
        string $title,
        ResolutionResult $result,
        int $votesFor,
        int $votesAgainst,
        int $votesAbstain,
        User $actor,
    ): AssemblyResolution {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Recording an assembly resolution requires the minutes.manage permission.');
        }

        if (! $convocatoria->isIssued()) {
            throw new RuntimeException('Resolutions can only be recorded for an issued convocatoria.');
        }

        if (trim($title) === '') {
            throw new RuntimeException('A resolution must state the agenda item it decides.');
        }

        return AssemblyResolution::updateOrCreate(
            ['convocatoria_id' => $convocatoria->id, 'position' => $position],
            [
                'organisation_id' => $convocatoria->organisation_id,
                'title' => trim($title),
                'result' => $result,
                'votes_for' => max(0, $votesFor),
                'votes_against' => max(0, $votesAgainst),
                'votes_abstain' => max(0, $votesAbstain),
                'recorded_by' => $actor->id,
            ],
        );
    }
}
