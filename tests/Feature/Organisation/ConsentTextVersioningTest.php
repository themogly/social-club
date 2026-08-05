<?php

namespace Tests\Feature\Organisation;

use App\Actions\Organisation\UpdateConsentText;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Exceptions\ConsentVersionRequiredException;
use App\Filament\Pages\ManageConsentText;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\ConsentText;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 159 — editing a consent text must not silently rewrite what already-consented members agreed to.
 * A changed text under the same version is refused; a version bump archives the outgoing text so an old
 * record still resolves to exactly what its member read (the reproducibility prompt 153 promised).
 */
class ConsentTextVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);

        // Baseline: version 1.0 with a known text in both languages.
        Settings::set('consent_privacy_text', ['es' => 'Aviso A', 'en' => 'Notice A'], SettingType::JSON);
        Settings::set('consent_statutes_text', ['es' => 'Estatutos A', 'en' => 'Statutes A'], SettingType::JSON);
        Settings::set('consent_text_version', '1.0', SettingType::STRING);
    }

    public function test_editing_a_consent_text_without_a_version_bump_is_refused(): void
    {
        $this->expectException(ConsentVersionRequiredException::class);

        (new UpdateConsentText)->handle(
            ['es' => 'Aviso B', 'en' => 'Notice A'],   // privacy CHANGED
            ['es' => 'Estatutos A', 'en' => 'Statutes A'],
            '1.0',                                       // same version — refused
        );
    }

    public function test_a_version_bump_archives_the_old_text_so_old_records_resolve(): void
    {
        (new UpdateConsentText)->handle(
            ['es' => 'Aviso B', 'en' => 'Notice B'],
            ['es' => 'Estatutos B', 'en' => 'Statutes B'],
            '1.1',
        );

        // The current version reads the new text; a member recorded under 1.0 still resolves to 1.0's text.
        $this->assertSame('1.1', ConsentText::version());
        $this->assertSame('Aviso B', ConsentText::privacyForVersion('1.1', 'es'));
        $this->assertSame('Aviso A', ConsentText::privacyForVersion('1.0', 'es'));
        $this->assertSame('Estatutos A', ConsentText::statutesForVersion('1.0', 'es'));
        $this->assertSame('Notice A', ConsentText::privacyForVersion('1.0', 'en'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'consent_text.updated']);
    }

    public function test_a_version_relabel_without_a_text_change_is_allowed(): void
    {
        (new UpdateConsentText)->handle(
            ['es' => 'Aviso A', 'en' => 'Notice A'],     // unchanged
            ['es' => 'Estatutos A', 'en' => 'Statutes A'],
            '2.0',
        );

        $this->assertSame('2.0', ConsentText::version());
        $this->assertSame('Aviso A', ConsentText::privacyForVersion('2.0', 'es'));
    }

    public function test_the_consent_screen_is_gated_on_settings_consent(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);
        $this->assertTrue(ManageConsentText::canAccess());

        $manager = User::factory()->create();
        $manager->assignRole(Role::MANAGER->value);
        $this->actingAs($manager);
        $this->assertFalse(ManageConsentText::canAccess());
    }
}
