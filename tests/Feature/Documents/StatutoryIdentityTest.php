<?php

namespace Tests\Feature\Documents;

use App\Enums\Role;
use App\Filament\Pages\LibroSocios;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\OrganisationIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 115 — statutory documents carry the organisation's LEGAL identity (name, CIF/NIF, address, logo)
 * through one resolver, and refuse to generate without a legal name rather than printing the trading name as
 * if it were the legal one.
 */
class StatutoryIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function scopeTo(Organisation $org): void
    {
        app(ActiveScope::class)->setOrganisation($org->id);
    }

    public function test_the_printed_name_is_the_legal_name_when_set(): void
    {
        $org = Organisation::factory()->create([
            'name' => 'CSC platform', 'legal_name' => 'Asociación Cannábica Legal', 'tax_id' => 'G12345678',
            'address' => 'Calle Uno 1',
        ]);
        $this->scopeTo($org);

        $identity = OrganisationIdentity::current();
        $this->assertSame('Asociación Cannábica Legal', $identity['display_name']);
        $this->assertSame('G12345678', $identity['tax_id']);
        $this->assertSame('Calle Uno 1', $identity['address']);
        $this->assertTrue(OrganisationIdentity::hasLegalName());
    }

    public function test_the_printed_name_falls_back_to_the_trading_name_without_a_legal_name(): void
    {
        $org = Organisation::factory()->create(['name' => 'Trading Name', 'legal_name' => null]);
        $this->scopeTo($org);

        $identity = OrganisationIdentity::current();
        $this->assertSame('Trading Name', $identity['display_name']);
        $this->assertNull($identity['legal_name']);
        $this->assertFalse(OrganisationIdentity::hasLegalName());
    }

    public function test_the_logo_is_embedded_as_a_data_uri_when_present(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('org/logo.png', 'FAKE-PNG-BYTES');
        $org = Organisation::factory()->create(['legal_name' => 'X', 'logo_path' => 'org/logo.png']);
        $this->scopeTo($org);

        $this->assertStringStartsWith('data:', (string) OrganisationIdentity::current()['logo']);
    }

    public function test_a_missing_logo_degrades_to_null_without_throwing(): void
    {
        Storage::fake('public');
        $org = Organisation::factory()->create(['legal_name' => 'X', 'logo_path' => 'does/not/exist.png']);
        $this->scopeTo($org);

        $this->assertNull(OrganisationIdentity::current()['logo']);
    }

    public function test_a_statutory_document_prints_the_legal_identity_in_its_header(): void
    {
        $org = Organisation::factory()->create([
            'legal_name' => 'Asociación Registro', 'tax_id' => 'G77777777', 'address' => 'Plaza Mayor 3',
        ]);
        $this->scopeTo($org);

        $html = view('documents.register', [
            'rows' => [], 'count' => 0, 'asAt' => now()->toDateString(),
            'sedeLabel' => 'Todas las sedes', 'orgName' => 'Asociación Registro',
            'identity' => OrganisationIdentity::current(), 'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Asociación Registro', $html);
        $this->assertStringContainsString('G77777777', $html);
        $this->assertStringContainsString('Plaza Mayor 3', $html);
    }

    public function test_the_libro_de_socios_refuses_to_export_without_a_legal_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['legal_name' => null]);
        $this->scopeTo($org);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);

        // No legal name → the export is refused (no download) and the operator is told why.
        Livewire::test(LibroSocios::class)
            ->call('exportPdf')
            ->assertNotified(__('Falta el nombre legal de la asociación'));
    }

    public function test_the_libro_de_socios_exports_once_a_legal_name_is_set(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create(['legal_name' => 'Asociación Con Nombre', 'tax_id' => 'G11111111']);
        $this->scopeTo($org);
        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->actingAs($owner);

        Livewire::test(LibroSocios::class)
            ->call('exportPdf')
            ->assertFileDownloaded();
    }
}
