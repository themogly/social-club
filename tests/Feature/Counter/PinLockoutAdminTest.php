<?php

namespace Tests\Feature\Counter;

use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Filament\Pages\Seguridad;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 235 — the PIN lockout is clearable from the admin, and the attempt count is a per-sede setting.
 *
 * The owner, preparing the first counter tablet: *"can you make it so I can clear [the PIN lockout] easily
 * in the admin panel, and adjust the number of attempts."* Until this branch the only ways out were waiting
 * or `cache:clear` over SSH, and the count was a class constant.
 *
 * The split that matters: **the attempt count is the fat-finger tolerance and is the club's to tune (3–10);
 * the escalating windows are the security property and stay constants.** A club that could set every window
 * to 60 seconds would have a throttle in name only.
 */
class PinLockoutAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $sedeA;

    private Location $sedeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->sedeA = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede A']);
        $this->sedeB = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede B']);
    }

    private function staffAt(Location $location, string $pin = '4321'): User
    {
        $user = User::factory()->create(['pin' => Hash::make($pin)]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$location->id]);

        return $user;
    }

    private function failTimes(Location $location, int $times): void
    {
        $unlock = new UnlockOperator;
        $key = 'counter-pin:'.$location->id;

        for ($i = 0; $i < $times; $i++) {
            $unlock->handle($location, '0000', $key);
        }
    }

    // --- The per-sede attempt count -----------------------------------------------------------------

    /**
     * **Sede A set to 3 locks on the third failure while sede B, unset, still locks on the fifth.**
     *
     * Fails against `e277da6`, where the count is `MAX_ATTEMPTS` for everybody.
     */
    public function test_the_attempt_count_is_per_sede_and_the_default_is_untouched(): void
    {
        Settings::set('counter_pin_max_attempts', 3, SettingType::INT, $this->sedeA->id);
        $this->staffAt($this->sedeA);
        $this->staffAt($this->sedeB);

        $unlock = new UnlockOperator;

        // Sede A: two failures leave it open; the third locks.
        $this->failTimes($this->sedeA, 2);
        $this->assertFalse($unlock->isLockedOut('counter-pin:'.$this->sedeA->id), 'sede A locked before its configured third failure');

        $this->failTimes($this->sedeA, 1);
        $this->assertTrue($unlock->isLockedOut('counter-pin:'.$this->sedeA->id), 'sede A did not lock on its configured third failure');

        // Sede B, unset: four failures leave it open; the fifth (the code default) locks.
        $this->failTimes($this->sedeB, 4);
        $this->assertFalse($unlock->isLockedOut('counter-pin:'.$this->sedeB->id), 'sede B locked early — the default moved');

        $this->failTimes($this->sedeB, 1);
        $this->assertTrue($unlock->isLockedOut('counter-pin:'.$this->sedeB->id), 'sede B did not lock on the fifth failure');
    }

    /** A correct PIN still clears the count — the throttle's shape is unchanged. */
    public function test_a_correct_pin_still_clears_the_throttle(): void
    {
        Settings::set('counter_pin_max_attempts', 3, SettingType::INT, $this->sedeA->id);
        $this->staffAt($this->sedeA, '4321');

        $this->failTimes($this->sedeA, 2);

        $unlock = new UnlockOperator;
        $matched = $unlock->handle($this->sedeA, '4321', 'counter-pin:'.$this->sedeA->id);

        $this->assertNotNull($matched);
        $this->failTimes($this->sedeA, 2);
        $this->assertFalse($unlock->isLockedOut('counter-pin:'.$this->sedeA->id), 'the correct PIN did not reset the tally');
    }

    /** A nonsense stored value is clamped to the 3–10 window, and a broken read falls back to 5. */
    public function test_the_read_is_clamped_and_fails_open(): void
    {
        $unlock = new UnlockOperator;

        Settings::set('counter_pin_max_attempts', 99, SettingType::INT, $this->sedeA->id);
        $this->assertSame(UnlockOperator::MAX_CONFIGURABLE_ATTEMPTS, $unlock->maxAttemptsAt($this->sedeA));

        Settings::set('counter_pin_max_attempts', 1, SettingType::INT, $this->sedeA->id);
        $this->assertSame(UnlockOperator::MIN_CONFIGURABLE_ATTEMPTS, $unlock->maxAttemptsAt($this->sedeA));

        // No location at all (the 'none' bucket's caller) → the code default.
        $this->assertSame(UnlockOperator::MAX_ATTEMPTS, $unlock->maxAttemptsAt(null));
    }

    // --- The sede form's bounds ---------------------------------------------------------------------

    /** 2 and 11 are refused by the form; 3 and 10 are accepted and stored as the sede's Setting row. */
    public function test_the_form_enforces_the_bounds(): void
    {
        $this->ownerWhoManagesStaff();

        foreach ([2 => true, 11 => true, 3 => false, 10 => false] as $value => $shouldFail) {
            $component = Livewire::test(EditLocation::class, ['record' => $this->sedeA->id])
                ->fillForm(['counter_pin_max_attempts' => $value])
                ->call('save');

            if ($shouldFail) {
                $component->assertHasFormErrors(['counter_pin_max_attempts']);
            } else {
                $component->assertHasNoFormErrors();
                $this->assertSame($value, (int) Settings::get('counter_pin_max_attempts', 5, $this->sedeA->id), "{$value} was accepted but not stored");
            }
        }
    }

    // --- Clearing from the admin --------------------------------------------------------------------

    /**
     * `staff.manage` is OWNER-held in the shipped matrix — the authority over who may work the counter, which
     * is exactly why the prompt chose it as the gate. A MANAGER can open Seguridad (lockdown.manage) and sees
     * no lockout section, which the permission test below pins from the other side.
     */
    private function ownerWhoManagesStaff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$this->sedeA->id, $this->sedeB->id]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Clearing a locked bucket removes attempts, lockout AND strikes — the next lockout starts again at 60s —
     * and writes one audit row carrying the counts it wiped.
     */
    public function test_clearing_wipes_everything_and_audits_the_counts(): void
    {
        $this->staffAt($this->sedeA);
        $owner = $this->ownerWhoManagesStaff();
        $key = 'counter-pin:'.$this->sedeA->id;
        $unlock = new UnlockOperator;

        // Two full lockouts: strikes at 2, so the NEXT window would be 900s if the escalation survived.
        $this->failTimes($this->sedeA, 5);
        Cache::forget($key.':lockout');            // wait out the first window without touching strikes
        $this->failTimes($this->sedeA, 5);
        $this->assertTrue($unlock->isLockedOut($key));
        $this->assertSame(2, $unlock->statusFor($key)['strikes']);

        Livewire::test(Seguridad::class)->call('clearPinLockout', $key);

        $status = $unlock->statusFor($key);
        $this->assertFalse($status['locked'], 'the bucket is still locked');
        $this->assertSame(0, $status['attempts'], 'the attempts survived');
        $this->assertSame(0, $status['strikes'], 'the strikes survived — the escalation is still armed');

        // The escalation restarted: the next lockout is the FIRST window again.
        $this->failTimes($this->sedeA, 5);
        $this->assertLessThanOrEqual(UnlockOperator::LOCKOUT_WINDOWS[0], $unlock->lockoutSecondsRemaining($key), 'the next lockout did not start back at 60s');

        // One audit row, with the wiped counts in `before`.
        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'counter.pin.lockout.cleared')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(2, (int) data_get($audit[0]->before, 'strikes'));
        $this->assertTrue((bool) data_get($audit[0]->before, 'was_locked'));
        $this->assertSame($owner->id, (string) data_get($audit[0]->after, 'cleared_by'));
    }

    /** The 'none' bucket — a terminal with no sede adopted — clears the same way. */
    public function test_the_no_sede_bucket_clears_too(): void
    {
        $this->ownerWhoManagesStaff();
        $unlock = new UnlockOperator;

        // Fail against sede A's STAFF but under the 'none' key, which is what a no-sede terminal produces.
        $this->staffAt($this->sedeA);
        for ($i = 0; $i < 5; $i++) {
            $unlock->handle($this->sedeA, '0000', 'counter-pin:none');
        }
        $this->assertTrue($unlock->isLockedOut('counter-pin:none'));

        Livewire::test(Seguridad::class)->call('clearPinLockout', 'counter-pin:none');

        $this->assertFalse($unlock->isLockedOut('counter-pin:none'));
    }

    /** The listing shows every sede plus the no-sede bucket, with the locked state and the strike count. */
    public function test_the_section_lists_every_bucket(): void
    {
        $this->staffAt($this->sedeA);
        $this->ownerWhoManagesStaff();
        $this->failTimes($this->sedeA, 5);

        $html = Livewire::test(Seguridad::class)->html();

        $this->assertStringContainsString('data-pin-lockouts', $html);
        $this->assertStringContainsString('data-pin-bucket="counter-pin:'.$this->sedeA->id.'"', $html);
        $this->assertStringContainsString('data-pin-bucket="counter-pin:'.$this->sedeB->id.'"', $html);
        $this->assertStringContainsString('data-pin-bucket="counter-pin:none"', $html);
        $this->assertStringContainsString('data-pin-locked', $html, 'the locked sede does not read as locked');
        $this->assertStringContainsString('data-pin-clear="counter-pin:'.$this->sedeA->id.'"', $html);
    }

    // --- Permission ---------------------------------------------------------------------------------

    /** Without `staff.manage`: no section, and the ACTION is refused server-side — not merely hidden. */
    public function test_without_staff_manage_the_section_and_the_action_are_refused(): void
    {
        $this->staffAt($this->sedeA);
        $this->failTimes($this->sedeA, 5);

        // A MANAGER opens Seguridad (lockdown.manage) but holds no staff.manage — the realistic near-miss.
        $user = User::factory()->create();
        $user->assignRole(Role::MANAGER->value);
        $user->locations()->sync([$this->sedeA->id]);
        $this->actingAs($user);

        $component = Livewire::test(Seguridad::class);
        $this->assertStringNotContainsString('data-pin-lockouts', $component->html());

        $component->call('clearPinLockout', 'counter-pin:'.$this->sedeA->id)->assertForbidden();

        $this->assertTrue((new UnlockOperator)->isLockedOut('counter-pin:'.$this->sedeA->id), 'the refused call cleared it anyway');
    }

    /** An arbitrary cache key is refused — only keys this screen hands out. */
    public function test_an_arbitrary_key_is_refused(): void
    {
        $this->ownerWhoManagesStaff();

        Livewire::test(Seguridad::class)->call('clearPinLockout', 'counter-pin:not-a-sede')->assertNotFound();
    }

    // --- Never 503 the counter (124) ----------------------------------------------------------------

    /** With the cache down, the throttle fails open and the Seguridad section says "unavailable". */
    public function test_a_cache_outage_degrades_instead_of_erroring(): void
    {
        $this->ownerWhoManagesStaff();

        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('has')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis down'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('redis down'));

        $unlock = new UnlockOperator;

        $this->assertFalse($unlock->isLockedOut('counter-pin:'.$this->sedeA->id), 'an outage locked the counter');
        $this->assertSame(UnlockOperator::MAX_ATTEMPTS, $unlock->maxAttemptsAt($this->sedeA), 'the setting read did not fall back');

        $status = $unlock->statusFor('counter-pin:'.$this->sedeA->id);
        $this->assertFalse($status['available']);

        $html = Livewire::test(Seguridad::class)->html();
        $this->assertStringContainsString(e(__('Estado no disponible')), $html);
    }
}
