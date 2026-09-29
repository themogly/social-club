<?php

namespace Tests\Feature\Ops;

use App\Enums\LocationKind;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Filament\Pages\SystemHealth;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\PinLookup;
use App\Support\Reset\RedisPurger;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DevAdminSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 327 — `csc:seed-staging`: a staging site (formacion.padron.app) gets a believable, entirely FICTIONAL club from
 * one command — never a copy of live. It refuses unless the environment is staging (or local), no club has launched, and
 * (with --fresh) the operator typed «staging». The demo seeders run on staging ONLY through it; never on production.
 * Staging also says so on every page, and its health page goes red if it could send anything real.
 */
class SeedStagingTest extends TestCase
{
    private string $storage;

    /** @var list<list<string>> */
    public static array $purged = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Not RefreshDatabase: the command runs a real `migrate:fresh` (as ResetForLaunchTest does).
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->storage = sys_get_temp_dir().'/csc-staging-'.bin2hex(random_bytes(4));
        foreach (['app/public/genetics', 'app/member-imports', 'framework/views', 'framework/cache', 'framework/sessions'] as $dir) {
            File::ensureDirectoryExists($this->storage.'/'.$dir);
        }
        $this->app->useStoragePath($this->storage);
        config(['filesystems.disks.public.root' => $this->storage.'/app/public']);
        Storage::forgetDisk('public');
        File::put($this->storage.'/app/public/genetics/old.jpg', 'jpeg');
        File::put($this->storage.'/app/member-imports/old.csv', 'a,b');

