<?php

namespace Tests\Feature\Dispensing;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 299 — once a member is chosen, the dispensary stops asking "find a member". The card is the one "who", with
 * *Cambiar socio* and a scan button; a card reader still switches member through a keyboard-wedge catcher; the missing
 * photo is a chip that opens a sheet.
 */
class MemberChosenTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private Genetic $genetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->sede->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);

        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        GeneticPrice::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id, 'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN]);
    }

    private function member(string $last, array $attributes = []): Member
    {
        $member = Member::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => 'Socio', 'last_name' => $last,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ], $attributes));
        Membership::factory()->create(['organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->sede->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0]);

        return $member;
    }

    private function enable(string $setting): void
    {
        Settings::set($setting, true, SettingType::BOOL, $this->sede->id);
    }

    private function withUnpaidLine(Member $member): Testable
    {
        return Livewire::test(DispensaryPos::class)
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->call('addLine', '1');
    }

    // --- 1. The search ----------------------------------------------------------------------------------------------

    public function test_with_a_member_chosen_there_is_no_lookup_and_without_one_the_blocking_state_has_it(): void
    {
        $pos = Livewire::test(DispensaryPos::class);
        $pos->assertSee('data-blocker="member"', false)->assertSee('data-member-lookup', false);

        $pos->call('selectMember', $this->member('Uno')->id);
        $pos->assertDontSee('data-member-lookup', false)->assertSee('data-member-summary', false);
    }

    // --- 2. Cambiar socio -------------------------------------------------------------------------------------------

    public function test_the_card_says_change_member_asks_about_unpaid_lines_and_otherwise_returns_to_the_lookup(): void
    {
        $member = $this->member('Uno');
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
        $pos->assertSee(__('Cambiar socio'))->assertSee('aria-label="'.e(__('Cambiar de socio')).'"', false)
            ->assertDontSee('aria-label="'.e(__('Cerrar ficha del socio')).'"', false);

        $pos->call('clearMember')->assertSet('memberId', null)
            ->assertSee('data-member-lookup', false)->assertSee('data-member-lookup-focus', false);

        $unpaid = $this->withUnpaidLine($member);
        $unpaid->call('clearMember')->assertSet('confirmDiscard', true)->assertSet('memberId', $member->id)
            ->assertSee(__('Seguir cobrando'))->assertSee(__('Descartar'));
    }

    // --- 3. The card's scan button ----------------------------------------------------------------------------------

    public function test_the_card_scan_button_follows_the_camera_setting_and_a_scan_of_another_member_asks_first(): void
    {
        $member = $this->member('Uno');
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->assertDontSee('data-member-rescan', false);

        $this->enable('camera_scan_enabled');
        $pos = $this->withUnpaidLine($member)->assertSee('data-member-rescan', false)
            ->assertSee('aria-label="'.e(__('Escanear otro socio')).'"', false);

        $other = $this->member('Dos');
        $pos->call('submitCameraScan', (new IssueMemberToken)->handle($other))
            ->assertSet('confirmDiscard', true)->assertSet('memberId', $member->id);
    }

    // --- 4. Card readers ----------------------------------------------------------------------------------------------

    public function test_the_wedge_catcher_is_there_only_with_readers_on_and_its_scan_selects_through_the_token(): void
    {
        $first = $this->member('Uno');
        $second = $this->member('Dos');
        $token = (new IssueMemberToken)->handle($second);

        $off = Livewire::test(DispensaryPos::class)->call('selectMember', $first->id)->assertDontSee('data-card-wedge', false);
        $off->call('submitWedgeScan', $token)->assertSet('memberId', $first->id);

        $this->enable('card_readers_enabled');
        $on = Livewire::test(DispensaryPos::class)->call('selectMember', $first->id)->assertSee('data-card-wedge', false);
        $on->call('submitWedgeScan', $token)->assertSet('memberId', $second->id);

        // Not a card: nothing changes, the hidden search is left clean, and staff are told.
        $on->call('submitWedgeScan', 'no-es-una-tarjeta')->assertSet('memberId', $second->id)->assertSet('lookup', '')->assertSet('flashType', 'error');
    }

    public function test_the_browser_rule_takes_a_fast_burst_and_ignores_people(): void
    {
        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e', <<<'JS'
            import { createCardWedge } from './resources/js/card-wedge.js';
            const out = {};
            const run = (keys, target = {}) => {
                const scans = []; const actions = [];
                const wedge = createCardWedge({ onScan: (v) => scans.push(v) });
                for (const [key, t] of keys) actions.push(wedge.handle({ key, timeStamp: t, target }));
                return { scans, actions };
            };
            const token = 'A1b2C3d4E5f6G7h8J9k0L1m2N3p4Q5r6';
            const burst = [...token].map((k, i) => [k, 1000 + i * 8]).concat([['Enter', 1000 + token.length * 8]]);
            out.burst = run(burst);
            out.slow = run([...'12345678'].map((k, i) => [k, 1000 + i * 180]).concat([['Enter', 3000]]));
            const typing = { closest: (s) => s.includes('input') ? {} : null };
            out.input = run(burst, typing);
            console.log(JSON.stringify(out));
        JS]);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $out = json_decode(trim($result->output()), true);

        $this->assertSame(['A1b2C3d4E5f6G7h8J9k0L1m2N3p4Q5r6'], $out['burst']['scans'], 'a card burst was not taken');
        $this->assertSame('scan', end($out['burst']['actions']), 'the burst\'s Enter reached the page (the pad would add a line)');
        $this->assertContains('swallow', $out['burst']['actions'], 'the burst\'s keys reached the weight pad');
        $this->assertSame([], $out['slow']['scans'], 'slow typing was taken as a card');
        $this->assertNotContains('swallow', $out['slow']['actions'], 'a person\'s key was swallowed');
        $this->assertSame([], $out['input']['scans'], 'typing into a field was captured');
    }

    // --- 5. The photo chip ------------------------------------------------------------------------------------------

    public function test_the_missing_photo_is_one_chip_that_opens_a_sheet_and_goes_once_there_is_a_photo(): void
    {
        $member = $this->member('Uno', ['photo_path' => null]);
        $pos = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);

        $pos->assertSee('data-photo-chip', false)->assertSee(__('Sin foto'))
            ->assertSee('data-photo-sheet', false)->assertSee(__('Hacer foto'))->assertSee(__('Elegir archivo'))
            ->assertSee(__('Sin foto — verifica y súbela')); // the same verification text as before, now in the sheet

        $member->forceFill(['photo_path' => 'members/uno.jpg'])->saveQuietly();
        Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->assertDontSee('data-photo-chip', false);
    }

    // --- 6. The other screens are unchanged ---------------------------------------------------------------------------

    public function test_barra_socios_and_recepcion_still_show_the_lookup_as_before(): void
    {
        $member = $this->member('Uno');
        $this->enable('bar_attach_socio_enabled'); // the bar's socio section is per sede

        Livewire::test(BarPos::class)->assertSee('data-member-lookup', false)
            ->call('selectMember', $member->id)->assertDontSee('data-member-lookup', false);
        Livewire::test(MembershipCounter::class)->assertSee('data-member-lookup', false)
            ->call('selectMember', $member->id)->assertDontSee('data-member-lookup', false);
        Livewire::test(CheckInScreen::class)->assertSee('data-member-lookup', false);
    }
}
