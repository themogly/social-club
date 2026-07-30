<?php

namespace Database\Factories;

use App\Enums\WalletTransactionType;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'member_id' => Member::factory(),
            'location_id' => Location::factory(),
            'amount_cents' => fake()->numberBetween(500, 50000),
            'type' => WalletTransactionType::TOPUP,
            'balance_after_cents' => fake()->numberBetween(500, 100000),
            'operator_id' => null,
            'till_session_id' => null,
            'reason' => fake()->sentence(3),
            'source_type' => null,
            'source_id' => null,
            'transfer_pair_id' => null,
        ];
    }
}
