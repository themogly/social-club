<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterHandover;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 187 — writes the REAL, authed check-in screen either side of the sede step, with the built CSS
 * inlined, to storage/app/surface-chain-*.html for the Playwright pass
 * (`node tests/Browser/shoot-surface-chain.mjs`).
 *
 * Playwright is not a CI dependency (see the README), so this doubles as the CI structural check: the
 * surface must stay DOWN while the chain is on the sede step — with the top bar, and therefore the sede
 * switcher, reachable — and must raise once the sede is chosen. The pixel proof is the .mjs script.
 */
class SurfaceChainHarnessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function write(string $name, string $html): void
    {
        // ONLY app-*.css — the counter layout loads resources/css/app.css and nothing else. Globbing *.css
        // appends the Filament PANEL theme after it and corrupts the cascade (found in prompt 176).
        $css = '';
        foreach (glob(public_path('build/assets/app-*.css')) ?: [] as $file) {
            $css .= (string) file_get_contents($file);
        }

        if ($css !== '') {
            $html = (string) preg_replace('#<link[^>]*build/assets/[^>]*>#', '', $html);
            $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        }

        file_put_contents(storage_path('app/surface-chain-'.$name.'.html'), $html);
    }

    public function test_it_writes_the_terminal_either_side_of_the_sede_step(): void
    {
        // MANAGER, not OWNER: an owner sees every sede in the org, so the "must choose" state is reached
        // differently. Two ASSIGNED sedes with none chosen is the reported case — a fresh terminal for an
        // operator who works in more than one sede, which is every first run.
        $user = User::factory()->create(['name' => 'Marta Ruiz', 'pin' => Hash::make('4321')]);
        $user->assignRole(Role::MANAGER->value);
        $centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte']);
        $user->locations()->sync([$centro->id, $norte->id]);

        // Write both artifacts BEFORE asserting, so the same harness can be run against an older commit to
        // capture the "before" side of the comparison (the same reason prompt 175's harness does it).

        // 1) NO SEDE — the fresh terminal. The chain is on the sede step and the surface must stay down.
        $noSede = $this->actingAs($user)->get(route('counter.checkin'))->getContent();
        $this->write('no-sede', $noSede);

        // 2) SEDE CHOSEN, still no operator — now it is the operator step's turn and the surface raises.
        $withSede = $this->actingAs($user)
            ->withSession(['counter.location_id' => $centro->id])
            ->get(route('counter.checkin'))->getContent();
        $this->write('with-sede', $withSede);

        // 3) IDENTIFIED — prompt 188's "after". Same sede, now with an operator: the surface is DOWN and the
        // counter is on screen. Paired with with-sede.html this is the before/after of identifying.
        session(['counter.location_id' => $centro->id]);
        CounterOperator::set($user);
        $identified = $this->actingAs($user)->get(route('counter.checkin'))->getContent();
        $this->write('identified', $identified);
        CounterOperator::clear();

        // 4) HANDED OVER — prompt 187 defect 2. The applicant left their form and landed back here; this is
        // the resting state and the way back, which together used to be a box promising a form.
        app(ActiveScope::class)->setLocation($centro->id);
        CounterOperator::set($user);
        Livewire::actingAs($user)->test(TillSession::class)->call('beginHandover');
        // session(), not withSession(): the handover lives in the session too, and withSession() would
        // reset it out from under the very state being captured.
        session(['counter.location_id' => $centro->id]);
        $handover = $this->actingAs($user)->get(route('counter.checkin'))->getContent();
        $this->write('handover', $handover);
        CounterHandover::end();

        // --- now the assertions ---

        // The bug: the surface raised here, covering the sede blocker AND the top bar that carries the only
        // control which could resolve it.
        $this->assertStringContainsString('data-surface-mode="none"', $noSede);
        $this->assertStringContainsString('data-blocker="sede"', $noSede);
        $this->assertStringContainsString('data-counter-topbar', $noSede);
        $this->assertSame(1, substr_count($noSede, 'data-counter-blocker'));

        // And once the sede is chosen the surface owns its own step exactly as prompt 173 designed.
        $this->assertStringContainsString('data-surface-mode="unidentified"', $withSede);
        $this->assertStringNotContainsString('data-blocker="sede"', $withSede);
        $this->assertStringContainsString('data-counter-surface-unlock', $withSede);

        // Identified: no surface, and the counter's own chrome is back.
        $this->assertStringContainsString('data-surface-mode="none"', $identified);
        $this->assertStringContainsString('data-counter-topbar', $identified);

        // Handed-over mode offers a real way back, and still names nobody and nothing.
        $this->assertStringContainsString('data-surface-mode="handover"', $handover);
        $this->assertStringContainsString('data-handover-staff', $handover);
        $this->assertStringNotContainsString('data-counter-topbar', $handover);
        $this->assertStringNotContainsString('Sede Centro', $handover);
    }
}
