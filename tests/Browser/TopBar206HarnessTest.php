<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 206 — the terminal strip in BOTH locales, for `shoot-topbar-destinations.mjs`.
 *
 * Two files rather than one, because **the defect only existed in English**: `lang/en.json` maps
 * `"Panel"` → `"Dashboard"`, so an English operator read *Home* and *Dashboard* side by side while a Spanish
 * one read *Inicio* and *Panel*. The English capture is the bug report; the Spanish one proves the fix did
 * not quietly break the locale that staff actually work in.
 *
 * Deliberately NOT merged into `TopbarHarnessTest`: that file is prompt 132/205's measurement harness and its
 * one output feeds `measure-topbar.mjs`. This writes pictures.
 */
class TopBar206HarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_bar_in_both_locales(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($location->id);

        foreach (['es', 'en'] as $locale) {
            $user = User::factory()->create(['name' => 'Lucía Márquez', 'locale' => $locale]);
            $user->assignRole(Role::OWNER->value);
            $user->locations()->sync([$location->id]);
            $this->actingAs($user);
            session(['counter.location_id' => $location->id]);
            CounterOperator::set($user);

            $html = (string) $this->get(route('counter.checkin'))->assertOk()->getContent();

            file_put_contents(storage_path('app/topbar-206-'.$locale.'.html'), $this->inlineBuiltCss($html));
        }

        $this->assertFileExists(storage_path('app/topbar-206-es.html'));
        $this->assertFileExists(storage_path('app/topbar-206-en.html'));
    }
}
