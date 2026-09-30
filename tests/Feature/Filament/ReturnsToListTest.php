<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipPeriod;
use App\Enums\Role;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use App\Filament\Resources\MembershipTiers\Pages\CreateMembershipTier;
use App\Filament\Resources\MembershipTiers\Pages\EditMembershipTier;
use App\Filament\Resources\Minutes\Pages\CreateMinute;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 334 — Ben: "When you edit, like a tier or something, it stays on the edit page. There should be a link to view
 * all, and it should be on all pages." One concern ({@see ReturnsToList}) on every create, edit and view page: saving
 * returns to the list (the hubs with relation managers also get *Guardar y seguir editando*), and a *← {plural}* header
 * action goes back to the list, first, with Filament's unsaved-changes alert guarding a dirty form.
 */
class ReturnsToListTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        app(ActiveScope::class)->setLocation($this->location->id);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $owner->locations()->sync([$this->location->id]);
        $this->actingAs($owner);
    }

    // --- 1–3. Saving and creating ------------------------------------------------------------------------------------------------

    public function test_saving_an_edit_returns_to_the_list_with_the_saved_notification(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $user = User::factory()->create();
        $user->assignRole(Role::STAFF->value);
        $user->locations()->sync([$this->location->id]);

        foreach ([
            [EditMembershipTier::class, MembershipTier::factory()->create(['organisation_id' => $this->org->id]), MembershipTierResource::class],
            [EditBatch::class, Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id]), BatchResource::class],
            [EditArticle::class, Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->location->id]), ArticleResource::class],
            [EditUser::class, $user, UserResource::class],
            [EditLocation::class, $this->location, LocationResource::class],
        ] as [$page, $record, $resource]) {
            Livewire::test($page, ['record' => $record->getRouteKey()])->call('save')
                ->assertHasNoFormErrors()->assertNotified()->assertRedirect($resource::getUrl('index'));
        }
    }

    public function test_creating_returns_to_the_list_and_create_another_stays_on_a_blank_form(): void
    {
        Livewire::test(CreateMembershipTier::class)->fillForm(['name' => 'Locals', 'default_fee_eur' => '20', 'default_period' => MembershipPeriod::cases()[0]->value])->call('create')
            ->assertHasNoFormErrors()->assertRedirect(MembershipTierResource::getUrl('index'));

        Livewire::test(CreateMembershipTier::class)->fillForm(['name' => 'Visitantes', 'default_fee_eur' => '10', 'default_period' => MembershipPeriod::cases()[0]->value])->call('createAnother')
            ->assertHasNoFormErrors()->assertNoRedirect()->assertFormSet(['name' => null]);
    }

    public function test_the_member_hub_saves_to_the_list_or_keeps_editing(): void
    {
        Settings::set('avalador_policy', 'not_required'); // the form's own rule, not this prompt's
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])->call('save')
            ->assertHasNoFormErrors()->assertRedirect(MemberResource::getUrl('index'));

        Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
            ->assertSee(__('Guardar y seguir editando'))
            ->call('saveAndKeepEditing')->assertHasNoFormErrors()->assertNotified()->assertNoRedirect();

        // A page without relation managers has the one save only.
        Livewire::test(EditMembershipTier::class, ['record' => MembershipTier::factory()->create(['organisation_id' => $this->org->id])->getRouteKey()])
            ->assertDontSee(__('Guardar y seguir editando'));
    }

    // --- 4–5. The way back -------------------------------------------------------------------------------------------------------------

    public function test_record_pages_show_a_back_link_to_their_list_first(): void
    {
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);

        foreach ([
            [CreateMembershipTier::class, [], MembershipTierResource::class],
            [EditMembershipTier::class, ['record' => $tier->getRouteKey()], MembershipTierResource::class],
            [EditMember::class, ['record' => $member->getRouteKey()], MemberResource::class],
            [ViewMember::class, ['record' => $member->getRouteKey()], MemberResource::class],
        ] as [$page, $params, $resource]) {
            $test = Livewire::test($page, $params)
                ->assertActionExists('backToList', fn ($a): bool => $a->getLabel() === $resource::getTitleCasePluralModelLabel() && $a->getIcon() !== null)
                ->assertActionHasUrl('backToList', $resource::getUrl('index'));
            $first = $test->instance()->getCachedHeaderActions()[0] ?? null;
            $this->assertSame('backToList', $first?->getName(), "{$page}: the way back is not first");
            $this->assertStringContainsString('min-h-11', (string) $test->html(), "{$page}: the way back is under the 44 px floor");
        }

        // A dirty create or edit form asks before leaving: Filament's unsaved-changes alert, on for the panel.
        $this->assertTrue(Filament::getPanel('admin')->hasUnsavedChangesAlerts());
    }

    public function test_the_batches_list_keeps_its_filter_through_an_edit_and_a_save(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id]);

        Livewire::test(ListBatches::class)->filterTable('stock', 'all');
        Livewire::test(EditBatch::class, ['record' => $batch->getRouteKey()])->call('save')->assertRedirect(BatchResource::getUrl('index'));

        Livewire::test(ListBatches::class)->assertTableFilterExists('stock')
            ->assertSet('tableFilters.stock.value', 'all');
    }

    // --- 6. Deliberate redirects kept ----------------------------------------------------------------------------------------------------

    public function test_the_deliberate_redirects_are_kept(): void
    {
        // 320 — the strain lands on the list with the «Crear lote» hand-off in its notification.
        Livewire::test(CreateGenetic::class)->fillForm(['name' => 'Gelato'])->call('create')
            ->assertHasNoFormErrors()->assertRedirect(GeneticResource::getUrl('index'));
        $notification = collect(session('filament.notifications', []))->last();
        $this->assertStringContainsString(BatchResource::getUrl('create'), json_encode($notification, JSON_UNESCAPED_SLASHES) ?: '');

        // An acta opens on its own page to be completed and signed.
        $this->assertStringContainsString("getUrl('view'", (string) file_get_contents((new \ReflectionClass(CreateMinute::class))->getFileName()));
    }

    // --- 7. The guard -----------------------------------------------------------------------------------------------------------------------

    public function test_every_create_edit_and_view_page_uses_the_concern(): void
    {
        $pages = glob(app_path('Filament/Resources/*/Pages/*.php')) ?: [];
        $record = array_values(array_filter($pages, fn (string $f): bool => (bool) preg_match('/\/(Create|Edit|View)[A-Z]\w*\.php$/', $f)));
        $this->assertGreaterThan(40, count($record));

        foreach ($record as $file) {
            $class = 'App\\Filament\\Resources\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path('Filament/Resources/'))));
            $this->assertTrue(self::usesTheConcern($class), "{$class} does not return to its list");
        }

        $this->assertFalse(self::usesTheConcern(PlantedEditPageWithoutTheConcern::class), 'the guard missed a planted page');
    }

    private static function usesTheConcern(string $class): bool
    {
        return in_array(ReturnsToList::class, class_uses_recursive($class), true);
    }
}

/** A page someone forgot: an edit page without the shared concern. */
class PlantedEditPageWithoutTheConcern extends EditRecord
{
    protected static string $resource = MembershipTierResource::class;
}
