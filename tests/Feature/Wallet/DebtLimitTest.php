<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtLimitTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);
        $this->member = Member::factory()->create(['organisation_id' => $org->id]);
    }

    public function test_debt_is_refused_when_not_allowed(): void
    {
        // Default: debt not allowed. A contribution beyond the balance is refused.
        $this->expectException(DebtLimitExceededException::class);
        (new RecordWalletTransaction)->handle($this->member, $this->location, -1000, WalletTransactionType::CONTRIBUTION);
    }

    public function test_debt_is_allowed_within_the_configured_limit_but_not_beyond(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 5000, SettingType::CENTS);

        $recorder = new RecordWalletTransaction;

        // Within limit: balance -3000 (>= -5000) is allowed.
        $recorder->handle($this->member, $this->location, -3000, WalletTransactionType::CONTRIBUTION);
        $this->assertSame(-3000, Wallet::balance($this->member->id, $this->location->id));

        // Beyond limit: -3000 more → -6000 (< -5000) is refused.
        $this->expectException(DebtLimitExceededException::class);
        $recorder->handle($this->member, $this->location, -3000, WalletTransactionType::CONTRIBUTION);
    }
}
