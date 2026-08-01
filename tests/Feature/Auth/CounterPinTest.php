<?php

namespace Tests\Feature\Auth;

use App\Actions\UnlockOperator;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CounterPinTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->location = Location::factory()->create(['organisation_id' => $org->id]);

        $this->operator = User::factory()->create(['pin' => Hash::make('4321'), 'active' => true]);
        $this->operator->locations()->sync([$this->location->id]);
    }

    public function test_correct_pin_identifies_the_operator(): void
    {
        $result = (new UnlockOperator)->handle($this->location, '4321', 'pin:test:1');

        $this->assertNotNull($result);
        $this->assertSame($this->operator->id, $result->id);
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $this->assertNull((new UnlockOperator)->handle($this->location, '0000', 'pin:test:2'));
    }

    public function test_pin_entry_is_rate_limited(): void
    {
        $action = new UnlockOperator;
        $key = 'pin:test:3';

        for ($i = 0; $i < UnlockOperator::MAX_ATTEMPTS; $i++) {
            $action->handle($this->location, '0000', $key);
        }

        $this->assertTrue($action->isLockedOut($key));
        // Even the CORRECT pin is refused while locked out.
        $this->assertNull($action->handle($this->location, '4321', $key));
    }

    public function test_the_lockout_window_escalates_on_repeated_lockouts(): void
    {
        $action = new UnlockOperator;
        $key = 'pin:test:escalate';

        // First lockout → the shortest window.
        for ($i = 0; $i < UnlockOperator::MAX_ATTEMPTS; $i++) {
            $action->handle($this->location, '0000', $key);
        }
        $this->assertTrue($action->isLockedOut($key));
        $firstWindow = $action->lockoutSecondsRemaining($key);
        $this->assertGreaterThan(0, $firstWindow);
        $this->assertLessThanOrEqual(UnlockOperator::LOCKOUT_WINDOWS[0], $firstWindow);

        // Let the first window elapse, then trip it again at the SAME location → a longer window.
        $this->travel(UnlockOperator::LOCKOUT_WINDOWS[0] + 1)->seconds();
        $this->assertFalse($action->isLockedOut($key));
        for ($i = 0; $i < UnlockOperator::MAX_ATTEMPTS; $i++) {
            $action->handle($this->location, '0000', $key);
        }

        $this->assertTrue($action->isLockedOut($key));
        $this->assertGreaterThan($firstWindow, $action->lockoutSecondsRemaining($key)); // escalated
    }

    public function test_a_correct_pin_clears_the_escalating_throttle(): void
    {
        $action = new UnlockOperator;
        $key = 'pin:test:clear';

        // Four wrong attempts (one shy of a lockout), then the correct PIN — the tally resets.
        for ($i = 0; $i < UnlockOperator::MAX_ATTEMPTS - 1; $i++) {
            $action->handle($this->location, '0000', $key);
        }
        $this->assertNotNull($action->handle($this->location, '4321', $key));
        $this->assertFalse($action->isLockedOut($key));

        // A fresh run of wrong attempts starts from zero (no residual count carried over).
        for ($i = 0; $i < UnlockOperator::MAX_ATTEMPTS - 1; $i++) {
            $action->handle($this->location, '0000', $key);
        }
        $this->assertFalse($action->isLockedOut($key)); // still one short — the earlier attempts did not persist
    }

    public function test_transaction_records_the_unlocked_operator_not_the_device_user(): void
    {
        $deviceUser = User::factory()->create();
        $this->actingAs($deviceUser);

        CounterOperator::set($this->operator);

        $this->assertSame($this->operator->id, CounterOperator::id());
        $this->assertNotSame($deviceUser->id, CounterOperator::id());
        $this->assertTrue(CounterOperator::current()->is($this->operator));
    }
}
