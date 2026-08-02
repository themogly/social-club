<?php

namespace Tests\Feature\Counter;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Models\CheckIn;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\Settings;
use App\Support\VerdictRemedy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 135 — a fired eligibility rule is named in the member's own terms (dates, amounts) with the fix beside
 * it, or a plain "who/when" where nothing can be done at the counter. Pure presentation over the verdicts
 * ResolveMemberEligibility already returns.
 */
class VerdictRemediesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 10]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function member(): Member
    {
        return Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
    }

    /** @param non-empty-string $key */
    private function rule(string $key): array
    {
        return ['rule' => $key, 'satisfied' => false, 'mode' => 'BLOCK', 'message' => 'GENERIC'];
    }

    public function test_carencia_names_the_end_date(): void
    {
        $member = $this->member();
        $member->update(['carencia_ends_at' => '2026-08-14']);

        $out = VerdictRemedy::describe($this->rule('carencia'), $member, $this->location);

        $this->assertStringContainsString('14/08/2026', $out['detail']);
        $this->assertNull($out['remedy']); // nothing to do — but the date is named
    }

    public function test_unpaid_fee_names_the_amount_owed(): void
    {
        $member = $this->member();
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 2500,
        ]);

        $out = VerdictRemedy::describe($this->rule('unpaid_fee'), $member, $this->location);

        $this->assertStringContainsString(Money::fromCents(2500)->formatted(), $out['detail']); // the €25 owed, not generic
    }

    public function test_debt_names_the_amount_and_offers_the_wallet_remedy(): void
    {
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL);
        Settings::set('wallet_debt_limit_cents', 5000, SettingType::CENTS);
        $member = $this->member();
        (new RecordWalletTransaction)->handle($member, $this->location, -1250, WalletTransactionType::CONTRIBUTION, ['allow_debt' => true]);

        $out = VerdictRemedy::describe($this->rule('debt'), $member, $this->location);

        $this->assertStringContainsString(Money::fromCents(1250)->formatted(), $out['detail']);
        $this->assertNotNull($out['remedy']); // "debe saldar el monedero"
    }

    public function test_aforo_names_the_occupancy(): void
    {
        // Three members inside a capacity-10 sede.
        foreach (range(1, 3) as $i) {
            CheckIn::factory()->create([
                'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
                'member_id' => $this->member()->id, 'checked_out_at' => null,
            ]);
        }

        $out = VerdictRemedy::describe($this->rule('aforo'), $this->member(), $this->location);

        $this->assertStringContainsString('3/10', $out['detail']);
    }

    public function test_unfixable_rules_say_who_or_what_not_a_generic_refusal(): void
    {
        $member = $this->member();

        $sanction = VerdictRemedy::describe($this->rule('sanction'), $member, $this->location);
        $this->assertNotNull($sanction['remedy']); // consult a manager

        $membership = VerdictRemedy::describe($this->rule('membership'), $member, $this->location);
        $this->assertNotNull($membership['remedy']); // renew the fee
    }

    public function test_an_unknown_rule_falls_back_to_its_generic_message(): void
    {
        $out = VerdictRemedy::describe($this->rule('age'), $this->member(), $this->location);

        $this->assertSame('GENERIC', $out['detail']);
        $this->assertNull($out['remedy']);
    }
}
