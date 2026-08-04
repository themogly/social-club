<?php

namespace App\Support;

use App\Models\AssemblyAttendance;
use App\Models\Convocatoria;

/**
 * The quorum arithmetic for an assembly, computed LIVE from the frozen roll and the recorded attendance —
 * never stored. First call needs the fraction frozen onto the convocatoria at issue (quorum_required, so the
 * threshold matches the roll that was actually convened). Second call needs the configured second-call
 * fraction of that same frozen roll — commonly 0 in a Spanish asociación, where the assembly is validly
 * constituted on second call whatever the number of attendees. Both PRESENT and PROXY count as present.
 */
class AssemblyQuorum
{
    public function __construct(
        public readonly int $roll,
        public readonly int $present,
        public readonly int $firstCallRequired,
        public readonly int $secondCallRequired,
    ) {}

    public static function forConvocatoria(Convocatoria $convocatoria): self
    {
        $roll = (int) $convocatoria->roll_count;

        $present = AssemblyAttendance::query()
            ->where('convocatoria_id', $convocatoria->id)
            ->count();

        $secondBp = (int) Settings::get('assembly_second_call_quorum_bp', 0);

        return new self(
            roll: $roll,
            present: $present,
            firstCallRequired: (int) $convocatoria->quorum_required,
            secondCallRequired: (int) ceil($roll * $secondBp / 10000),
        );
    }

    public function firstCallMet(): bool
    {
        return $this->present >= $this->firstCallRequired;
    }

    public function secondCallMet(): bool
    {
        return $this->present >= $this->secondCallRequired;
    }

    /** Validly constituted if the first-call quorum is met, or the second-call quorum is (the fallback). */
    public function isConstituted(): bool
    {
        return $this->firstCallMet() || $this->secondCallMet();
    }
}
