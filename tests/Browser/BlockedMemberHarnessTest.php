<?php

namespace Tests\Browser;

use App\Actions\Till\OpenTill;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Livewire\Counter\DispensaryPos;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Browser\Concerns\InlinesBuiltCss;
use Tests\TestCase;

/**
 * Prompt 211 — the reported screen: an ACTIVE member on the dispensary with no membership at this sede.
 *
 * Before, that read *"Sin membresía activa en esta sede"* / *"Renueva su cuota desde su ficha"* with nothing
 * to press. The picture is the verdict panel, so the harness holds the member and renders the real component.
 */
class BlockedMemberHarnessTest extends TestCase
{
    use InlinesBuiltCss, RefreshDatabase;

    public function test_it_writes_the_blocked_member_on_the_dispensary(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['name' => 'Club Verde']);
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id, 'name' => 'Sede Centro', 'capacity' => 50]);
        app(ActiveScope::class)->setLocation($location->id);

        $user = User::factory()->create(['name' => 'Lucía Márquez']);
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$location->id]);
        $this->actingAs($user);
        session(['counter.location_id' => $location->id]);
        CounterOperator::set($user);
        (new OpenTill)->handle($location, 'POS-1', 10000);

        $member = Member::factory()->create([
            'organisation_id' => $org->id,
            'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'status' => MemberStatus::ACTIVE,
            'date_of_birth' => now()->subYears(34),
            'carencia_ends_at' => now()->subDay(),
        ]);

        $page = (string) $this->get(route('counter.pos'))->assertOk()->getContent();
        $held = Livewire::test(DispensaryPos::class)->call('selectMember', $member->id)->html();

        $open = (int) strpos($page, '<main');
        $close = (int) strrpos($page, '</main>');
        $page = substr($page, 0, (int) strpos($page, '>', $open) + 1).$held.substr($page, $close);

        file_put_contents(storage_path('app/blocked-member-211.html'), $this->inlineBuiltCss($page));

        $this->assertStringContainsString('Ana Ruiz', $page, 'the harness has no member on screen');
        $this->assertStringContainsString('Sin membresía activa en esta sede.', $page, 'the member is not blocked');
    }
}
