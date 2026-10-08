<?php

namespace Tests\Feature\Till;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Till\CloseTill;
use App\Actions\Till\OpenTill;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\CashPot;
use App\Enums\FeePaymentMethod;
use App\Enums\MemberStatus;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\CashMovement;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CashBoxes;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TillSummary;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 373 — "Where the cash goes". Arron (Medicana, Dream Green): "Only money in the till is from weed. Members, drinks and
 * edibles all go in separate boxes." Liam (Greenhouse): "I have members money separate and all other transactions in one
 * till." Each sede now chooses, per kind of money, the till or its own box (edibles, bar, fees), with three presets; the
 * session snapshots its boxes; switching never loses or duplicates money.
 */
class CashBoxesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sede;

    private User $owner;

    private Member $member;

    private Genetic $flower;

    private Genetic $edible;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Medicana']);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);
        $this->flower = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $this->edible = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Brownie', 'product_type' => ProductType::EDIBLE, 'grams_per_unit_cg' => 100]);
    }

    /** @param  array{EDIBLES?: string, BAR?: string, FEES?: string}  $boxes */
    private function boxes(array $boxes, ?Location $at = null): void
    {
        foreach (CashBoxes::SETTINGS as $pot => $key) {
            Settings::set($key, $boxes[$pot] ?? 'till', SettingType::STRING, (string) ($at ?? $this->sede)->id);
        }
    }

    private function open(int $float = 10000): TillSession
    {
        return (new OpenTill)->handle($this->sede, 'Caja 1', $float, ['operator_id' => $this->owner->id]);
    }

    /** A visit: 5 g of flower at €10/g and, optionally, one edible at €10. */
    private function visit(TillSession $session, int $cash, int $wallet = 0, bool $withEdible = true, int $flowerCg = 500): Dispensation
    {
        $flowerBatch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->flower->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
        $lines = [['genetic_id' => $this->flower->id, 'batch_id' => $flowerBatch->id, 'grams_cg' => $flowerCg]];
        if ($withEdible) {
            $edibleBatch = Batch::factory()->units(10, 10)->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->edible->id, 'location_id' => $this->sede->id,
                'status' => BatchStatus::OPEN, 'price_per_unit_cents' => 1000, 'expires_on' => now()->addYear()]);
            $lines[] = ['genetic_id' => $this->edible->id, 'batch_id' => $edibleBatch->id, 'units' => 1];
        }

        return (new CommitDispensation)->handle($this->member, $this->sede, $lines,
            ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => $cash, 'wallet_cents' => $wallet]);
    }

    private function fee(TillSession $session, int $cents): void
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'default_fee_cents' => $cents]);
        $membership = (new EnrolMembership)->handle(Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]), $this->sede, $tier, ['actor' => $this->owner]);
        (new RecordFeePayment)->handle($membership->fresh(), $cents, FeePaymentMethod::CASH, ['till_session_id' => $session->id, 'operator_id' => $this->owner->id]);
    }

    private function barSale(TillSession $session, int $cents): void
    {
        $article = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'price_cents' => $cents, 'stock' => 10]);
        (new CommitOrder)->handle($this->sede, [['article_id' => $article->id, 'qty' => 1]], ['operator_id' => $this->owner->id, 'till_session_id' => $session->id, 'cash_cents' => $cents]);
    }

    private function euros(int $cents): string
    {
        return Money::fromCents($cents)->formatted();
    }

    /** @return array<string, int> pot => expected */
    private function expected(TillSession $session): array
    {
        return collect(TillSummary::breakdown($session->fresh())['pots'])->map(fn (array $p): int => $p['expected'])->all();
    }

    // --- 1. Presets and the summary sentence -------------------------------------------------------------------------------------

    public function test_the_presets_set_the_three_rows_and_the_sentence_says_what_is_counted(): void
    {
        $this->assertSame(['EDIBLES' => 'till', 'BAR' => 'till', 'FEES' => 'own'], CashBoxes::PRESETS['fees_apart']);
        $this->assertSame(['EDIBLES' => 'own', 'BAR' => 'own', 'FEES' => 'own'], CashBoxes::PRESETS['all_apart']);
        $this->assertSame(['EDIBLES' => 'till', 'BAR' => 'till', 'FEES' => 'till'], CashBoxes::PRESETS['all_till']);

        $this->assertSame('Al cerrar se cuenta la caja (dispensario, comestibles, barra y cuotas).', CashBoxes::summary(CashBoxes::PRESETS['all_till']));
        $this->assertSame('Al cerrar se cuenta la caja (dispensario, comestibles y barra) y el bote de cuotas.', CashBoxes::summary(CashBoxes::PRESETS['fees_apart']));
        $this->assertSame('Al cerrar se cuenta la caja (dispensario) y los botes de comestibles, barra y cuotas.', CashBoxes::summary(CashBoxes::PRESETS['all_apart']));
    }

    // --- 2. Per sede, owner only -------------------------------------------------------------------------------------------------

    public function test_each_sede_keeps_its_own_choice_and_only_the_owner_may_change_it(): void
    {
        $other = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Greenhouse']);
        $this->owner->locations()->sync([$this->sede->id, $other->id]);

        Livewire::test(EditLocation::class, ['record' => $other->id])->fillForm(['cash_box_edibles' => 'till', 'cash_box_bar' => 'till', 'cash_box_fees' => 'own'])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(EditLocation::class, ['record' => $this->sede->id])->fillForm(['cash_box_edibles' => 'own', 'cash_box_bar' => 'own', 'cash_box_fees' => 'own'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(['FEES'], CashBoxes::ownBoxesFor($other->id));
        $this->assertSame(['BAR', 'FEES', 'EDIBLES'], CashBoxes::ownBoxesFor($this->sede->id));
        $this->assertSame(['FEES'], (new OpenTill)->handle($other, 'Caja 1', 0, ['operator_id' => $this->owner->id])->own_boxes);
        $this->assertSame(['BAR', 'FEES', 'EDIBLES'], $this->open()->own_boxes);

        // A manager sees the section and cannot change it.
        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $manager->locations()->sync([$other->id]);
        $this->actingAs($manager);
        Livewire::test(EditLocation::class, ['record' => $other->id])->fillForm(['cash_box_bar' => 'own', 'cash_box_fees' => 'till'])->call('save');
        $this->assertSame(['FEES'], CashBoxes::ownBoxesFor($other->id), 'a manager cannot move where the money goes');
    }

    // --- 3. Greenhouse: fees apart, everything else in the till -----------------------------------------------------------------

    public function test_greenhouse_keeps_the_fees_apart_and_everything_else_in_the_till(): void
    {
        $this->boxes(['FEES' => 'own']);
        Settings::set('count_fees_nightly', true, SettingType::BOOL, (string) $this->sede->id);
        $session = $this->open(10000);
        $this->visit($session, 4000, 0, withEdible: false, flowerCg: 400);
        $this->barSale($session, 600);
        $this->fee($session, 2000);

        $expected = $this->expected($session);
        $this->assertSame(10000 + 4000 + 600, $expected['DISPENSARY']);
        $this->assertSame(2000, $expected['FEES']);
        $this->assertSame(0, $expected['BAR']);
        $this->assertSame(10000 + 4600, TillSummary::breakdown($session->fresh())['expected']);

        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        Livewire::test(TillScreen::class)->call('startClose')
            ->set('reweighDone', true)->set('reweighing', false) // the flower count is its own step (47), not this test's
            ->assertSeeHtml('data-pot-count="FEES"')->assertDontSeeHtml('data-pot-count="BAR"')->assertDontSeeHtml('data-pot-count="EDIBLES"')
            ->assertSet('potCountNow.FEES', true);
    }

    // --- 4. Arron: everything apart ---------------------------------------------------------------------------------------------

    public function test_arrons_sede_puts_edibles_bar_and_fees_each_in_its_box_and_says_so(): void
    {
        $this->boxes(['EDIBLES' => 'own', 'BAR' => 'own', 'FEES' => 'own']);
        $session = $this->open(10000);

        $paidCash = $this->visit($session, 6000);
        $this->assertSame(1000, $paidCash->edibles_cash_cents->cents);
        $this->assertSame('Pon '.$this->euros(1000).' en el bote de comestibles.', CashBoxes::sentence($session->fresh(), [CashPot::EDIBLES->value => 1000]));

        // €30 wallet + €30 cash: cash goes to the edibles first, up to their total.
        (new RecordWalletTransaction)->handle($this->member, $this->sede, 3000, WalletTransactionType::TOPUP, ['till_session_id' => null]);
        $mixed = $this->visit($session, 3000, 3000);
        $this->assertSame(1000, $mixed->edibles_cash_cents->cents);

        $this->barSale($session, 500);
        $this->fee($session, 2000);

        $expected = $this->expected($session);
        $this->assertSame(10000 + 5000 + 2000, $expected['DISPENSARY']);
        $this->assertSame(2000, $expected['EDIBLES']);
        $this->assertSame(500, $expected['BAR']);
        $this->assertSame(2000, $expected['FEES']);

        $this->assertSame('Pon '.$this->euros(1000).' en el bote de comestibles y '.$this->euros(500).' en el bote de la barra.',
            CashBoxes::sentence($session->fresh(), [CashPot::EDIBLES->value => 1000, CashPot::BAR->value => 500]));
        $this->assertSame('Pon '.$this->euros(2000).' en el bote de cuotas.', CashBoxes::sentence($session->fresh(), [CashPot::FEES->value => 2000]));
        $this->assertNull(CashBoxes::sentence($session->fresh(), [CashPot::DISPENSARY->value => 5000]), 'nothing in a separate box, no line');
    }

    // --- 5. Bug 1: switching a box into the till never loses its money -----------------------------------------------------------

    public function test_a_box_switched_into_the_till_is_merged_with_an_entry_and_said_on_opening(): void
    {
        $this->boxes(['BAR' => 'own', 'FEES' => 'own']);
        $first = $this->open(10000);
        $this->barSale($first, 4500);
        (new CloseTill)->handle($first->fresh(), 10000, $this->owner, null, []); // the bar box left uncounted
        $this->assertSame(4500, (int) $first->fresh()->getRawOriginal('bar_expected_cents'));

        $this->boxes(['FEES' => 'own']); // bar → the till
        $second = $this->open(10000);

        $merge = CashMovement::query()->where('till_session_id', $second->id)->sole();
        $this->assertSame([CashMovementType::IN, CashPot::DISPENSARY, 4500], [$merge->type, $merge->pot, $merge->amount_cents->cents]);
        $this->assertSame('Bote de la barra unido a la caja', $merge->reason);
        $this->assertSame(10000 + 4500, TillSummary::breakdown($second->fresh())['expected']);
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'till.box_merged')->sole();
        $this->assertSame(['pot' => 'BAR', 'amount_cents' => 4500], array_intersect_key($audit->after, ['pot' => 1, 'amount_cents' => 1]));

        session(['counter.location_id' => $this->sede->id]);
        CounterOperator::set($this->owner);
        $html = Livewire::test(TillScreen::class)->html();
        $this->assertStringContainsString('data-till-merged', $html);
        $this->assertStringContainsString(e('Vacía el bote de la barra ('.$this->euros(4500).') en la caja.'), $html);
    }

    // --- 6. Bug 2: a box made separate again starts at zero ---------------------------------------------------------------------

    public function test_a_box_separate_again_opens_at_zero_not_an_old_balance(): void
    {
        $this->boxes(['BAR' => 'own']);
        $first = $this->open(10000);
        $this->barSale($first, 4500);
        (new CloseTill)->handle($first->fresh(), 10000, $this->owner, null, ['BAR' => 4500]);

        $this->boxes([]); // a night all in the till: the €45 is merged in
        $second = $this->open(10000);
        (new CloseTill)->handle($second->fresh(), 14500, $this->owner);

        $this->boxes(['BAR' => 'own']);
        $third = $this->open(10000);
        $this->assertSame(0, $third->bar_opening_cents->cents, 'the €45 went into the till two nights ago');
    }

    // --- 7. The session snapshot -------------------------------------------------------------------------------------------------

    public function test_a_setting_changed_mid_session_does_not_change_that_session(): void
    {
        $this->boxes(['FEES' => 'own']);
        $session = $this->open(10000);
        $this->fee($session, 2000);
        $this->boxes([]);

        $this->assertSame(2000, $this->expected($session)['FEES']);
        $this->assertSame(10000, TillSummary::breakdown($session->fresh())['expected']);
    }

    // --- 8. The migration --------------------------------------------------------------------------------------------------------

    public function test_the_migration_maps_the_old_switch_and_backfills_the_sessions(): void
    {
        $other = Location::factory()->create(['organisation_id' => $this->org->id]);
        DB::table('settings')->where('key', 'like', 'cash_box_%')->delete();
        foreach ([[$this->sede->id, '1'], [$other->id, '0']] as [$location, $value]) {
            DB::table('settings')->insert(['id' => (string) Str::ulid(), 'organisation_id' => $this->org->id, 'location_id' => $location,
                'key' => 'separate_cash_pots', 'value' => $value, 'type' => 'BOOL', 'created_at' => now(), 'updated_at' => now()]);
        }
        $legacy = TillSession::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->sede->id, 'separate_pots' => true]);
        $plain = TillSession::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $other->id, 'separate_pots' => false]);
        DB::table('till_sessions')->whereIn('id', [$legacy->id, $plain->id])->update(['own_boxes' => null]);

        $migration = require database_path('migrations/2026_10_09_100000_cash_boxes_per_kind_of_money.php');
        $migration->moveData();

        $this->assertSame(['BAR', 'FEES'], CashBoxes::ownBoxesFor($this->sede->id));
        $this->assertSame([], CashBoxes::ownBoxesFor($other->id));
        $this->assertSame(0, DB::table('settings')->where('key', 'separate_cash_pots')->count());
        $this->assertSame(['BAR', 'FEES'], $legacy->fresh()->own_boxes);
        $this->assertSame([], $plain->fresh()->own_boxes);
    }

    // --- 9. Edibles cash is a snapshot ------------------------------------------------------------------------------------------

    public function test_the_edibles_cash_is_stored_at_commit_and_survives_a_type_change(): void
    {
        $this->boxes(['EDIBLES' => 'own']);
        $session = $this->open(10000);
        $this->visit($session, 6000);
        $this->edible->update(['product_type' => ProductType::FLOWER]);

        $this->assertSame(1000, $this->expected($session)['EDIBLES']);
        $this->assertSame(10000 + 5000, $this->expected($session)['DISPENSARY']);
    }
}
