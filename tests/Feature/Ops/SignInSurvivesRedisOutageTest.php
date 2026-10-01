<?php

namespace Tests\Feature\Ops;

use App\Actions\Till\OpenTill;
use App\Actions\UnlockOperator;
use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SystemHealth;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\PanelIdentity;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ExplodingStore;
use Tests\TestCase;

/**
 * Prompt 344 — with Redis stopped, the panel login bounced back to the form with no message
 * (`RateLimiter::attempts()` → the redis store → Connection refused), and the counter PIN pad's tally — on the same
 * default store — read every attempt as 0, so it let a correct PIN in but could never lock out. 124 had moved the
 * permission cache off Redis for exactly this reason; the sign-in limits had stayed on it. They now live on
 * `cache.limiter` (`database` by default).
 */
class SignInSurvivesRedisOutageTest extends TestCase
{
    use RefreshDatabase;

    private Location $sede;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($this->sede->id);
        $this->owner = User::factory()->create(['email' => 'ben@club.test', 'password' => 'secret-pass', 'pin' => Hash::make('1234'), 'active' => true]);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([$this->sede->id]);
        $this->owner->can('pos.use'); // warm the permission cache (database store, 124)
    }

    /** Redis down: the DEFAULT store throws on every call. The limiter keeps its configured store. */
    private function redisDown(): void
    {
        Cache::extend('exploding', fn (): Repository => new Repository(new ExplodingStore));
        config(['cache.stores.exploding' => ['driver' => 'exploding'], 'cache.default' => 'exploding']);
        Cache::forgetDriver();
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
    }

    private function login(string $password): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(Login::class)->set('data.email', 'ben@club.test')->set('data.password', $password)->call('authenticate');
    }

    // --- 1. The panel login ------------------------------------------------------------------------------------------------

    public function test_with_redis_down_a_correct_panel_login_succeeds(): void
    {
        $this->redisDown();

        $login = $this->login('secret-pass');

        $this->assertAuthenticatedAs($this->owner);
        $this->assertNotSame('', (string) ($login->effects['redirect'] ?? ''), 'the login bounced back to the form');
    }

    public function test_with_redis_down_five_wrong_passwords_still_trip_the_throttle(): void
    {
        $this->redisDown();

        foreach (range(1, 5) as $i) {
            $this->login('wrong-'.$i);
        }
        $this->login('secret-pass'); // the sixth attempt, correct, inside the window: refused by the throttle

        $this->assertGuest();
    }

    // --- 2. The counter PIN pad -------------------------------------------------------------------------------------------------

    private function pad(): Testable
    {
        $this->actingAs($this->owner);
        session(['counter.location_id' => $this->sede->id]);
        (new OpenTill)->handle($this->sede, 'POS-1', 10000);
        CounterOperator::clear();

        return Livewire::test(TillSession::class);
    }

    public function test_with_redis_down_the_pin_pad_accepts_a_correct_pin(): void
    {
        $pad = $this->pad();
        $this->redisDown();

        $pad->set('operatorPin', '1234')->call('unlockOperator');

        $this->assertSame($this->owner->id, CounterOperator::id());
    }

    public function test_with_redis_down_the_pin_pad_still_locks_out_after_the_configured_wrong_pins(): void
    {
        $pad = $this->pad();
        $this->redisDown();
        $unlock = new UnlockOperator;
        $max = $unlock->maxAttemptsAt($this->sede);

        foreach (range(1, $max) as $i) {
            $pad->set('operatorPin', '9'.str_pad((string) $i, 3, '0', STR_PAD_LEFT))->call('unlockOperator');
        }

        $this->assertTrue($unlock->isLockedOut('counter-pin:'.$this->sede->id), 'the pad could not count with Redis down');
        $pad->set('operatorPin', '1234')->call('unlockOperator');
        $this->assertNull(CounterOperator::id(), 'a correct PIN got through a lockout');
    }

    // --- 3. Where the counters live ------------------------------------------------------------------------------------------------

    public function test_the_throttle_counters_live_in_the_cache_table(): void
    {
        $this->assertSame('database', config('cache.limiter'));
        $this->redisDown();

        $this->login('wrong-1');
        $this->pad()->set('operatorPin', '9999')->call('unlockOperator');

        $keys = DB::table('cache')->pluck('key')->implode(' ');
        $this->assertStringContainsString('counter-pin:'.$this->sede->id.':attempts', $keys);
        $this->assertMatchesRegularExpression('/livewire-rate-limiter:[0-9a-f]+/', $keys, 'Filament’s login limiter');
    }

    public function test_the_after_pin_password_confirmation_survives_too(): void
    {
        $this->redisDown();
        $this->actingAs($this->owner);
        session(['auth.via_pin' => true]);

        PanelIdentity::confirmed($this->owner); // threw with Redis down before 344

        $this->assertFalse(PanelIdentity::mustConfirm($this->owner));
    }

    // --- 4. The health card says what is now true ----------------------------------------------------------------------------------

    public function test_the_health_card_says_login_and_pins_keep_working(): void
    {
        $this->redisDown();
        $this->actingAs($this->owner);

        $this->get(SystemHealth::getUrl())->assertOk()
            ->assertSee(__('No accesible'))
            ->assertSee(__('El inicio de sesión y los PIN también (los límites de intentos están en base de datos).'))
            ->assertDontSee(__('El inicio de sesión y los PIN dependen de esta caché: configura CACHE_LIMITER=database.'));

        // Misconfigured — the limiter on the same failing store: the card says so instead of promising.
        config(['cache.limiter' => 'exploding']);
        $this->get(SystemHealth::getUrl())->assertOk()
            ->assertSee(__('El inicio de sesión y los PIN dependen de esta caché: configura CACHE_LIMITER=database.'));
    }
}
