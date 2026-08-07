<?php

namespace Tests\Browser;

use App\Actions\Till\HandOverTill;
use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\TillSession as TillScreen;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\TestCase;

/** Prompt 186 — writes the handover states for the screenshot pass (`node tests/Browser/shoot-till-handover.mjs`). */
class TillHandoverHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_the_handover_states(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);

        $ana = $this->staff($org, $location, 'Ana Serra', Role::MANAGER);
        $bea = $this->staff($org, $location, 'Bea Lloret', Role::MANAGER);

        $this->actingAs($ana);
        app(ActiveScope::class)->setLocation($location->id);
        CounterOperator::set($ana);
        $session = (new OpenTill)->handle($location, 'POS-1', 10000, ['operator_id' => $ana->id]);

        // 1) a handover in progress — the panel open, nothing revealing the expected figure
        $this->write('inprogress', Livewire::test(TillScreen::class)->call('toggleHandover')->html());

        // 2) an arqueo covering two shifts
        (new HandOverTill)->handle($session->fresh(), 10000, $ana, $bea);
        CounterOperator::set($bea);
        $this->actingAs($bea);
        $this->write('twoshifts', Livewire::test(TillScreen::class)->html());

        foreach (['inprogress', 'twoshifts'] as $state) {
            $this->assertStringContainsString('data-handover', (string) file_get_contents(storage_path('app/handover-'.$state.'.html')));
        }
    }

    private function staff(Organisation $org, Location $location, string $name, Role $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role->value);
        $user->locations()->sync([$location->id]);

        return $user;
    }

    private function write(string $name, string $componentHtml): void
    {
        $html = view('components.layouts.counter', [
            'slot' => new HtmlString($componentHtml),
            'title' => 'Caja',
            'fullHeight' => false,
        ])->render();

        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/handover-'.$name.'.html'), $html);
    }
}