        self::$purged = [];
        $this->app->instance(RedisPurger::class, new class extends RedisPurger
        {
            public function check(): void {}

            public function purge(): int
            {
                SeedStagingTest::$purged[] = $this->prefixes();

                return 2;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function anExistingClub(): Organisation
    {
        $this->seed(RolePermissionSeeder::class);

        return Organisation::factory()->create(['name' => 'Club existente']);
    }

    // --- 1–4. Refusals ----------------------------------------------------------------------------------------------------

    public function test_production_refuses_and_nothing_changes(): void
    {
        $this->anExistingClub();
        $this->app['env'] = 'production';

        $this->artisan('csc:seed-staging', ['--fresh' => true])->expectsOutputToContain('production')->assertFailed();

        $this->assertSame('Club existente', Organisation::query()->sole()->name);
        $this->assertFileExists($this->storage.'/app/public/genetics/old.jpg');
        $this->assertSame([], self::$purged);
    }

    public function test_a_launched_club_refuses(): void
    {
        $this->app['env'] = 'staging';
        $this->anExistingClub()->forceFill(['launched_at' => now()->subDay()])->save();

        $this->artisan('csc:seed-staging', ['--fresh' => true])->assertFailed();
        $this->assertSame(1, Organisation::query()->count());
    }

    public function test_fresh_without_typing_staging_refuses(): void
    {
        $this->app['env'] = 'staging';
        $this->anExistingClub();

        $this->artisan('csc:seed-staging', ['--fresh' => true])
            ->expectsQuestion(__('Escribe «staging» para borrar todo y empezar de cero'), 'yes')
            ->assertFailed();
        $this->assertSame('Club existente', Organisation::query()->sole()->name);
        $this->assertSame([], self::$purged);
    }

    public function test_an_existing_database_without_fresh_refuses(): void
    {
        $this->app['env'] = 'staging';
        $this->anExistingClub();

        $this->artisan('csc:seed-staging')->expectsOutputToContain(__('Ya hay datos. Usa --fresh para empezar de cero.'))->assertFailed();
        $this->assertSame(1, Organisation::query()->count());
    }

    // --- 5. Seeding -------------------------------------------------------------------------------------------------------

    public function test_staging_fresh_seeds_the_fictional_demo_club_and_prints_the_logins(): void
    {
        $this->app['env'] = 'staging';
        $this->anExistingClub();

        $this->artisan('csc:seed-staging', ['--fresh' => true])
            ->expectsQuestion(__('Escribe «staging» para borrar todo y empezar de cero'), 'staging')
            ->expectsOutputToContain('owner@club.test  ·  PIN 1234')
            ->expectsOutputToContain('manager@club.test  ·  PIN 2345')
            ->expectsOutputToContain('staff@club.test  ·  PIN 3456')
            ->assertSuccessful();

        $this->assertFalse(Organisation::query()->where('name', 'Club existente')->exists(), 'the old data survived --fresh');
        $this->assertFileDoesNotExist($this->storage.'/app/public/genetics/old.jpg');
        $this->assertFileDoesNotExist($this->storage.'/app/member-imports/old.csv');
        $this->assertCount(1, self::$purged);

        $org = Organisation::query()->sole();
        app(ActiveScope::class)->setOrganisation($org->id);
        $store = Location::query()->withoutGlobalScopes()->where('kind', LocationKind::ALMACEN->value)->sole();
        $split = Batch::query()->withoutGlobalScopes()->where('location_id', $store->id)->sole();
        $this->assertSame(2, Batch::query()->withoutGlobalScopes()->where('batch_no', $split->batch_no)->count(), 'no batch split between the store and a sede');
        $this->assertSame(7, Genetic::query()->where('product_type', ProductType::EDIBLE->value)->sole()->grams_per_unit_cg, 'the edible is not by THC');
        $this->assertSame(100, Genetic::query()->where('product_type', ProductType::PREROLL->value)->sole()->grams_per_unit_cg);
        $this->assertTrue(Batch::query()->withoutGlobalScopes()->where('remaining_cg', 0)->exists(), 'no empty batch');
        $this->assertTrue(Membership::query()->withoutGlobalScopes()->get()->contains(fn (Membership $m): bool => $m->owedCents() > 0), 'nobody owes a fee');
        $this->assertFalse(Member::query()->withoutGlobalScopes()->get()->contains(fn (Member $m): bool => preg_match('/^\d{8}[A-Z]$/', (string) $m->document_number) === 1), 'a real-looking DNI was seeded');

        foreach (['owner@club.test' => '1234', 'manager@club.test' => '2345', 'staff@club.test' => '3456'] as $email => $pin) {
            $this->assertSame($email, User::query()->where('pin_lookup', PinLookup::for($pin))->value('email'));
        }
    }

    public function test_plain_db_seed_skips_the_demo_seeders_on_staging_and_production(): void
    {
        foreach (['staging', 'production'] as $env) {
            $this->app['env'] = $env;
            $this->app->instance('csc.seeding_staging', $env === 'production'); // production ignores even the flag
            foreach ([RolePermissionSeeder::class, DevAdminSeeder::class, DemoDataSeeder::class] as $seeder) {
                $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->assertSuccessful();
            }

            $this->assertSame(0, Organisation::query()->count(), "{$env}: the demo club was seeded by a plain db:seed");
            $this->assertSame(0, User::query()->where('email', 'owner@club.test')->count(), "{$env}: the dev accounts were seeded");
            $this->app->forgetInstance('csc.seeding_staging');
        }
    }

    // --- 7–8. The marker and the health row ----------------------------------------------------------------------------------

    public function test_the_staging_strip_shows_on_staging_on_the_panel_and_the_counter_and_not_on_production(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);

        $this->app['env'] = 'staging';
        foreach (['/', '/counter'] as $page) {
            $html = (string) $this->get($page)->getContent();
            $this->assertStringContainsString('data-staging-strip', $html, "no staging strip on {$page}");
            $this->assertStringContainsString(__('ENTORNO DE PRUEBAS — no es el club real'), $html);
            $page === '/counter'
                ? $this->assertMatchesRegularExpression('/<title>\s*'.preg_quote(__('[Pruebas]'), '/').'/', $html, 'no [Pruebas] title on the counter')
                : $this->assertStringContainsString('data-staging-title', $html, 'no [Pruebas] title prefix on the panel');
        }

        $this->app['env'] = 'production';
        foreach (['/', '/counter'] as $page) {
            $this->assertStringNotContainsString('data-staging-strip', (string) $this->get($page)->getContent());
        }
    }

    public function test_the_health_page_goes_red_on_staging_if_it_could_send_anything_real(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
        $this->app['env'] = 'staging';

        config(['mail.default' => 'log', 'services.telegram.token' => null, 'filesystems.disks.documents.driver' => 'local']);
        Livewire::test(SystemHealth::class)->assertDontSee(__('Staging no debe enviar nada real.'));

        config(['mail.default' => 'resend']);
        Livewire::test(SystemHealth::class)->assertSee(__('Staging no debe enviar nada real.'));

        config(['mail.default' => 'log', 'services.telegram.token' => '123:abc']);
        Livewire::test(SystemHealth::class)->assertSee(__('Staging no debe enviar nada real.'));
    }
}
