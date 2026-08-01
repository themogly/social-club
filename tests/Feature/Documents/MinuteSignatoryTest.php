<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateMinute;
use App\Actions\Documents\SignMinute;
use App\Enums\MinuteBook;
use App\Enums\Role;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Filament\Resources\Minutes\Pages\ListMinutes;
use App\Models\AuditLog;
use App\Models\Minute;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 87 — an acta now records WHO signed it, not merely that it was signed. Signing is gated on
 * `minute.sign` (owner-only by default), narrower than the `minutes.manage` that drafts one; the signatory
 * is frozen with the signature (never re-signable, never editable); and an acta with no recorded signatory
 * (signed before the column existed, or its signer's account deleted) reports "no consta" — never a
 * fabricated name.
 */
class MinuteSignatoryTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    private function draft(User $author): Minute
    {
        return (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, ['held_on' => '2026-07-10', 'body' => 'Original'], $author);
    }

    public function test_signing_records_the_signatory(): void
    {
        $owner = $this->user(Role::OWNER);
        $minute = $this->draft($owner);

        (new SignMinute)->handle($minute, $owner);

        $fresh = $minute->fresh();
        $this->assertNotNull($fresh->signed_at);
        $this->assertSame($owner->id, $fresh->signed_by);
        $this->assertTrue($fresh->signedBy->is($owner));
    }

    public function test_the_audit_log_records_who_signed(): void
    {
        $owner = $this->user(Role::OWNER);
        $minute = $this->draft($owner);

        (new SignMinute)->handle($minute, $owner);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'minute.signed',
            'auditable_id' => $minute->id,
        ]);
        $log = AuditLog::query()->where('action', 'minute.signed')->firstOrFail();
        $this->assertSame($owner->id, $log->after['signed_by'] ?? null);
        $this->assertSame($owner->name, $log->after['signatory'] ?? null);
    }

    public function test_a_manager_can_draft_but_cannot_sign(): void
    {
        // MANAGER holds minutes.manage (can draft/correct) but NOT minute.sign — the signing authority.
        $manager = $this->user(Role::MANAGER);
        $this->assertTrue($manager->can('minutes.manage'));
        $this->assertFalse($manager->can('minute.sign'));

        $minute = $this->draft($manager);

        try {
            (new SignMinute)->handle($minute, $manager);
            $this->fail('A manager without minute.sign must not be able to sign an acta.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertNull($minute->fresh()->signed_at);
        $this->assertNull($minute->fresh()->signed_by);
    }

    public function test_staff_cannot_sign(): void
    {
        $owner = $this->user(Role::OWNER);
        $staff = $this->user(Role::STAFF);
        $minute = $this->draft($owner);

        $this->assertFalse($staff->can('minute.sign'));
        $this->expectException(AuthorizationException::class);
        (new SignMinute)->handle($minute, $staff);
    }

    public function test_a_signed_acta_cannot_be_re_signed_and_the_signatory_cannot_change(): void
    {
        $first = $this->user(Role::OWNER);
        $second = $this->user(Role::OWNER);
        $minute = $this->draft($first);
        (new SignMinute)->handle($minute, $first);

        // Re-signing is refused outright…
        try {
            (new SignMinute)->handle($minute->fresh(), $second);
            $this->fail('A signed acta must not be re-signable.');
        } catch (RuntimeException) {
        }

        // …and the signatory cannot be rewritten directly either — the model is immutable once signed.
        try {
            $minute->fresh()->update(['signed_by' => $second->id]);
            $this->fail('The signatory of a signed acta must be immutable.');
        } catch (RuntimeException) {
        }

        $this->assertSame($first->id, $minute->fresh()->signed_by);
    }

    public function test_the_pdf_names_the_signatory_when_recorded(): void
    {
        $owner = $this->user(Role::OWNER);
        $owner->update(['name' => 'Lucía Fernández']);
        $minute = $this->draft($owner);
        (new SignMinute)->handle($minute->fresh(), $owner);

        $html = view('documents.minute', MinuteResource::pdfData($minute->fresh()))->render();

        $this->assertStringContainsString('Lucía Fernández', $html);
    }

    public function test_a_historically_signed_acta_reports_no_consta_not_a_fabricated_name(): void
    {
        app()->setLocale('es');

        // An acta signed before signatories were recorded: signed_at present, signed_by null.
        $minute = Minute::factory()->create([
            'organisation_id' => $this->org->id,
            'book' => MinuteBook::ASSEMBLY,
            'number' => 1,
            'signed_at' => CarbonImmutable::parse('2026-01-05 10:00:00'),
            'signed_by' => null,
        ]);

        $html = view('documents.minute', MinuteResource::pdfData($minute))->render();

        $this->assertStringContainsString('No consta', $html);
        // It still shows as signed — the signature is real, only its author is unrecorded.
        $this->assertStringContainsString('Firmada el', $html);
    }

    public function test_the_sign_action_is_visible_to_a_signer_and_hidden_from_a_manager(): void
    {
        $owner = $this->user(Role::OWNER);
        $manager = $this->user(Role::MANAGER);
        $minute = $this->draft($owner);

        $this->actingAs($owner);
        Livewire::test(ListMinutes::class)->assertTableActionVisible('sign', $minute);

        $this->actingAs($manager);
        Livewire::test(ListMinutes::class)->assertTableActionHidden('sign', $minute);
    }
}
