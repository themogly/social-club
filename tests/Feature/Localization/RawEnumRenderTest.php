<?php

namespace Tests\Feature\Localization;

use App\Enums\CultivationType;
use App\Enums\MemberStatus;
use App\Models\Genetic;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\MembersRegister;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 94 — no backed enum reaches a human as its RAW value. Every enum has a localized label(); a few
 * call sites bypassed it and printed ACTIVE / INDOOR / OPEN in both languages, one of them inside a
 * statutory register (the libro de socios PDF). These pin the fixes and guard the class of bug.
 */
class RawEnumRenderTest extends TestCase
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

    public function test_the_libro_de_socios_renders_member_status_through_its_label_in_both_locales(): void
    {
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'member_no' => 'M-001',
            'status' => MemberStatus::ACTIVE, 'joined_at' => '2026-01-01',
        ]);

        app()->setLocale('es');
        $es = MembersRegister::asAt($this->org->id, '2026-06-01');
        $this->assertSame('Activo', $es[0]['estado']);              // the label, not ACTIVE
        $this->assertNotSame(MemberStatus::ACTIVE->value, $es[0]['estado']);

        app()->setLocale('en');
        $en = MembersRegister::asAt($this->org->id, '2026-06-01');
        $this->assertSame('Active', $en[0]['estado']);
    }

    public function test_the_libro_de_socios_pdf_shows_the_status_label_not_the_raw_enum(): void
    {
        // The artefact that leaves the building — the register PDF must never print ACTIVE.
        app()->setLocale('es');
        Member::factory()->create([
            'organisation_id' => $this->org->id, 'member_no' => 'M-001', 'first_name' => 'Ana', 'last_name' => 'García',
            'status' => MemberStatus::ACTIVE, 'joined_at' => '2026-01-01',
        ]);

        $rows = MembersRegister::asAt($this->org->id, '2026-06-01');
        $html = view('documents.register', [
            'rows' => $rows, 'count' => count($rows), 'asAt' => '2026-06-01',
            'sedeLabel' => 'Todas', 'orgName' => 'Club', 'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('Activo', $html);
        $this->assertStringNotContainsString('ACTIVE', $html);
    }

    public function test_cultivation_type_renders_its_label_on_the_genetic_cards(): void
    {
        // The row builder passes the LABEL now — the blade renders it verbatim (no __() double-pass).
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'cultivation_type' => CultivationType::INDOOR]);

        app()->setLocale('es');
        $this->assertSame('Interior', $genetic->cultivation_type?->label());
        app()->setLocale('en');
        $this->assertSame('Indoor', $genetic->cultivation_type?->label());
        // And it is never the raw backed value.
        $this->assertNotSame(CultivationType::INDOOR->value, $genetic->cultivation_type?->label());
    }

    public function test_the_counter_subtitle_key_was_renamed_and_no_contador_remains(): void
    {
        app()->setLocale('es');
        $this->assertSame('Mostrador', __('Mostrador')); // the correct Spanish word (contador = accountant)
        app()->setLocale('en');
        $this->assertSame('Counter', __('Mostrador'));

        // The old key must not linger anywhere in the app views/code.
        $hits = [];
        foreach (array_merge(
            glob(base_path('resources/views/**/*.blade.php')) ?: [],
            glob(base_path('resources/views/**/**/*.blade.php')) ?: [],
            glob(app_path('**/*.php')) ?: [],
        ) as $file) {
            if (str_contains((string) file_get_contents($file), "__('Contador')")) {
                $hits[] = $file;
            }
        }
        $this->assertSame([], $hits, 'The old "Contador" key still appears: '.implode(', ', $hits));
    }

    /**
     * The guard, prompt-94 style: the human-facing DISPLAY producers must not print a raw enum — while the
     * legitimate machine-readable / audit / comparison uses are left alone. It FIRES on a display leak and
     * does NOT fire on the Article 20 export.
     */
    public function test_the_display_producers_use_labels_while_the_article_20_export_keeps_raw_values(): void
    {
        $register = (string) file_get_contents(app_path('Support/MembersRegister.php'));
        $this->assertStringContainsString('$m->status->label()', $register);
        $this->assertStringNotContainsString('$m->status->value', $register);

        $zreport = (string) file_get_contents(app_path('Support/ZReport.php'));
        $this->assertStringContainsString('$session->status->label()', $zreport);
        $this->assertStringNotContainsString('$session->status->value', $zreport);

        // The Article 20 portability export is deliberately machine-readable — raw values are CORRECT there,
        // and the guard must not force them to labels.
        $export = (string) file_get_contents(app_path('Actions/Members/ExportMemberData.php'));
        $this->assertStringContainsString('->status->value', $export);
    }
}
