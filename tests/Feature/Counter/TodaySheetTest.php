<?php

namespace Tests\Feature\Counter;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Dispensing\VoidDispensation;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\CounterHome;
use App\Livewire\Counter\TodaySheet;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 371 — Liam: "Any way you can make it so we just click on Today and we get a sheet-equivalent rundown of the day's
 * transactions… not knowing how to double-check sheet vs iPad." The home's «Hoy» opens /counter/hoy: every sale of the day
 * at this sede, oldest first, with the totals a paper sheet has — on the SAME definition of "today" as the panel's
 * «Operaciones», and the money only for `reports.view`, as on the panel.
 */
class TodaySheetTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private User $manager;

    private User $staff;

    private Genetic $amnesia;

    private Batch $centroBatch;

    private Batch $norteBatch;

    private Article $agua;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Europe/Madrid'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid']);
        app(ActiveScope::class)->setLocation($this->centro->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('charge_rounding_enabled', false, SettingType::BOOL);
        $this->manager = $this->person(Role::MANAGER, 'Bruno Encargado', '2345');
        $this->staff = $this->person(Role::STAFF, 'Ana Barra', '3456');
        $this->amnesia = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia']);
        $this->centroBatch = $this->batch($this->centro);
        $this->norteBatch = $this->batch($this->norte);
        $this->agua = Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Agua', 'price_cents' => 150, 'stock' => 50]);
        (new OpenTill)->handle($this->centro, 'POS-1', 10000, ['operator_id' => $this->manager->id]);
        (new OpenTill)->handle($this->norte, 'POS-1', 10000, ['operator_id' => $this->manager->id]);
    }

    private function person(Role $role, string $name, string $pin): User
    {
        $user = User::factory()->create(['name' => $name, 'pin' => Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->sync([$this->centro->id, $this->norte->id]);

        return $user;
    }

    private function batch(Location $at): Batch
    {
        return Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $this->amnesia->id, 'location_id' => $at->id,
            'remaining_cg' => 100000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 1000, 'expires_on' => now()->addYear()]);
    }

    private function member(string $name, Location $at): Member
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE, 'first_name' => $name, 'last_name' => 'Prueba',
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($member, $at, MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'discount_bp' => 0]), ['actor' => $this->manager, 'fee_cents' => 0]);

        return $member;
    }

    /** A dispensation through the real writer, at a Madrid time, by an operator. */
    private function dispense(Member $member, string $madrid, int $cg, User $by, ?Location $at = null, array $pay = ['cash' => true]): void
    {
        $at ??= $this->centro;
        $this->travelTo(CarbonImmutable::parse($madrid, 'Europe/Madrid'));
        $total = (int) round($cg * 10); // €10/g
        (new CommitDispensation)->handle($member, $at, [['genetic_id' => $this->amnesia->id, 'batch_id' => ($at->is($this->norte) ? $this->norteBatch : $this->centroBatch)->id, 'grams_cg' => $cg]],
            ['operator_id' => $by->id, 'till_session_id' => $this->till($at), 'cash_cents' => $pay['cash'] === true ? $total : (int) $pay['cash'], 'wallet_cents' => $pay['wallet'] ?? 0, 'on_tab' => $pay['tab'] ?? false, 'charge_rounding' => $pay['rounding'] ?? false]);
    }

    private function sell(Member $member, string $madrid, int $qty, User $by): void
    {
        $this->travelTo(CarbonImmutable::parse($madrid, 'Europe/Madrid'));
        (new CommitOrder)->handle($this->centro, [['article_id' => $this->agua->id, 'qty' => $qty]],
            ['operator_id' => $by->id, 'member_id' => $member->id, 'till_session_id' => $this->till($this->centro), 'cash_cents' => 150 * $qty]);
    }

    private function till(Location $at): string
    {
        return (string) TillSession::query()->withoutGlobalScopes()->where('location_id', $at->id)->open()->value('id');
    }

    /** The scenario of test 2: 3 dispensations + 2 bar orders today at Centro, 1 yesterday, 1 at Norte, 1 voided today. */
    private function day(): Member
    {
        $laura = $this->member('Laura', $this->centro);
        $this->dispense($laura, '2026-10-07 20:00', 100, $this->manager); // yesterday
        $this->dispense($laura, '2026-10-08 10:00', 200, $this->manager);
        $this->sell($laura, '2026-10-08 10:30', 2, $this->staff);
        $this->dispense($this->member('Pedro', $this->centro), '2026-10-08 11:00', 350, $this->staff);
        $this->dispense($this->member('Nuria', $this->norte), '2026-10-08 11:15', 100, $this->manager, $this->norte); // other sede
        $this->dispense($laura, '2026-10-08 12:00', 150, $this->manager);
        $this->sell($laura, '2026-10-08 12:30', 1, $this->manager);
        $this->dispense($laura, '2026-10-08 13:00', 500, $this->staff); // voided below
        $voided = Dispensation::query()->withoutGlobalScopes()->latest('dispensed_at')->first();
        (new VoidDispensation)->handle($voided, $this->manager, 'Peso mal puesto');
        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Europe/Madrid'));

        return $laura;
    }

    private function sheet(User $operator, array $params = []): Testable
    {
        $this->actingAs($operator);
        session(['counter.location_id' => $this->centro->id]);
        CounterOperator::set($operator);

        return Livewire::withQueryParams($params)->test(TodaySheet::class);
    }

    // --- 1. The panel links to the sheet ----------------------------------------------------------------------------------------

    public function test_the_home_today_panel_opens_the_days_sheet(): void
    {
        $this->actingAs($this->manager);
        session(['counter.location_id' => $this->centro->id]);
        CounterOperator::set($this->manager);

        Livewire::test(CounterHome::class)
            ->assertSeeHtml('href="'.route('counter.today').'"')
            ->assertSee('Ver el día');
    }

    // --- 2. Counts agree, in time order, this sede only --------------------------------------------------------------------------

    public function test_the_sheet_lists_todays_sales_at_this_sede_in_order_and_its_count_equals_the_panels(): void
    {
        $this->day();

        $sheet = $this->sheet($this->manager);
        $rows = $sheet->instance()->sheet()->rows();
        $this->assertSame(['10:00', '10:30', '11:00', '12:00', '12:30', '13:00'], array_column($rows, 'time'));
        $this->assertSame([false, false, false, false, false, true], array_column($rows, 'voided'));
        $this->assertSame(5, $sheet->instance()->sheet()->totals()['count']);
        $sheet->assertSeeHtml('data-sheet-voided')->assertSee('Peso mal puesto');

        $home = Livewire::test(CounterHome::class)->html();
        preg_match('/data-figure="transactions"[^>]*>\s*(\d+)\s*</', $home, $m);
        $this->assertSame('5', $m[1] ?? null, 'the panel\'s «Operaciones» and the sheet agree');
    }

    // --- 3. Totals ---------------------------------------------------------------------------------------------------------------

    public function test_the_totals_add_up_grams_charged_and_money_by_how_it_was_paid(): void
    {
        $this->day();
        // An approved tab (259): the wallet is empty, so this €10.00 sale goes on the member's account.
        Settings::set('wallet_debt_allowed', true, SettingType::BOOL, (string) $this->centro->id);
        $wallet = $this->member('Monedero', $this->centro);
        $wallet->forceFill(['debt_limit_cents' => 5000])->save();
        $this->dispense($wallet, '2026-10-08 14:00', 100, $this->manager, null, ['cash' => 0, 'wallet' => 1000, 'tab' => true]);
        $this->dispense($this->member('Redondeo', $this->centro), '2026-10-08 15:00', 110, $this->manager, null, ['cash' => 1000, 'rounding' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Europe/Madrid'));

        $totals = $this->sheet($this->manager)->instance()->sheet()->totals();
        $amnesia = collect($totals['strains'])->firstWhere('name', 'Amnesia');
        $this->assertSame(200 + 350 + 150 + 100 + 110, $amnesia['grams_cg'], 'weighed grams, voided excluded');
        $this->assertSame(200 + 350 + 150 + 100 + 100, $amnesia['charged_cg'], '1.10 g charged 1.00 g');
        $this->assertSame(3, $totals['bar_items']);
        // Prompt 374 — the two ledgers apart: the contributions, and bar & shop.
        $this->assertSame(2000 + 3500 + 1500 + 1000 + 1000, $totals['money']['dispensary']['total']);
        $this->assertSame(1000, $totals['money']['dispensary']['tab']);
        $this->assertSame(0, $totals['money']['dispensary']['wallet']);
        $this->assertSame($totals['money']['dispensary']['total'] - 1000, $totals['money']['dispensary']['cash']);
        $this->assertSame(300 + 150, $totals['money']['bar']['total']);
        $this->assertSame(300 + 150, $totals['money']['bar']['cash']);

        $this->sheet($this->manager)->assertSee('1.10 g')->assertSee('se cobra 1.00 g');
    }

    // --- 4. The money gate -------------------------------------------------------------------------------------------------------

    public function test_staff_without_reports_view_see_rows_and_grams_but_no_euros_and_a_manager_sees_amounts(): void
    {
        $this->day();

        $staff = $this->sheet($this->staff)->assertSee('Laura')->assertSee('Amnesia')->assertSeeHtml('data-sheet-strain-total');
        $this->assertStringNotContainsString('€', strip_tags($staff->html()), 'no euro figure anywhere for staff');
        $staff->assertDontSeeHtml('data-sheet-money');

        $manager = $this->sheet($this->manager);
        $manager->assertSeeHtml('data-sheet-money');
        $this->assertStringContainsString('€', strip_tags($manager->html()));
    }

    // --- 5. Filters --------------------------------------------------------------------------------------------------------------

    public function test_the_source_mine_and_member_filters(): void
    {
        $this->day();
        $this->member('Zoe', $this->centro);

        $sheet = $this->sheet($this->staff);
        $sheet->call('setSource', 'bar');
        $this->assertSame(['10:30', '12:30'], array_column($sheet->instance()->sheet()->rows(), 'time'));
        $sheet->call('setSource', 'dispensary');
        $this->assertCount(4, $sheet->instance()->sheet()->rows());
        $sheet->call('setSource', 'all')->set('mine', true);
        $this->assertSame(['10:30', '11:00', '13:00'], array_column($sheet->instance()->sheet()->rows(), 'time'), 'Ana served these');
        $sheet->set('mine', false)->set('memberFilter', 'pedro');
        $this->assertSame(['11:00'], array_column($sheet->instance()->sheet()->rows(), 'time'));
    }

    // --- 6/8. Receipts in-page, print, CSV gate ----------------------------------------------------------------------------------

    public function test_a_row_opens_its_receipt_in_the_counter_sheet_and_the_page_prints_and_exports_for_reports_export(): void
    {
        $this->day();

        $html = $this->sheet($this->manager)->html();
        $this->assertStringContainsString('data-receipt-sheet', $html, 'the one receipt sheet (252)');
        $this->assertStringContainsString('counter-receipt-open', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringContainsString('data-sheet-print', $html);
        $this->assertStringContainsString(route('counter.today.csv'), $html, 'a manager holds reports.export');

        $this->assertStringNotContainsString(route('counter.today.csv'), $this->sheet($this->staff)->html());
        $this->actingAs($this->staff);
        CounterOperator::set($this->staff);
        $this->get(route('counter.today.csv'))->assertForbidden();

        $this->actingAs($this->manager);
        CounterOperator::set($this->manager);
        $csv = $this->get(route('counter.today.csv'))->assertOk()->streamedContent();
        $this->assertSame(6, preg_match_all('/^"?\d{2}:\d{2}/m', $csv), 'one line per sale (the voided one included)');
        $this->assertStringContainsString('Total', $csv);
        $this->assertStringContainsString('Peso mal puesto', $csv);
    }

    // --- 9. Time zone ------------------------------------------------------------------------------------------------------------

    public function test_a_sale_at_half_past_midnight_madrid_belongs_to_that_madrid_day(): void
    {
        $this->centro->update(['business_day_cutoff' => '00:00']); // the time zone alone (the default 06:00 cutoff keeps a late night on the evening's sheet)
        $member = $this->member('Noche', $this->centro);
        $this->dispense($member, '2026-10-09 00:30', 100, $this->manager); // 22:30 UTC on the 8th

        $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00', 'Europe/Madrid'));
        $this->assertSame(['00:30'], array_column($this->sheet($this->manager)->instance()->sheet()->rows(), 'time'));

        $this->travelTo(CarbonImmutable::parse('2026-10-08 23:00', 'Europe/Madrid'));
        $this->assertSame([], $this->sheet($this->manager)->instance()->sheet()->rows());
    }
}
