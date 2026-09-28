<?php

namespace Tests\Feature\Security;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Post-296 audit, Phase 1 findings 3 and 4 — prompt 260's rule, finished: with nobody at the PIN, the counter reads
 * nothing about a member, whatever the browser asks for.
 *
 * The lock surface only HIDES what is under it; what matters is what reaches the browser. The audit proved, over the
 * real endpoint, that a signed-in tablet with nobody at the PIN still sent: who is inside (and, by editing an unlocked
 * `locationId`, who is inside another sede); a member's card, by selecting or scanning them; the till's drawer
 * figures; and — through 293's islands, whose methods were public — a member's dispensing history ("Su habitual").
 */
class NobodyAtThePinSeesNoMemberTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private const PIN = '48151623';

    private Organisation $org;

    private Location $sede;

    private Location $otra;

    private User $device;

    private Member $member;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        $this->otra = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);

        $this->device = User::factory()->create(['pin' => Hash::make(self::PIN)]);
        $this->device->assignRole(Role::OWNER->value);
        $this->device->locations()->sync([$this->sede->id, $this->otra->id]);
        $till = (new OpenTill)->handle($this->sede, 'POS-1', 12345);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Habitualkush']);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'tier_id' => null, 'price_per_gram_cents' => 900, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN]);

        $this->member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Ainhoa', 'last_name' => 'Zubizarreta',
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $this->member->id, 'location_id' => $this->sede->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);
        (new CommitDispensation)->handle($this->member, $this->sede, [['genetic_id' => $this->genetic->id, 'batch_id' => null, 'grams_cg' => 100]],
            ['operator_id' => $this->device->id, 'till_session_id' => $till->id, 'cash_cents' => 900, 'wallet_cents' => 0]);

        $this->actingAs($this->device);
        $this->post(route('counter.location'), ['location_id' => $this->sede->id]);
        CounterOperator::clear(); // a signed-in tablet, nobody at the PIN
    }

    private function body(string $response): string
    {
        return $response;
    }

    public function test_who_is_inside_is_not_sent_and_its_sede_cannot_be_changed(): void
    {
        CheckIn::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'member_id' => $this->member->id, 'checked_out_at' => null]);
        $inOtra = Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Otrasede', 'last_name' => 'Dentro']);
        CheckIn::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->otra->id, 'member_id' => $inOtra->id, 'checked_out_at' => null]);

        $page = (string) $this->get('/counter/checkin')->assertOk()->getContent();
        $this->assertStringNotContainsString('Zubizarreta', $page, 'who is inside reached a tablet with nobody at the PIN');

        $snapshot = $this->snapshotFrom('/counter/checkin', 'counter.whos-inside');
        $tampered = $this->livewirePost($snapshot, ['locationId' => $this->otra->id]);
        $this->assertStringNotContainsString('Otrasede', (string) $tampered->getContent(), 'another sede\'s room was listed');
        $this->assertNotSame(200, $tampered->getStatusCode(), 'the sede of the list is not locked');
    }

    public function test_selecting_or_scanning_a_member_shows_nothing_with_nobody_at_the_pin(): void
    {
        foreach (['/counter/pos' => 'counter.dispensary-pos', '/counter/checkin' => 'counter.check-in-screen', '/counter/bar' => 'counter.bar-pos'] as $uri => $name) {
            $snapshot = $this->snapshotFrom($uri, $name);

            $selected = $this->livewirePost($snapshot, calls: [['selectMember', [$this->member->id]]]);
            $this->assertStringNotContainsString('Zubizarreta', (string) $selected->getContent(), "{$name}: selectMember rendered the member");

            $set = $this->livewirePost($snapshot, ['memberId' => $this->member->id]);
            $this->assertStringNotContainsString('Zubizarreta', (string) $set->getContent(), "{$name}: a posted member id rendered the member");
        }
    }

    public function test_the_catalogue_islands_are_not_wire_actions_and_hold_no_history_without_an_operator(): void
    {
        $snapshot = $this->snapshotFrom('/counter/pos', 'counter.dispensary-pos');

        foreach ([['islandView', ['header']], ['islandView', ['genetics']], ['islandChanged', ['header']]] as $call) {
            $response = $this->livewirePost($snapshot, ['memberId' => $this->member->id], [$call]);
            $this->assertNotSame(200, $response->getStatusCode(), "{$call[0]} answered as a wire action");
            $this->assertStringNotContainsString('usual', (string) $response->getContent());
        }

        $rendered = (string) $this->livewirePost($snapshot, ['memberId' => $this->member->id])->getContent();
        $this->assertStringNotContainsString('data-usual-genetic', $rendered, '"Su habitual" rendered with nobody at the PIN');
    }

    public function test_the_till_figures_wait_for_the_pin(): void
    {
        $page = (string) $this->get('/counter/till')->assertOk()->getContent();

        $this->assertStringNotContainsString(e(Money::fromCents(12345)->formatted()), $page, 'the drawer figures reached a tablet with nobody at the PIN');
        $this->assertNotNull(TillSession::query()->withoutGlobalScopes()->first());
    }

    public function test_with_the_operator_back_the_screens_show_it_all_again(): void
    {
        CounterOperator::set($this->device);

        $snapshot = $this->snapshotFrom('/counter/pos', 'counter.dispensary-pos');
        $selected = (string) $this->livewirePost($snapshot, calls: [['selectMember', [$this->member->id]]])->getContent();
        $this->assertStringContainsString('Zubizarreta', $selected);
        $this->assertStringContainsString('data-usual-genetic', $selected, 'Su habitual is back with an operator');

        $this->assertStringContainsString(e(Money::fromCents(12345)->formatted()), (string) $this->get('/counter/till')->getContent());
    }
}
