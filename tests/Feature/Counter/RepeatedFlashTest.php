<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Post-296 completeness D3 — prompt 279 proved that the SAME confirmation twice in a row morphed onto the first one's
 * already-faded element and showed nothing, and fixed it on the till only. The other four counter screens keyed their
 * flash by the message alone: a second identical "Registrado" was invisible, which invites a double tap.
 */
class RepeatedFlashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $sede = Location::factory()->create(['organisation_id' => $org->id]);
        app(ActiveScope::class)->setLocation($sede->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$sede->id]);
        $this->actingAs($owner);
        CounterOperator::set($owner);
        (new OpenTill)->handle($sede, 'POS-1', 10000);
    }

    public function test_every_counter_screen_numbers_its_flashes(): void
    {
        foreach ([DispensaryPos::class, BarPos::class, CheckInScreen::class, MembershipCounter::class] as $screen) {
            $component = Livewire::test($screen);
            $before = (int) $component->get('flashSeq');

            $component->invade()->flash('Registrado', 'success');
            $component->invade()->flash('Registrado', 'success');

            $this->assertSame($before + 2, (int) $component->get('flashSeq'), class_basename($screen).' does not number its flashes');
        }
    }

    public function test_the_shared_flash_keys_on_the_number_so_a_repeat_is_a_new_element(): void
    {
        $render = fn (int $seq): string => view('livewire.counter.partials.counter-flash', [
            'flashMessage' => 'Registrado', 'flashType' => 'success', 'flashSeq' => $seq, 'anchor' => 'data-commit-feedback',
        ])->render();

        preg_match('/wire:key="([^"]+)"/', $render(1), $first);
        preg_match('/wire:key="([^"]+)"/', $render(2), $second);

        $this->assertNotSame($first[1], $second[1], 'the same message twice reuses one element');
    }
}
