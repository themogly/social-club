<?php

namespace Tests\Feature\Wallet;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Prompt 49 — the nightly cross-location settlement sweep wired to the (previously dead) AutoSettleDebt
 * + TransferCredit. The sweep runs in the SCHEDULER (no ambient scope), so every test clears the org
 * scope before the run to prove the command resolves organisations and ring-fencing on its own — the
 * subtle correctness point, since Settings::get resolves the per-location `ring_fenced` THROUGH the org.
 */
class CrossLocationSettlementTest extends TestCase
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
        $recorder->handle($this->member, $this->debtSite, -2000, WalletTransactionType::CONTRIBUTION);
    }

    /** Simulate the scheduler: no organisation in scope when the command fires. */
    private function runAsScheduler(): void
    {
        app(ActiveScope::class)->setOrganisation(null);
        Artisan::call('wallet:settle-cross-location');
    }

    public function test_the_nightly_sweep_clears_cross_location_debt(): void
    {
        $this->runAsScheduler();

        $this->assertSame(0, Wallet::balance($this->member->id, $this->debtSite->id));       // debt cleared
        $this->assertSame(3000, Wallet::balance($this->member->id, $this->creditSite->id));   // credit reduced
    }

    public function test_the_sweep_is_idempotent_under_a_double_fire(): void
    {
        $this->runAsScheduler();
        $this->runAsScheduler(); // nothing left to settle

        $this->assertSame(0, Wallet::balance($this->member->id, $this->debtSite->id));
        $this->assertSame(3000, Wallet::balance($this->member->id, $this->creditSite->id));
        // Exactly one transfer pair — the second run moved no extra money.
        $this->assertSame(2, WalletTransaction::withoutGlobalScopes()
            ->whereIn('type', [WalletTransactionType::TRANSFER_IN->value, WalletTransactionType::TRANSFER_OUT->value])
            ->count());
    }

    public function test_the_sweep_skips_a_ring_fenced_debt_site(): void
    {
        Settings::set('ring_fenced', true, SettingType::BOOL, $this->debtSite->id);

        $this->runAsScheduler();

        // If the command failed to set the org scope, ring_fenced would resolve to its default (false)
        // and this debt would be wrongly settled. It must stay untouched.
        $this->assertSame(-2000, Wallet::balance($this->member->id, $this->debtSite->id));
        $this->assertSame(5000, Wallet::balance($this->member->id, $this->creditSite->id));
    }

    public function test_each_transfer_is_audited(): void
    {
        $this->runAsScheduler();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'wallet.transferred',
            'auditable_id' => $this->member->id,
        ]);
    }
}
