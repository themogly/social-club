<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\AutoSettleDebt;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RingFenceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Member $member;

    private Location $creditSite;

    private Location $debtSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 10000, SettingType::CENTS);

        $this->member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $this->creditSite = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->debtSite = Location::factory()->create(['organisation_id' => $this->org->id]);

        $recorder = new RecordWalletTransaction;
        $recorder->handle($this->member, $this->creditSite, 5000, WalletTransactionType::TOPUP);
        $recorder->handle($this->member, $this->debtSite, -2000, WalletTransactionType::CONTRIBUTION); // debt
    }

    public function test_credit_at_an_unfenced_site_auto_clears_debt_elsewhere(): void
    {
        (new AutoSettleDebt)->handle($this->member, $this->creditSite);

        $this->assertSame(0, Wallet::balance($this->member->id, $this->debtSite->id));   // debt cleared
        $this->assertSame(3000, Wallet::balance($this->member->id, $this->creditSite->id)); // credit reduced
        $this->assertDatabaseHas('wallet_transactions', ['type' => WalletTransactionType::TRANSFER_IN->value]);
        $this->assertDatabaseHas('wallet_transactions', ['type' => WalletTransactionType::TRANSFER_OUT->value]);
    }

    public function test_a_ring_fenced_debt_site_does_not_auto_settle(): void
    {
        $this->debtSite->update(['settings' => ['ring_fenced' => true]]);

        (new AutoSettleDebt)->handle($this->member, $this->creditSite);

        $this->assertSame(-2000, Wallet::balance($this->member->id, $this->debtSite->id));   // still in debt
        $this->assertSame(5000, Wallet::balance($this->member->id, $this->creditSite->id));  // untouched
    }
}
