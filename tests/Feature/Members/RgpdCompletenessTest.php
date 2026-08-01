<?php

namespace Tests\Feature\Members;

use App\Actions\Members\AnonymiseMember;
use App\Actions\RecordAuditLog;
use App\Enums\MemberDocumentType;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\DocumentVault;
use App\ViewModels\Rat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Prompt 76 — erasure must actually erase (health documents, the health flag, document snapshots), the
 * audit log must not undo it (the DNI index + health flag redacted), and the RAT must describe reality.
 */
class RgpdCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
    }

    private function member(): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'Real',
            'is_therapeutic' => true, 'medical_cert_path' => 'member-medical-certs/cert.pdf',
            'photo_path' => 'photos/p.jpg', 'document_scan_path' => 'scans/s.jpg',
        ]);
        foreach (['member-medical-certs/cert.pdf', 'photos/p.jpg', 'scans/s.jpg'] as $path) {
            DocumentVault::put($path, 'sensitive');
        }

        return $member;
    }

    public function test_erasure_removes_every_member_file_and_the_health_data(): void
    {
        $member = $this->member();

        (new AnonymiseMember)->handle($member);
        $member->refresh();

        Storage::disk('documents')->assertMissing('member-medical-certs/cert.pdf');
        Storage::disk('documents')->assertMissing('photos/p.jpg');
        Storage::disk('documents')->assertMissing('scans/s.jpg');
        $this->assertNull($member->medical_cert_path);
        $this->assertFalse($member->is_therapeutic); // health flag no longer identifies the member
        $this->assertNull($member->email);
    }

    public function test_no_surviving_document_snapshot_contains_the_name_or_dni(): void
    {
        $member = $this->member();
        // A DECLARATION (retention obligation) with the real name + DNI in its snapshot, and its PDF.
        $declaration = MemberDocument::create([
            'member_id' => $member->id, 'type' => MemberDocumentType::DECLARATION, 'version' => 1,
            'path' => 'generated/decl.pdf', 'snapshot' => ['nombre' => 'Ana Real', 'documento' => '12345678Z', 'declared_monthly_cg' => 5000],
        ]);
        DocumentVault::put('generated/decl.pdf', 'pdf');
        // A CONSENT (no retention obligation) — destroyed outright.
        $consent = MemberDocument::create([
            'member_id' => $member->id, 'type' => MemberDocumentType::CONSENT, 'version' => 1,
            'path' => 'generated/consent.pdf', 'snapshot' => ['nombre' => 'Ana Real'],
        ]);
        DocumentVault::put('generated/consent.pdf', 'pdf');

        (new AnonymiseMember)->handle($member);

        // Retention doc retained as REDACTED metadata: row kept, name/DNI gone, identifying PDF gone.
        $declaration->refresh();
        $this->assertSame('[borrado]', $declaration->snapshot['nombre']);
        $this->assertSame('[borrado]', $declaration->snapshot['documento']);
        $this->assertSame(5000, $declaration->snapshot['declared_monthly_cg']); // the retained, non-identifying figure
        $this->assertNull($declaration->path);
        Storage::disk('documents')->assertMissing('generated/decl.pdf');

        // No-retention doc destroyed outright.
        $this->assertNull(MemberDocument::query()->find($consent->id));

        // Assert against ACTUAL stored content: no snapshot anywhere holds the name or DNI.
        $blob = (string) json_encode(MemberDocument::query()->where('member_id', $member->id)->pluck('snapshot'));
        $this->assertStringNotContainsString('Ana Real', $blob);
        $this->assertStringNotContainsString('12345678Z', $blob);
    }

    public function test_every_member_linked_table_is_covered_by_erasure(): void
    {
        // Enumerate from the SCHEMA — a new member-linked table cannot silently reopen the erasure gap.
        // (SQLite's getTableListing prefixes the schema, e.g. "main.check_ins" — strip it.)
        $memberTables = collect(Schema::getTableListing())
            ->map(fn (string $t): string => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->filter(fn (string $t): bool => in_array('member_id', Schema::getColumnListing($t), true));

        foreach ($memberTables as $table) {
            $this->assertArrayHasKey(
                $table,
                AnonymiseMember::COVERED_MEMBER_TABLES,
                "Table [{$table}] holds a member_id but is not documented in AnonymiseMember::COVERED_MEMBER_TABLES."
            );
        }
    }

    public function test_erasure_redacts_the_dni_index_and_health_flag_from_audit_rows(): void
    {
        $member = $this->member();
        // A pre-existing member.updated audit row carrying the health flag + the unsalted DNI index.
        (new RecordAuditLog)->handle('member.updated', $member,
            ['is_therapeutic' => false, 'document_hash' => hash('sha256', '12345678Z')],
            ['is_therapeutic' => true]);

        (new AnonymiseMember)->handle($member);

        $rows = AuditLog::query()->where('auditable_type', $member->getMorphClass())->where('auditable_id', $member->id)->get();
        $blob = (string) json_encode($rows->map(fn (AuditLog $r): array => [$r->before, $r->after]));
        $this->assertStringNotContainsString(hash('sha256', '12345678Z'), $blob); // DNI index gone
        $this->assertStringContainsString('[borrado]', $blob);                    // masked in place
        // The redaction itself is audited (append, not a hole).
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.audit.redacted']);
    }

    public function test_the_audit_log_is_still_append_only_outside_a_redaction(): void
    {
        $log = AuditLog::factory()->create(['before' => ['x' => 1]]);

        $this->expectException(RuntimeException::class);
        $log->update(['before' => ['x' => 2]]); // the bypass is off by default
    }

    public function test_the_rat_declares_the_medical_certificate_store_and_the_web_push_transfer(): void
    {
        app(ActiveScope::class)->setOrganisation($this->org->id);
        app()->setLocale('es'); // assert the Spanish legal source text
        $activities = collect((new Rat)->activities());

        // RAT-03 now includes the medical certificate and is flagged Article 9 (it was false).
        $idDocs = $activities->firstWhere('ref', 'RAT-03');
        $this->assertTrue($idDocs['article_9']);
        $this->assertStringContainsString('médico', implode(' ', $idDocs['data_categories']));

        // RAT-06 now declares the browser push service as a non-EEA recipient/transfer (was undeclared).
        $comms = $activities->firstWhere('ref', 'RAT-06');
        $this->assertStringContainsString('push', mb_strtolower($comms['recipients']));
        $this->assertStringContainsString('EEE', $comms['transfers']);

        // Schema-anchored: the Art. 9 declaration is grounded in columns that really exist.
        $this->assertTrue(Schema::hasColumn('members', 'is_therapeutic'));
        $this->assertTrue(Schema::hasColumn('members', 'medical_cert_path'));
    }
}
