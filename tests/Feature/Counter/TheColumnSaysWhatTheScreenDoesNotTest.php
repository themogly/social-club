<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Enums\WalletTransactionType;
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
use App\Models\TillSession;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 234 — the cart column says only what the screen does not already say.
 *
 * The owner, on a clean socio: *"the wallet amount should be in the part above; the waiting period Completed
 * I don't think is needed, along with Cleared to dispense — if there's an issue with the account just block
 * the whole page til it's resolved."* One principle, which 225 half-built: **the screen's states speak; the
 * column does not narrate them.**
 *
 * Every deletion maps to somewhere the screen already says it:
 *
 *   · `Carencia · Cumplida`   → an ACTIVE carencia is a verdict rule and lands on 225's blocked surface.
 *                               "Cumplida" is the rule NOT applying — a row about nothing.
 *   · `✓ Apto para dispensar` → a blocked socio has no catalogue (225), so the catalogue's presence IS the
 *                               verdict. Silence is the all-clear.
 *   · the sanction box        → the verdict machinery states a sanction at the severity the matrix gives it.
 *                               Said twice was 199's rule broken quietly.
 *
 * The acceptance test is the first one below.
 */
class TheColumnSaysWhatTheScreenDoesNotTest extends TestCase
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
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);

        if (! TillSession::query()->withoutGlobalScopes()->exists()) {
            (new OpenTill)->handle($this->location, 'POS-1', 10000);
        }

        return $user;
    }

    private function genetic(): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Haze']);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'tier_id' => null, 'price_per_gram_cents' => 800, 'active' => true,
        ]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 50000, 'remaining_cg' => 50000, 'status' => BatchStatus::OPEN, 'expires_on' => now()->addMonths(6),
        ]);

        return $genetic;
    }

    /** @param  array<string, mixed>  $member */
    private function member(array $member = [], int $feeCents = 0): Member
    {
        $row = Member::factory()->create(array_merge([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34), 'carencia_ends_at' => now()->subMonth(),
            'photo_path' => 'member-photos/x.png',
            'daily_limit_cg' => 300000, 'monthly_limit_cg' => 3000000,
        ], $member));

        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $row->id, 'location_id' => $this->location->id,
            'tier_id' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->id,
            'status' => MembershipStatus::ACTIVE, 'fee_cents' => $feeCents,
        ]);

        return $row;
    }

    private function pos(Member $member): Testable
    {
        return Livewire::test(DispensaryPos::class)->call('selectMember', $member->id);
    }

    /** Everything between the pinned identity card and the basket. */
    private function betweenIdentityAndBasket(string $html): string
    {
        $after = strpos($html, 'data-member-summary');
        $basket = strpos($html, 'data-cart-dispensation-section');

        $this->assertNotFalse($after, 'no pinned identity card');
        $this->assertNotFalse($basket, 'no basket');
        $this->assertGreaterThan($after, $basket, 'the basket comes before the identity');

        return substr($html, (int) $after, (int) $basket - (int) $after);
    }

    // --- THE ACCEPTANCE TEST ----------------------------------------------------------------------

    /**
     * **A clean socio with a photo has NOTHING between the pinned identity and the basket.**
     *
     * Fails against `6c09582`, where the same socio gets a card carrying "Monedero", "Carencia · Cumplida"
     * and a green "✓ Apto para dispensar." banner.
     */
    public function test_a_clean_member_has_nothing_between_the_identity_and_the_basket(): void
    {
        $this->operator();
        $this->genetic();

        $html = $this->pos($this->member())->html();
        $between = $this->betweenIdentityAndBasket($html);

        $this->assertStringNotContainsString('data-member-detail', $between, 'the member-detail card still renders for a clean socio');
        $this->assertStringNotContainsString(e(__('Apto para dispensar.')), $html, 'the column still announces the all-clear');
        $this->assertStringNotContainsString(e(__('Carencia')), $html, 'the column still narrates the carencia');
        $this->assertStringNotContainsString(e(__('Sanción activa')), $html);

        // …and the catalogue — the thing that IS the verdict — is there.
        $this->assertStringContainsString('data-product', $html);
    }

    // --- What moved up ----------------------------------------------------------------------------

    /** The wallet is in the pinned card, and red when it is negative. */
    public function test_the_wallet_is_pinned_and_red_when_negative(): void
    {
        $this->operator();
        $member = $this->member();

        $html = $this->pos($member)->html();

        // The whole pinned region — from the identity card to where the scroll region begins.
        $start = (int) strpos($html, 'data-member-summary');
        $summary = substr($html, $start, (int) strpos($html, 'data-cart-scroll') - $start);

        $this->assertStringContainsString('data-member-wallet', $summary, 'the wallet is not in the pinned card');
        $this->assertStringContainsString(e(Money::fromCents(0)->formatted()), $summary);
        $this->assertStringNotContainsString('text-error', substr($html, (int) strpos($html, 'data-member-wallet'), 300));

        // …and a socio in debt reads red. The balance is derived from the ledger, so the ledger is what a
        // fixture writes — there is no "set the balance" call, by design.
        WalletTransaction::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'type' => WalletTransactionType::PURCHASE, 'amount_cents' => -1500, 'balance_after_cents' => -1500,
        ]);

        $indebted = $this->pos($member->fresh())->html();
        $line = substr($indebted, (int) strpos($indebted, 'data-member-wallet'), 300);

        $this->assertStringContainsString('text-error', $line, 'a negative wallet is not marked');
    }

    /** The photo nag moved to the pinned card, keeping 228's construction and its ≥44px controls. */
    public function test_the_photo_nag_is_in_the_pinned_card(): void
    {
        $this->operator();
        $member = $this->member(['photo_path' => null]);

        $html = $this->pos($member)->html();

        $summaryStart = (int) strpos($html, 'data-member-summary');
        $summaryEnd = (int) strpos($html, 'data-cart-scroll');
        $pinned = substr($html, $summaryStart, $summaryEnd - $summaryStart);

        $this->assertStringContainsString('data-photo-nag', $pinned, 'the nag is not in the pinned region');
        $this->assertSame(1, substr_count($html, 'data-photo-nag'), 'the nag renders twice');
        $this->assertStringContainsString('min-h-11', $pinned, '228\'s 44px controls did not come with it');
    }

    /** A socio WITH a photo gets no nag at all. */
    public function test_a_member_with_a_photo_gets_no_nag(): void
    {
        $this->operator();

        $this->assertStringNotContainsString('data-photo-nag', $this->pos($this->member())->html());
    }

    // --- What the column still says, because it needs an operator ----------------------------------

    /**
     * A WARN-state socio still gets the warning and 211's remedy.
     *
     * The counter matrix BLOCKS every rule by default (a blocked socio has no catalogue — 225), so the WARN
     * case is reached through the one rule that ships OFF and is meant to be dialled up: the photo (157).
     */
    public function test_a_warned_member_still_gets_the_warning_and_its_remedy(): void
    {
        $matrix = Settings::DEFAULTS['enforcement'];
        $matrix['counter']['photo'] = 'WARN';
        Settings::set('enforcement', $matrix, SettingType::JSON);

        $this->operator();
        $this->genetic();

        $html = $this->pos($this->member(['photo_path' => null]))->html();

        $this->assertStringContainsString('data-member-detail', $html, 'the column says nothing about a socio who needs attention');
        $this->assertStringContainsString(e(__('Aviso')), $html, 'the warning is not marked as one');
        $this->assertStringContainsString('data-product', $html, 'a WARN took the catalogue away');
    }

    /** A sanctioned socio's sanction is stated exactly once, wherever its severity puts it. */
    public function test_a_sanction_is_stated_exactly_once(): void
    {
        $this->operator();
        $this->genetic();

        $member = $this->member();
        $member->forceFill(['status' => MemberStatus::SUSPENDED])->save();

        $html = $this->pos($member->fresh())->html();

        $sanction = __('Socio/a suspendido/a o expulsado/a.');
        $this->assertSame(1, substr_count($html, e($sanction)), 'the sanction is stated more than once');
        $this->assertStringNotContainsString(e(__('Sanción activa')), $html, 'the standalone sanction box came back');
    }

    // --- Flashes earn their place ------------------------------------------------------------------

    /** A waive says nothing: its outcome is the fee notice clearing and the catalogue returning. */
    public function test_a_waive_shows_no_toast(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->member(feeCents: 2500);

        $component = $this->pos($member);
        $this->assertStringContainsString('data-blocked-member', $component->html(), 'the fee did not block');

        $component->call('toggleWaive')->set('waiveReason', 'OTHER')->set('waiveReasonText', 'Caso social')->call('waiveFee');

        $component->assertSet('flashMessage', null);

        $html = $component->html();
        $this->assertStringNotContainsString('data-blocked-member', $html, 'the outcome is not visible, so the toast was doing work');
        $this->assertStringContainsString('data-product', $html);
    }

    /** A collect DOES say something: it carries figures the screen stops showing. */
    public function test_a_collect_still_reports_its_figures(): void
    {
        $this->operator();
        $this->genetic();
        $member = $this->member(feeCents: 2500);

        $component = $this->pos($member)->set('feeAmount', '25')->set('feeMethod', 'CASH')->call('collectMemberFee');

        $this->assertNotNull($component->get('flashMessage'), 'a cash collection said nothing at all');
        $this->assertStringContainsString(e(Money::fromCents(2500)->formatted()), (string) $component->get('flashMessage'));
        $this->assertSame('success', $component->get('flashType'));
    }

    /** The signature says it in place, not in the foot. */
    public function test_capturing_a_signature_shows_no_toast(): void
    {
        // The pad renders only where the sede asks for a signature — otherwise there is nothing to confirm.
        Settings::set('signature_on_dispensation', true, SettingType::BOOL, $this->location->id);

        $this->operator();

        $genetic = $this->genetic();

        // The pad rides with the payment apparatus, so it needs a basket line to be on screen at all.
        $component = $this->pos($this->member())
            ->call('chooseGenetic', $genetic->id)
            ->set('weightInput', '2')
            ->call('addLine')
            ->call('saveSignature', 'data:image/png;base64,'.base64_encode('sig'));

        $component->assertSet('flashMessage', null);
        $this->assertStringContainsString(e(__('Firma capturada')), $component->html(), 'the pad no longer confirms in place either');
    }

    /** A success auto-dismisses; an error does not. */
    public function test_successes_hide_themselves_and_errors_do_not(): void
    {
        $this->operator();
        $member = $this->member(feeCents: 2500);

        $success = $this->pos($member)->set('feeAmount', '25')->set('feeMethod', 'CASH')->call('collectMemberFee')->html();
        $this->assertStringContainsString('setTimeout(() => show = false', $success, 'a success flash never goes away');

        $error = $this->pos($this->member())->call('commitDispensation')->html();
        $this->assertStringNotContainsString('setTimeout(() => show = false', $error, 'an error hides itself before it is read');
        $this->assertStringContainsString('role="alert"', $error);
    }

    // --- The fee panel's two buttons ---------------------------------------------------------------

    /** Collect and Waive are peers, on all three hosts, both clearing the touch floor. */
    public function test_collect_and_waive_sit_side_by_side_on_every_host(): void
    {
        $this->operator();
        $member = $this->member(feeCents: 2500);

        $hosts = [
            'pos' => $this->pos($member)->html(),
            'door' => Livewire::test(CheckInScreen::class)->call('selectMember', $member->id)->html(),
            'socios' => Livewire::test(MembershipCounter::class)->call('selectMember', $member->id)->html(),
        ];

        foreach ($hosts as $host => $html) {
            $this->assertStringContainsString('data-fee-waive-toggle', $html, "{$host}: no waive control");
            $this->assertStringContainsString(e(__('Cobrar cuota')), $html, "{$host}: no collect control");

            // A button, not the quiet link 219 wrote — and at the counter's floor.
            preg_match('/<button[^>]*data-fee-waive-toggle[^>]*>/', $html, $m);
            $this->assertNotEmpty($m, "{$host}: the waive trigger is not a button");
            $this->assertStringContainsString('min-h-11', $m[0], "{$host}: the waive button is under the touch floor");
            $this->assertStringNotContainsString('underline', $m[0], "{$host}: the waive control is still a link");
        }
    }

    /** Pressing it opens 229's reason form, with its preselection — nothing behind the button changed. */
    public function test_the_waive_button_opens_the_same_reason_form(): void
    {
        $this->operator();
        $member = $this->member(['is_therapeutic' => true], feeCents: 2500);

        $component = $this->pos($member)->call('toggleWaive');

        $component->assertSet('waiveReason', 'THERAPEUTIC');
        $this->assertStringContainsString('data-waive-reason="THERAPEUTIC"', $component->html());
        $this->assertStringContainsString('data-fee-waive-submit', $component->html());
    }

    /** …and the permission gate is untouched by the louder button. */
    public function test_the_waive_button_still_needs_its_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('membership.fee.collect');
        $user->givePermissionTo('pos.use');
        $user->locations()->sync([$this->location->id]);
        $this->actingAs($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        session(['counter.location_id' => $this->location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = $this->pos($this->member(feeCents: 2500))->html();

        $this->assertStringNotContainsString('data-fee-waive-toggle', $html);
        $this->assertStringContainsString(e(__('Cobrar cuota')), $html, 'the collect control went with it');
    }
}
