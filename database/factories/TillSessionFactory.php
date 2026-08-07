<?php

namespace Database\Factories;

use App\Enums\TillSessionStatus;
use App\Enums\TillShiftStatus;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\TillShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TillSession>
 */
class TillSessionFactory extends Factory
{
    protected $model = TillSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'location_id' => Location::factory(),
            'terminal' => fake()->bothify('TILL-##'),
            'opened_by' => null,
            'opened_at' => now(),
            'float_cents' => fake()->numberBetween(0, 50000),
            'closed_by' => null,
            'closed_at' => null,
            'counted_cents' => null,
            'expected_cents' => null,
            'variance_cents' => null,
            'status' => TillSessionStatus::OPEN,
            'notes' => null,
        ];
    }

    /**
     * Prompt 186 — an OPEN session gets its opening shift, exactly as `OpenTill` gives it one.
     *
     * Without this the factory produces a shape the real writer never produces — a drawer nobody holds —
     * and every fixture would then be testing a state that cannot occur in production while the commit
     * gate refuses it. That is the drift CLAUDE.md records against `DemoDataSeeder`: a green test and a
     * working-looking screen both certifying a shape production never makes.
     *
     * `opened_by` is nullable on the factory, so a shift is only created when there is somebody to
     * attribute it to — an unattributed shift would be worse than none.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (TillSession $session): void {
            if ($session->status === TillSessionStatus::OPEN && $session->opened_by !== null) {
                TillShift::create([
                    'organisation_id' => $session->organisation_id,
                    'till_session_id' => $session->id,
                    'opened_by' => $session->opened_by,
                    'opened_at' => $session->opened_at ?? now(),
                    'opening_counted_cents' => (int) $session->getRawOriginal('float_cents'),
                    'opening_expected_cents' => (int) $session->getRawOriginal('float_cents'),
                    'status' => TillShiftStatus::OPEN,
                ]);
            }
        });
    }
}
