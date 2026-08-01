<?php

namespace Tests\Feature\Install;

use App\Enums\ExpenseKind;
use App\Enums\Role;
use App\Models\ExpenseCategory;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\Rat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Prompt 78 — `csc:install` is the production path to create the organisation and first owner that never
 * existed. Its acceptance is concrete: the RAT data controller stops being blank, and someone can log in.
 */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function installOptions(array $overrides = []): array
    {
        return array_merge([
            '--name' => 'Club Cannábico Ejemplo',
            '--legal-name' => 'Asociación Cannábica Ejemplo',
            '--tax-id' => 'G12345678',
            '--contact-email' => 'info@example.es',
            '--owner-name' => 'Ana Propietaria',
            '--owner-email' => 'ana@example.es',
            '--owner-password' => 'sup3rsecret',
        ], $overrides);
    }

    public function test_it_creates_the_organisation_and_owner_and_the_rat_controller_resolves(): void
    {
        $this->artisan('csc:install', $this->installOptions())->assertSuccessful();

        $org = Organisation::query()->firstOrFail();
        $this->assertSame('Asociación Cannábica Ejemplo', $org->legal_name);

        // The RAT data controller — previously blank — now resolves to the registered legal name.
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->assertSame('Asociación Cannábica Ejemplo', (new Rat)->controller()['legal_name']);

        $owner = User::query()->where('email', 'ana@example.es')->firstOrFail();
        $this->assertTrue($owner->hasRole(Role::OWNER->value));
        $this->assertTrue($owner->canAccessPanel(app('filament')->getPanel('admin')));
        $this->assertTrue(Hash::check('sup3rsecret', (string) $owner->password)); // stored hashed, never plain
    }

    public function test_it_seeds_the_default_expense_categories_for_the_new_org(): void
    {
        $this->artisan('csc:install', $this->installOptions())->assertSuccessful();
        $org = Organisation::query()->firstOrFail();

        // The club can record expenses from day one — the default set is scoped to the new org and includes
        // the TILL (petty cash) category the till expense flow needs (prompt 117).
        $this->assertGreaterThan(0, ExpenseCategory::query()->where('organisation_id', $org->id)->count());
        $this->assertTrue(
            ExpenseCategory::query()->where('organisation_id', $org->id)->where('default_kind', ExpenseKind::TILL)->exists(),
        );
    }

    public function test_it_refuses_when_an_organisation_already_exists(): void
    {
        Organisation::factory()->create();

        $this->artisan('csc:install', $this->installOptions())->assertFailed();

        $this->assertSame(1, Organisation::query()->count());          // unchanged
        $this->assertSame(0, User::query()->where('email', 'ana@example.es')->count());
    }

    public function test_it_refuses_a_blank_legal_name_so_the_rat_is_never_empty(): void
    {
        $this->artisan('csc:install', $this->installOptions(['--legal-name' => '']))->assertFailed();

        $this->assertSame(0, Organisation::query()->count());
    }
}
