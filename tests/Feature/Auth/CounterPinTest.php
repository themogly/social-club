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
