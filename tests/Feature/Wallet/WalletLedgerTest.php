<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\WalletTransactionType;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
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

    public function test_movements_track_the_balance_and_the_ledger_sum_equals_the_balance(): void
    {
        $recorder = new RecordWalletTransaction;

        $topup = $recorder->handle($this->member, $this->location, 5000, WalletTransactionType::TOPUP);
        $this->assertSame(5000, Wallet::balance($this->member->id, $this->location->id));
        $this->assertSame(5000, $topup->balance_after_cents->cents);
        $this->assertSame(WalletTransactionType::TOPUP, $topup->type);

        $recorder->handle($this->member, $this->location, -2000, WalletTransactionType::CONTRIBUTION);
        $this->assertSame(3000, Wallet::balance($this->member->id, $this->location->id));

        $refund = $recorder->handle($this->member, $this->location, -3000, WalletTransactionType::REFUND);
        $this->assertSame(0, Wallet::balance($this->member->id, $this->location->id));

        // The derived balance always equals the last row's balance_after.
        $this->assertSame(Wallet::balance($this->member->id, $this->location->id), $refund->balance_after_cents->cents);
    }
}
