<?php

namespace Tests\Feature\Documents;

use App\Enums\Role;
use App\Filament\Pages\LibroSocios;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class LibroSociosPageTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        Location::factory()->create(['organisation_id' => $this->org->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_it_renders_as_at_and_lists_a_seeded_member(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'member_no' => 'M-777', 'joined_at' => '2026-06-01',
        ]);

        Livewire::test(LibroSocios::class)
            ->assertOk()
            ->assertSet('asAt', '2026-07-15')  // "as at" defaults to today
            ->assertSee($member->member_no);
    }

    public function test_the_csv_export_contains_a_seeded_member(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'member_no' => 'M-555', 'joined_at' => '2026-06-01',
        ]);

        $page = new LibroSocios;
        $page->asAt = '2026-07-15';
        $csv = $this->streamed($page->exportCsv());

        $this->assertStringContainsString('M-555', $csv);
        $this->assertStringContainsString(__('Nº socio'), $csv); // header row present
    }

    public function test_a_later_joiner_is_excluded_as_at_an_earlier_date(): void
    {
        $this->actingAs($this->user(Role::OWNER));
        Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-EARLY', 'joined_at' => '2026-01-01']);
        Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-LATER', 'joined_at' => '2026-07-10']);

        $page = new LibroSocios;
        $page->asAt = '2026-03-01';
        $csv = $this->streamed($page->exportCsv());

        $this->assertStringContainsString('M-EARLY', $csv);
        $this->assertStringNotContainsString('M-LATER', $csv);
    }

    public function test_the_page_is_forbidden_to_staff(): void
    {
        $owner = $this->user(Role::OWNER);
        $staff = $this->user(Role::STAFF);

        $this->actingAs($owner);
        $this->assertTrue(LibroSocios::canAccess());

        $this->actingAs($staff);
        $this->assertFalse($staff->can('register.view'));
        $this->assertFalse(LibroSocios::canAccess());
        $this->get(LibroSocios::getUrl())->assertForbidden();
    }
}
