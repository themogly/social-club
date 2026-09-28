<?php

namespace Tests\Feature\Counter;

use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\PinLookup;
use App\Support\WorkedHours;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 286 — the PIN answers straight away.
 *
 * Every PIN attempt ran `Hash::check` (bcrypt, cost 12 ≈ 310 ms) against EVERY active person at the sede — since 270 on
 * purpose, to refuse a PIN two people share — so 10 people meant ~3 s per attempt, paid again for "Fichar salida" and the
 * supervisor PIN. Saving a PIN checked it against every user in the organisation. For a 4–8 digit PIN bcrypt buys little
 * (the keyspace is tiny); the online throttle is the protection. Now a PIN is found by ONE indexed lookup of a keyed
 * HMAC; legacy bcrypt PINs upgrade lazily on their owner's first correct PIN, and their hash is then nulled.
 */
class PinLookupTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Centro']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Norte']);
        app(ActiveScope::class)->setLocation($this->centro->id);
    }

    /** A person at a sede whose PIN is stored the NEW way (the model writes the lookup from a plain PIN). */
    private function upgraded(string $pin, ?Location $at = null, bool $active = true): User
    {
        $user = User::factory()->create(['pin' => $pin, 'active' => $active]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->attach(($at ?? $this->centro)->id);

        return $user;
    }

    /** A person whose PIN is a bcrypt hash from before this prompt — written raw, as the old code left it. */
    private function legacy(string $pin, ?Location $at = null): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['pin' => Hash::make($pin), 'pin_lookup' => null]);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->attach(($at ?? $this->centro)->id);

        return $user->fresh();
    }

    private function unlock(string $pin, ?Location $at = null): ?User
    {
        return (new UnlockOperator)->handle($at ?? $this->centro, $pin, 'test:pin:'.($at ?? $this->centro)->id);
    }

    // --- Speed and the lookup ------------------------------------------------------------------------------------

    public function test_with_ten_upgraded_people_a_pin_attempt_runs_no_bcrypt_check(): void
    {
        $people = collect(range(1, 10))->map(fn (int $i): User => $this->upgraded((string) (1000 + $i)));

        Hash::partialMock()->shouldReceive('check')->never();

        $this->assertTrue($this->unlock('1007')?->is($people[6]));
        $this->assertNull($this->unlock('9999'));
    }

    public function test_a_legacy_pin_upgrades_on_first_use_and_is_never_bcrypt_checked_again(): void
    {
        $marta = $this->legacy('4321');

        $this->assertTrue($this->unlock('4321')?->is($marta));

        $row = DB::table('users')->where('id', $marta->id)->first();
        $this->assertNull($row->pin, 'the bcrypt hash is nulled after the upgrade');
        $this->assertSame(PinLookup::for('4321'), $row->pin_lookup);

        Hash::partialMock()->shouldReceive('check')->never();
        $this->assertTrue($this->unlock('4321')?->is($marta));
    }

    public function test_the_legacy_scan_only_covers_people_without_a_lookup(): void
    {
        collect(range(1, 5))->each(fn (int $i) => $this->upgraded((string) (2000 + $i)));
        $this->legacy('4321');
        $this->legacy('8765');

        // Two legacy people at the sede → at most two bcrypt checks for a PIN nobody has.
        Hash::partialMock()->shouldReceive('check')->twice()->andReturnUsing(fn (string $value, string $hash): bool => password_verify($value, $hash));

        $this->assertNull($this->unlock('0000'));
    }

    public function test_two_legacy_people_sharing_a_pin_are_refused_and_a_taken_new_pin_cannot_be_stored(): void
    {
        $this->legacy('4321');
        $this->legacy('4321');

        $this->assertNull($this->unlock('4321'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'counter.pin.ambiguous']);

        $this->upgraded('5555');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->upgraded('5555', $this->norte);
    }

    public function test_saving_a_pin_among_twenty_people_runs_no_bcrypt_check(): void
    {
        collect(range(1, 20))->each(fn (int $i) => $this->upgraded((string) (3000 + $i)));

        Hash::partialMock()->shouldReceive('check')->never();

        $this->assertTrue(User::pinIsTaken('3005'));
        $this->assertFalse(User::pinIsTaken('7777'));
    }

    // --- Throttle and security ------------------------------------------------------------------------------------

    public function test_a_wrong_pin_still_counts_and_the_escalation_is_unchanged(): void
    {
        $this->upgraded('1111');
        $throttle = new UnlockOperator;
        $max = $throttle->maxAttemptsAt($this->centro);

        foreach (range(1, $max) as $n) {
            $this->assertSame($max - $n + 1, $throttle->attemptsRemaining($this->centro, 'test:pin:'.$this->centro->id));
            $this->assertNull($this->unlock('9999'));
        }

        $this->assertTrue($throttle->isLockedOut('test:pin:'.$this->centro->id));
        $this->assertSame(UnlockOperator::LOCKOUT_WINDOWS[0], $throttle->lockoutSecondsRemaining('test:pin:'.$this->centro->id));
        $this->assertNull($this->unlock('1111'), 'a correct PIN is refused while locked out');
    }

    public function test_an_inactive_or_removed_person_cannot_sign_in_with_a_matching_lookup(): void
    {
        $this->upgraded('1212', active: false);
        $this->upgraded('3434', $this->norte);

        $this->assertNull($this->unlock('1212'));
        $this->assertNull($this->unlock('3434'), 'a person from another sede');
    }

    public function test_a_lookup_only_pin_counts_as_having_a_pin(): void
    {
        $user = $this->upgraded('5656');

        $this->assertNull($user->fresh()->getRawOriginal('pin'));
        $this->assertNotContains('no_pin', $user->fresh()->setupIncompleteReasons());
        $this->assertTrue($user->fresh()->hasPin());
    }

    // --- The upgrade status, and the rest of the round trip ---------------------------------------------------------

    public function test_the_upgrade_status_command_reports_counts_and_writes_nothing(): void
    {
        $this->upgraded('1000');
        $this->legacy('2000');
        $this->legacy('3000');
        User::factory()->create(['pin' => null]); // no PIN at all

        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $this->assertSame(0, Artisan::call('csc:pin-upgrade-status'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\b2\b/', $output);
        $this->assertStringContainsString('1', $output);
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_open_period_for_runs_bounded_queries_whatever_the_history(): void
    {
        $marta = $this->upgraded('1000');
        $this->travelTo(now()->subDays(60));
        foreach (range(1, 40) as $day) {
            $this->travel(1)->days();
            (new ClockIn)->handle($marta, $this->centro, $marta);
            $this->travel(4)->hours();
            (new ClockOut)->handle($marta, $marta, StaffClockSource::PIN);
        }
        $this->travelBack();
        (new ClockIn)->handle($marta, $this->centro, $marta);
        $this->assertSame(81, StaffClockEvent::query()->count());

        $loaded = 0;
        Event::listen('eloquent.retrieved: '.StaffClockEvent::class, function () use (&$loaded): void {
            $loaded++;
        });
        DB::enableQueryLog();
        $open = WorkedHours::openPeriodFor($marta);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotNull($open);
        $this->assertLessThanOrEqual(3, $queries);
        $this->assertLessThanOrEqual(3, $loaded, 'openPeriodFor() loads the latest IN and what follows, not a year of shifts');
    }
}
