<?php

namespace App\Actions\Governance;

use App\Actions\Documents\CreateMinute;
use App\Actions\RecordAuditLog;
use App\Enums\ConvocatoriaType;
use App\Enums\MinuteBook;
use App\Models\AssemblyAttendance;
use App\Models\AssemblyResolution;
use App\Models\Convocatoria;
use App\Models\Minute;
use App\Models\User;
use App\Support\AssemblyQuorum;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Draft the acta of an assembly FROM what was recorded — the frozen roll's attendance and each agenda item's
 * resolution — rather than retyped. Reuses CreateMinute (the one acta writer): sequential numbering, quorum
 * at the meeting date and immutability all come from it unchanged. Attendance + resolutions are SNAPSHOTTED
 * into the acta's JSON so the acta stands alone as the record even after the working rows change. Created
 * UNSIGNED — SignMinute closes it; a correction is a new acta via supersedes_id, exactly as any other.
 */
class DraftAssemblyMinute
{
    public function handle(Convocatoria $convocatoria, User $actor): Minute
    {
        if (! $actor->can('minutes.manage')) {
            throw new AuthorizationException('Drafting an acta requires the minutes.manage permission.');
        }

        if (! $convocatoria->isIssued()) {
            throw new RuntimeException('An acta can only be drafted for an issued convocatoria.');
        }

        // Don't draft a second acta while an unsigned draft already exists for this assembly — sign or correct
        // that one. (Correcting a SIGNED acta supersedes it through CreateMinute directly, not through here.)
        $hasDraft = Minute::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->whereNull('signed_at')
            ->exists();
        if ($hasDraft) {
            throw new RuntimeException('A draft acta already exists for this assembly. Sign or correct it instead.');
        }

        $attendances = AssemblyAttendance::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->orderBy('name')
            ->get();

        $resolutions = AssemblyResolution::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->orderBy('position')
            ->get();

        $quorum = AssemblyQuorum::forConvocatoria($convocatoria);

        // Agenda in the acta's existing JSON shape ({ punto: ... }).
        $agenda = array_map(
            fn (string $punto): array => ['punto' => $punto],
            array_values($convocatoria->agenda ?? []),
        );

        // Resolutions in the acta's existing shape, plus a locale-stable 'resultado'.
        $resolutionSnapshot = $resolutions->map(fn (AssemblyResolution $r): array => [
            'texto' => $r->title,
            'resultado' => $r->result->actaTerm(),
            'favor' => $r->votes_for,
            'contra' => $r->votes_against,
            'abstencion' => $r->votes_abstain,
        ])->all();

        $minute = (new CreateMinute)->handle(
            $convocatoria->organisation,
            MinuteBook::ASSEMBLY,
            [
                'type' => $convocatoria->type === ConvocatoriaType::EXTRAORDINARY
                    ? 'Asamblea general extraordinaria'
                    : 'Asamblea general ordinaria',
                'held_on' => $convocatoria->held_at->toDateString(),
                'location_id' => $convocatoria->location_id,
                'agenda' => $agenda,
                'resolutions' => $resolutionSnapshot,
                'attendees' => $attendances->pluck('member_id')->filter()->values()->all(),
                'body' => $this->body($convocatoria, $quorum),
                'convocatoria_id' => $convocatoria->id,
            ],
            $actor,
        );

        (new RecordAuditLog)->handle('assembly.acta_drafted', $minute, null, [
            'convocatoria_id' => $convocatoria->id,
            'present' => $quorum->present,
            'quorum_required' => $quorum->firstCallRequired,
            'first_call_met' => $quorum->firstCallMet(),
            'constituted' => $quorum->isConstituted(),
            'resolutions' => $resolutions->count(),
        ]);

        return $minute;
    }

    /** A short, locale-stable Spanish preamble stating when it was held and whether quorum was reached. */
    private function body(Convocatoria $convocatoria, AssemblyQuorum $quorum): string
    {
        $held = $convocatoria->held_at->format('d/m/Y H:i');
        $constitution = $quorum->firstCallMet()
            ? 'quórum alcanzado en primera convocatoria'
            : ($quorum->isConstituted()
                ? 'quórum de primera convocatoria no alcanzado; constituida en segunda convocatoria'
                : 'quórum no alcanzado');

        return "Asamblea celebrada el {$held}. Asistentes: {$quorum->present} "
            ."(quórum requerido en primera convocatoria: {$quorum->firstCallRequired}) — {$constitution}.";
    }
}
