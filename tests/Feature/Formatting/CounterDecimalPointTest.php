<?php

namespace Tests\Feature\Formatting;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 352 — the last two decimal commas on the counter. 316 made every displayed number use a point, but the cash box
 * after *Justo* / a note ("16,00") and the quick-weight buttons ("3,5 g") were built by hand with a literal comma.
 */
class CounterDecimalPointTest extends TestCase
{
    use RefreshDatabase;

    private Location $sede;

    private Member $member;

    private Genetic $genetic;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        Settings::set('enrol_all_sedes', false, SettingType::BOOL);
        Settings::set('after_recording', 'stay', SettingType::STRING, (string) $this->sede->id);
        $this->owner = User::factory()->create(['pin' => '1234']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->actingAs($this->owner);
        $this->member = Member::factory()->create(['organisation_id' => $org->id, 'status' => MemberStatus::ACTIVE,
            'carencia_ends_at' => now()->subMonth(), 'daily_limit_cg' => 100000, 'monthly_limit_cg' => 1000000]);
        (new EnrolMembership)->handle($this->member, $this->sede, MembershipTier::factory()->create(['organisation_id' => $org->id, 'discount_bp' => 0]), ['actor' => $this->owner, 'fee_cents' => 0]);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $org->id]);
        Batch::factory()->create(['organisation_id' => $org->id, 'genetic_id' => $this->genetic->id, 'location_id' => $this->sede->id,
            'remaining_cg' => 10000, 'status' => BatchStatus::OPEN, 'price_per_gram_cents' => 800, 'price_per_eighth_cents' => null, 'expires_on' => now()->addYear()]);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::set($this->owner);
    }

    /** A 2 g basket at 8.00 €/g: 16.00 €. */
    private function basket(): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)
            ->call('chooseGenetic', $this->genetic->id)->set('weightInput', '2')->call('addLine');
    }

    // --- 1. Justo and the notes -------------------------------------------------------------------------------------------------

    public function test_justo_writes_16_00_and_a_5_euro_note_makes_it_21_00_in_both_languages(): void
    {
        foreach (['es', 'en'] as $locale) {
            app()->setLocale($locale);
            $pos = $this->basket()->call('quickCash');
            $this->assertSame('16.00', $pos->get('cashTendered'), $locale.': Justo');

            $pos->call('quickCash', 500);
            $this->assertSame('21.00', $pos->get('cashTendered'), $locale.': + 5 €');
        }
    }

    // --- 2. The quick-weight buttons ---------------------------------------------------------------------------------------------

    public function test_the_quick_weight_buttons_read_1_2_3_point_5_and_5_g(): void
    {
        app()->setLocale('es');
        $html = Livewire::test(DispensaryPos::class)->call('selectMember', $this->member->id)->call('chooseGenetic', $this->genetic->id)->html();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $labels = [];
        foreach ((new DOMXPath($dom))->query('//*[@data-weight-preset]//span[1]') ?: [] as $span) {
            /** @var DOMElement $span */
            $labels[] = trim(preg_replace('/\s+/', ' ', $span->textContent));
        }

        $this->assertSame(['1 g', '2 g', '3.5 g', '5 g'], $labels);
    }

    // --- 3. Typing still accepts both separators (a pin) ---------------------------------------------------------------------------

    public function test_typing_16_comma_00_or_16_point_00_gives_the_same_change(): void
    {
        app()->setLocale('es');
        $comma = $this->basket()->set('cashTendered', '20,00')->viewData('changeDueCents');
        $point = $this->basket()->set('cashTendered', '20.00')->viewData('changeDueCents');

        $this->assertSame(400, $comma);
        $this->assertSame($comma, $point);
        $this->assertSame(0, $this->basket()->set('cashTendered', '16,00')->viewData('changeDueCents'));
        $this->assertSame(0, $this->basket()->set('cashTendered', '16.00')->viewData('changeDueCents'));
    }
}
