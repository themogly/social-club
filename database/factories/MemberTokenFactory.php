<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberToken>
 */
class MemberTokenFactory extends Factory
{
    protected $model = MemberToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'purpose' => 'QR_CARD',
            'issued_at' => now(),
            'revoked_at' => null,
        ];
    }
}
