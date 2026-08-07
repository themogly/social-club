<?php

namespace Tests\Browser;

use App\Enums\Role;
use App\Livewire\Counter\CounterChrome;
use App\Livewire\Counter\MembershipCounter;
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
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 209 — **step 4 of the report, with and without its bar.**
 *
 * The picture the branch owes is a narrow one: the counter recovered from a handover, the operator restored,
 * the green *"Trabajando: …"* confirmation on screen — and above it either nothing (the bug) or the terminal
 * strip (the fix). Both frames are assembled the same way, from real authed HTML: the page as it stood on the
 * previous full load, with the chrome region replaced by what a Livewire response would have put there.
 *
 * That splice is the honest way to photograph this. The whole defect is that a Livewire response updates the
 * component and not the layout, so a plain `GET` after recovery shows the FIXED state on `main` too — the
 * reload is what always hid the bug.
 */
class HandoverRecoveryHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_recovered_counter_with_and_without_its_chrome(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez', 'pin' => Hash::make('4321')]);
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);

        // 1–3: hand the tablet over, then Back. This is the page the browser is holding, and its chrome was
        // decided while the handover was active.
        Livewire::test(MembershipCounter::class)->call('toggleAlta')->call('handOverForAlta');
        $this->assertTrue(CounterHandover::active());
        $handedOverPage = (string) $this->get(route('counter.members'))->assertOk()->getContent();

        // 4: "Personal del club" → PIN. A Livewire action — no page load, so the layout is never re-rendered.
        CounterOperator::clear();
        $screen = Livewire::test(MembershipCounter::class)->set('operatorPin', '4321')->call('unlockOperator');
        $this->assertFalse(CounterHandover::active());

        $recoveredScreen = $screen->html();
        $chrome = Livewire::test(CounterChrome::class)->dispatch('counter-unlocked')->html();

        // BEFORE: the component swapped, the layout did not — the counter is back and the bar never returns.
        file_put_contents(
            storage_path('app/handover-209-before.html'),
            $this->inlineBuiltCss($this->splice($handedOverPage, '', $recoveredScreen)),
        );

        // AFTER: the chrome is a component now, so the same response brings it back with the counter.
        file_put_contents(
            storage_path('app/handover-209-after.html'),
            $this->inlineBuiltCss($this->splice($handedOverPage, $chrome, $recoveredScreen)),
        );

        $this->assertStringNotContainsString('data-counter-topbar', (string) file_get_contents(storage_path('app/handover-209-before.html')));
        $this->assertStringContainsString('data-counter-topbar', (string) file_get_contents(storage_path('app/handover-209-after.html')));
        foreach (['handover-209-before.html', 'handover-209-after.html'] as $file) {
            $this->assertStringContainsString('Trabajando: Lucía Márquez', (string) file_get_contents(storage_path('app/'.$file)));
        }
    }

    /** Put the recovered component and the chrome region back into the page the browser is holding. */
    private function splice(string $page, string $chrome, string $screen): string
    {
        // The chrome's own element, found by its component name and closed at the first `</div>` — its
        // rendered body during a handover is empty, so there is no nesting to balance.
        $at = strpos($page, 'counter.counter-chrome');
        $this->assertNotFalse($at, 'the chrome component did not render into the page at all');

        $start = (int) strrpos(substr($page, 0, $at), '<div ');
        $end = strpos($page, '</div>', $at);
        $this->assertNotFalse($end);

        $page = substr($page, 0, $start).$chrome.substr($page, (int) $end + strlen('</div>'));

        $open = strpos($page, '<main');
        $close = strrpos($page, '</main>');
        $this->assertNotFalse($open);
        $this->assertNotFalse($close);

        $mainOpenEnd = strpos($page, '>', $open);

        return substr($page, 0, (int) $mainOpenEnd + 1).$screen.substr($page, (int) $close);
    }
}
