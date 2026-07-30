<?php

namespace Database\Factories;

use App\Enums\DispensationStatus;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispensation>
 */
class DispensationFactory extends Factory
{
    protected $model = Dispensation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Invariant: cash_cents + wallet_cents must equal total_cents.
        $total = fake()->numberBetween(500, 20000);

        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'location_id' => Location::factory(),
            'operator_id' => null,
            'till_session_id' => null,
            'total_cents' => $total,
            'cash_cents' => $total,
            'wallet_cents' => 0,
            'status' => DispensationStatus::COMPLETED,
            'reversal_of_id' => null,
            'void_reason' => null,
            'voided_by' => null,
            'voided_at' => null,
            'signature_path' => null,
            'idempotency_key' => fake()->uuid(),
            'reference' => fake()->bothify('DISP-#####'),
            'dispensed_at' => now(),
        ];
    }
}
