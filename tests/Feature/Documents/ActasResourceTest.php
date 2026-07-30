<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateMinute as CreateMinuteAction;
use App\Actions\Documents\SignMinute;
use App\Enums\MinuteBook;
use App\Enums\Role;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Filament\Resources\Minutes\Pages\CreateMinute;
use App\Models\Location;
use App\Models\Minute;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActasResourceTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_index_loads_for_an_owner(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        $this->get(MinuteResource::getUrl('index'))->assertOk();
    }

    public function test_creating_an_acta_through_the_page_routes_through_the_action_and_numbers_sequentially(): void
    {
        $this->actingAs($this->user(Role::OWNER));

        Livewire::test(CreateMinute::class)
            ->fillForm(['book' => MinuteBook::ASSEMBLY->value, 'held_on' => '2026-07-10', 'body' => 'Primera'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateMinute::class)
            ->fillForm(['book' => MinuteBook::ASSEMBLY->value, 'held_on' => '2026-07-11', 'body' => 'Segunda'])
            ->call('create')
            ->assertHasNoFormErrors();

        $numbers = Minute::query()->where('book', MinuteBook::ASSEMBLY->value)->orderBy('number')->pluck('number')->all();
        $this->assertSame([1, 2], $numbers); // sequential, no gaps — set by CreateMinute
        $this->assertSame(2, Minute::query()->count());
    }

    public function test_the_corregir_flow_prefills_a_new_acta_with_the_superseded_id(): void
    {
        $owner = $this->user(Role::OWNER);
        $minute = (new CreateMinuteAction)->handle($this->org, MinuteBook::ASSEMBLY, ['held_on' => '2026-07-10'], $owner);
        (new SignMinute)->handle($minute, $owner);

        // "Corregir" links to the create page carrying the superseded id; the form
        // prefills the hidden supersedes_id from the query — proving the wiring is live.
        $this->actingAs($owner)
            ->get(MinuteResource::getUrl('create').'?supersedes='.$minute->id)
            ->assertOk()
            ->assertSee($minute->id, false);
    }

    public function test_a_signed_acta_cannot_be_edited_through_the_panel(): void
    {
        $owner = $this->user(Role::OWNER);
        $minute = (new CreateMinuteAction)->handle($this->org, MinuteBook::ASSEMBLY, ['held_on' => '2026-07-10', 'body' => 'Original'], $owner);
        (new SignMinute)->handle($minute, $owner);

        // The policy withholds update once signed…
        $this->assertFalse($owner->can('update', $minute->fresh()));

        // …so the edit page is forbidden (not merely a hidden button).
        $this->actingAs($owner)
            ->get(MinuteResource::getUrl('edit', ['record' => $minute->getKey()]))
            ->assertForbidden();
    }

    public function test_the_actas_index_is_forbidden_to_staff(): void
    {
        $staff = $this->user(Role::STAFF);
        $this->assertFalse($staff->can('minutes.manage'));

        $this->actingAs($staff)
            ->get(MinuteResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_acta_pdf_view_renders_with_signature_blocks(): void
    {
        // The acta is an official Spanish legal record; verify its Spanish rendering.
        app()->setLocale('es');
        $owner = $this->user(Role::OWNER);
        $minute = (new CreateMinuteAction)->handle($this->org, MinuteBook::ASSEMBLY, [
            'type' => 'asamblea_general',
            'held_on' => '2026-07-10',
            'agenda' => ['Aprobación de cuentas'],
            'resolutions' => [['texto' => 'Se aprueban', 'favor' => 10, 'contra' => 1, 'abstencion' => 0]],
            'body' => 'Desarrollo de la sesión.',
        ], $owner);

        $html = view('documents.minute', MinuteResource::pdfData($minute))->render();

        $this->assertStringContainsString('Acta nº 1', $html);
        $this->assertStringContainsString('Aprobación de cuentas', $html);
        $this->assertStringContainsString('Secretario', $html);
        $this->assertStringContainsString('Presidente', $html);
    }
}
