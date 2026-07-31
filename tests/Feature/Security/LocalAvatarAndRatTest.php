<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\InitialsAvatarProvider;
use App\ViewModels\Rat;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 61 — the admin panel must render with NO outbound request to a third party. Filament's default
 * avatar provider called https://ui-avatars.com with the staff name on every page load (undeclared
 * personal-data outbound + a broken image on locked-down networks). It's replaced with a local
 * data-URI provider, and the RAT is reconciled to the one processor that genuinely remains (Resend).
 */
class LocalAvatarAndRatTest extends TestCase
{
    use RefreshDatabase;

    private function decodeSvg(string $dataUri): string
    {
        return (string) base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')));
    }

    public function test_the_avatar_provider_returns_a_local_data_uri_with_no_external_host(): void
    {
        $avatar = (new InitialsAvatarProvider)->get(User::factory()->create(['name' => 'Jane Doe']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringNotContainsString('http', $avatar); // nothing leaves the server
        $this->assertStringContainsString('JD', $this->decodeSvg($avatar));
    }

    public function test_the_avatar_handles_non_ascii_spanish_names(): void
    {
        $avatar = (new InitialsAvatarProvider)->get(User::factory()->create(['name' => 'Ángel Ñoño']));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $this->assertStringContainsString('ÁÑ', $this->decodeSvg($avatar)); // accented initials, uppercased
    }

    public function test_the_admin_panel_is_configured_to_use_the_local_provider(): void
    {
        $this->assertSame(InitialsAvatarProvider::class, Filament::getPanel('admin')->getDefaultAvatarProvider());
    }

    public function test_an_admin_page_renders_with_no_ui_avatars_request(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $location = Location::factory()->create(['organisation_id' => $org->id]);
        $user = User::factory()->create();
        $user->assignRole(Role::OWNER->value);
        $user->locations()->sync([$location->id]);
        app(ActiveScope::class)->setLocation($location->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user)
            ->get(MemberResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('ui-avatars.com');
    }

    public function test_the_rat_declares_resend_and_never_ui_avatars(): void
    {
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        $blob = (string) json_encode((new Rat)->activities());

        $this->assertStringContainsString('Resend', $blob);       // the real remaining outbound processor
        $this->assertStringNotContainsString('ui-avatars', $blob); // never a declared recipient, now truly gone
    }
}
