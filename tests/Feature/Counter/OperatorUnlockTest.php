<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use App\Models\Article;
use App\Models\Batch;
use App\Models\CashMovement;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 26 — PIN operator switching, end-to-end through the UI. The backend
 * (UnlockOperator + rate limiting + CounterOperator) already existed and is proven in
 * CounterPinTest; these prove the missing wiring: the counter screens surface a PIN
 * pad, unlocking sets the operator, a transaction is BLOCKED until one is identified,
 * and every transaction records the PIN operator — never the shared device login.
 */
class OperatorUnlockTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    private Genetic $genetic;

    private User $device;    // the shared till login — must NEVER be the recorded operator

    private User $operator;  // the person, identified by PIN 4321

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Amnesia Test']);

        $this->device = $this->staff('Device Login', null);
        $this->operator = $this->staff('Marta Operadora', '4321');
    }

    // --- Unlock via the UI ----------------------------------------------------------

    public function test_a_correct_pin_unlocks_the_operator_and_shows_who_is_working(): void
    {
        // No operator yet -> the full-screen surface is in its "unidentified" mode (prompt 173).
        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->assertSee('data-surface-mode="unidentified"', false)
            ->set('operatorPin', '4321')
            ->call('unlockOperator')
            ->assertSee('data-surface-mode="none"', false);

        $this->assertSame($this->operator->id, CounterOperator::id());

        // "Who is working" moved to the top bar when the inline strip was retired — it renders in the
        // layout, so it is asserted through a real request rather than on the component.
        $this->actingAs($this->device)->get(route('counter.checkin'))
            ->assertSee('data-operator-name', false)
            ->assertSee('Marta Operadora');
    }

    public function test_a_wrong_pin_is_rejected_and_never_retained(): void
    {
        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->set('operatorPin', '0000')
            ->call('unlockOperator')
            ->assertSet('operatorFeedback', __('PIN no reconocido.'))
            ->assertSet('operatorPin', '');   // the PIN is never held in component state

        $this->assertNull(CounterOperator::id());
    }

    public function test_pin_entry_rate_limits_through_the_ui(): void
    {
        $screen = Livewire::actingAs($this->device)->test(CheckInScreen::class);

        for ($i = 0; $i < 5; $i++) {
            $screen->set('operatorPin', '0000')->call('unlockOperator');
        }

        // Even the CORRECT pin is refused while locked out — not a brute-forceable gate.
        $screen->set('operatorPin', '4321')->call('unlockOperator')
            ->assertSet('operatorFeedback', __('Demasiados intentos. Espera un momento antes de reintentar.'));

        $this->assertNull(CounterOperator::id());
    }

    public function test_switching_operator_updates_the_indicator_immediately(): void
    {
        CounterOperator::set($this->operator);
        $other = $this->staff('Otro Turno', '9999');

        $this->actingAs($this->device)->get(route('counter.checkin'))->assertSee('Marta Operadora');

        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->call('switchOperator')
            ->assertSee('data-surface-mode="unidentified"', false)
            ->set('operatorPin', '9999')
            ->call('unlockOperator');

        $this->assertSame($other->id, CounterOperator::id());

        $this->actingAs($this->device)->get(route('counter.checkin'))
            ->assertSee('Otro Turno')
            ->assertDontSee('Marta Operadora');
    }

    // --- Attribution: the PIN operator, never the device login ----------------------

    public function test_a_dispensation_records_the_unlocked_operator(): void
    {
        $this->priceAt(1000);
        $this->batch(100000);
        $this->openTill();
        $member = $this->eligibleMember();

        Livewire::actingAs($this->device)->test(DispensaryPos::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '3.5')
            ->call('addLine')
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');

        $dispensation = Dispensation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->operator->id, $dispensation->operator_id);
        $this->assertNotSame($this->device->id, $dispensation->operator_id);
    }

    public function test_a_bar_order_records_the_unlocked_operator(): void
    {
        $this->openTill();
        $article = Article::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id,
            'name' => 'Cerveza', 'price_cents' => 250, 'stock' => 10, 'active' => true,
        ]);

        Livewire::actingAs($this->device)->test(BarPos::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->call('addArticle', $article->id)
            ->call('commitOrder')
            ->assertSet('flashType', 'success');

        $order = Order::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->operator->id, $order->operator_id);
        $this->assertNotSame($this->device->id, $order->operator_id);
    }

    public function test_a_cash_movement_records_the_unlocked_operator(): void
    {
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->set('terminal', 'POS-1')
            ->set('floatInput', '100')
            ->call('open')
            ->set('movementType', 'IN')
            ->set('movementAmount', '25')
            ->call('recordMovement')
            ->assertSet('flashType', 'success');

        $movement = CashMovement::query()->withoutGlobalScopes()
            ->where('type', 'IN')->firstOrFail();
        $this->assertSame($this->operator->id, $movement->operator_id);
        $this->assertNotSame($this->device->id, $movement->operator_id);
    }

    public function test_a_check_in_records_the_unlocked_operator(): void
    {
        $member = $this->eligibleMember();

        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->call('selectMember', $member->id)
            ->call('checkIn')
            ->assertSet('flashType', 'success');

        $checkIn = CheckIn::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->operator->id, $checkIn->operator_id);
        $this->assertNotSame($this->device->id, $checkIn->operator_id);
    }

    // --- Block until identified -----------------------------------------------------

    public function test_a_transaction_is_blocked_until_an_operator_is_identified(): void
    {
        $member = $this->eligibleMember();

        // No operator unlocked: the check-in is refused with a clear prompt (the pad
        // opens), not a silent failure — and nothing is written.
        Livewire::actingAs($this->device)->test(CheckInScreen::class)
            ->call('selectMember', $member->id)
            ->call('checkIn')
            ->assertSet('flashType', 'error')
            ->assertSet('operatorPanelOpen', true)
            ->assertSee(__('Identifícate con tu PIN antes de continuar.'));

        $this->assertSame(0, CheckIn::query()->withoutGlobalScopes()->count());
    }

    // --- Idle lock (prompt 120) -----------------------------------------------------

    public function test_locking_the_counter_signs_the_operator_out_and_blocks_a_commit(): void
    {
        $this->priceAt(1000);
        $this->batch(100000);
        $this->openTill();
        $member = $this->eligibleMember();

        $pos = Livewire::actingAs($this->device)->test(DispensaryPos::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->call('selectMember', $member->id)
            ->call('chooseGenetic', $this->genetic->id)
            ->set('weightInput', '3.5')
            ->call('addLine');

        $this->assertSame($this->operator->id, CounterOperator::id());

        // The idle timer (or the "lock now" button) dispatches counter-lock → the operator is signed out.
        $pos->call('lockCounter');
        $this->assertNull(CounterOperator::id());

        // A commit is now refused SERVER-SIDE (not just hidden behind the overlay): the pad is forced open and
        // nothing is written.
        $pos->call('commitDispensation')
            ->assertSet('operatorPanelOpen', true)
            ->assertSet('flashType', 'error');
        $this->assertSame(0, Dispensation::query()->withoutGlobalScopes()->count());

        // The basket SURVIVED the lock: re-identifying and committing writes the same basket, once.
        $pos->set('operatorPin', '4321')->call('unlockOperator')
            ->call('commitDispensation')
            ->assertSet('flashType', 'success');
        $this->assertSame(1, Dispensation::query()->withoutGlobalScopes()->count());
    }

    public function test_unlocking_dispatches_the_event_that_lifts_the_overlay(): void
    {
        Livewire::actingAs($this->device)->test(DispensaryPos::class)
            ->set('operatorPin', '4321')->call('unlockOperator')
            ->assertDispatched('counter-unlocked');
    }

    public function test_every_counter_screen_carries_the_full_screen_surface(): void
    {
        foreach ([CheckInScreen::class, DispensaryPos::class, BarPos::class, TillSession::class] as $screen) {
            Livewire::actingAs($this->device)->test($screen)
                // The idle lock is now one of three modes on the counter's single full-screen surface
            // (prompt 173) — same primitive, same PIN, same throttle.
                ->assertSee('data-counter-surface', false);
        }
    }

    // --- The Users-resource set/reset-PIN control works end to end ------------------

    public function test_a_pin_set_through_the_users_resource_form_unlocks_at_the_counter(): void
    {
        $fresh = $this->staff('Sin Pin Todavía', null);       // no PIN yet, assigned to the sede
        $this->assertNull($fresh->fresh()->pin);

        // Set the PIN through the actual Filament Users edit form (owner-gated). Since prompt 163 a
        // credential is only written when the operator asked for it in this session — a value present
        // without `set_pin` is what a password manager's autofill looks like and is refused. See
        // Tests\Feature\Auth\UserCredentialAutofillTest.
        $this->actingAs($this->owner());
        Livewire::test(EditUser::class, ['record' => $fresh->getRouteKey()])
            ->fillForm(['set_pin' => true, 'pin' => '5678'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($fresh->fresh()->pin);           // stored (hashed)
        $this->assertNotSame('5678', $fresh->fresh()->pin);   // never in plain text

        // …and it unlocks that operator at the counter.
        Livewire::actingAs($this->device)->test(TillSession::class)
            ->set('operatorPin', '5678')
            ->call('unlockOperator');

        $this->assertSame($fresh->id, CounterOperator::id());
    }

    // --- Fixtures -------------------------------------------------------------------

    private function staff(string $name, ?string $pin): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'pin' => $pin !== null ? Hash::make($pin) : null,
            'active' => true,
        ]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);

        return $user;
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);

        return $owner;
    }

    private function priceAt(int $centsPerGram): void
    {
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null,
            'price_per_gram_cents' => $centsPerGram, 'active' => true,
        ]);
    }

    private function batch(int $remainingCg): Batch
    {
        return Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $this->genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => $remainingCg,
            'status' => BatchStatus::OPEN, 'expires_on' => now()->addYear(),
        ]);
    }

    private function eligibleMember(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(30), 'carencia_ends_at' => now()->subDay(),
            'daily_limit_cg' => 100000, 'monthly_limit_cg' => 100000,
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id, 'member_id' => $member->id, 'location_id' => $this->location->id,
            'tier_id' => $tier->id, 'status' => MembershipStatus::ACTIVE, 'fee_cents' => 0,
        ]);

        return $member;
    }

    private function openTill(): void
    {
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
    }
}
