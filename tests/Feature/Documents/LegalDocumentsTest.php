<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateMinute;
use App\Actions\Documents\GenerateMemberDocument;
use App\Actions\Documents\SignMinute;
use App\Actions\Members\IssueDocumentUrl;
use App\Enums\DispensationStatus;
use App\Enums\MemberDocumentType;
use App\Enums\MinuteBook;
use App\Enums\Role;
use App\Models\ConsentRecord;
use App\Models\Dispensation;
use App\Models\DocumentAccessLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\MembersRegister;
use App\Support\Period;
use App\Support\Spreadsheet\AccountingExport;
use App\ViewModels\Reports\FinancialReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
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
    }

    private function user(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    public function test_the_register_as_at_a_date_keeps_leavers_and_excludes_later_joiners(): void
    {
        $early = Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-001', 'joined_at' => '2026-01-01']);
        $leaver = Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-002', 'joined_at' => '2026-02-01', 'left_at' => '2026-04-01']);
        $later = Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-003', 'joined_at' => '2026-09-01']);

        $rows = collect(MembersRegister::asAt($this->org->id, '2026-06-01'));
        $numbers = $rows->pluck('member_no')->all();

        $this->assertContains($early->member_no, $numbers);
        $this->assertContains($leaver->member_no, $numbers);       // leavers stay in the register…
        $this->assertNotContains($later->member_no, $numbers);     // …but later joiners are excluded
        $this->assertSame('2026-04-01', $rows->firstWhere('member_no', 'M-002')['baja']);
    }

    public function test_a_signed_minute_is_immutable_and_a_correction_is_a_linked_successor(): void
    {
        $owner = $this->user(Role::OWNER);
        $minute = (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, ['held_on' => '2026-03-01', 'body' => 'Original'], $owner);
        (new SignMinute)->handle($minute, $owner);

        try {
            $minute->update(['body' => 'Tampered']);
            $this->fail('A signed minute must not be editable.');
        } catch (RuntimeException) {
        }
        try {
            $minute->fresh()->delete();
            $this->fail('A signed minute must not be deletable.');
        } catch (RuntimeException) {
        }

        $correction = (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, [
            'held_on' => '2026-03-08', 'body' => 'Corrección', 'supersedes_id' => $minute->id,
        ], $owner);
        $this->assertSame($minute->id, $correction->supersedes_id);
    }

    public function test_minute_numbering_is_sequential_per_book_with_no_gaps(): void
    {
        $owner = $this->user(Role::OWNER);
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, [], $owner)->number;
        }
        $board = (new CreateMinute)->handle($this->org, MinuteBook::BOARD, [], $owner);

        $this->assertSame([1, 2, 3], $numbers);   // no gaps, sequential
        $this->assertSame(1, $board->number);     // independent per book
    }

    public function test_quorum_is_computed_against_members_active_at_the_meeting_date(): void
    {
        $owner = $this->user(Role::OWNER);
        // 4 members active on 2026-03-01 (default quorum fraction = 50% → 2 required).
        for ($i = 0; $i < 4; $i++) {
            Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => '2026-01-01']);
        }
        Member::factory()->create(['organisation_id' => $this->org->id, 'joined_at' => '2026-05-01']); // joined later — excluded

        $minute = (new CreateMinute)->handle($this->org, MinuteBook::ASSEMBLY, ['held_on' => '2026-03-01', 'attendees' => ['a', 'b', 'c']], $owner);

        $this->assertSame(2, $minute->quorum_required); // ceil(4 × 0.5)
        $this->assertSame(3, $minute->quorum_present);
    }

    public function test_a_generated_document_freezes_its_data_and_is_unchanged_by_later_edits(): void
    {
        Storage::fake('documents');
        $owner = $this->user(Role::OWNER);
        $member = Member::factory()->create(['organisation_id' => $this->org->id, 'first_name' => 'Ana', 'last_name' => 'García']);
        ConsentRecord::factory()->create(['member_id' => $member->id, 'consent_text_version' => '1.0', 'granted_at' => now()]);

        $v1 = (new GenerateMemberDocument)->handle($member, MemberDocumentType::REGISTRATION_FORM, $owner);
        $this->assertSame('Ana García', $v1->snapshot['nombre']);
        $this->assertSame('1.0', $v1->snapshot['consent_version']);
        $this->assertSame(1, $v1->version);
        Storage::disk('documents')->assertExists($v1->path);

        // Edit the member and regenerate — a NEW version; the first document is untouched.
        $member->update(['first_name' => 'Ana', 'last_name' => 'López']);
        $v2 = (new GenerateMemberDocument)->handle($member->fresh(), MemberDocumentType::REGISTRATION_FORM, $owner);

        $this->assertSame('Ana López', $v2->snapshot['nombre']);
        $this->assertSame(2, $v2->version);
        $this->assertSame('Ana García', $v1->fresh()->snapshot['nombre']); // frozen artifact unchanged
        $this->assertSame(2, MemberDocument::query()->where('member_id', $member->id)->count());
    }

    public function test_document_access_is_permissioned_signed_and_logged(): void
    {
        $owner = $this->user(Role::OWNER); // has member.documents.view
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        $document = MemberDocument::factory()->create(['member_id' => $member->id]);

        $url = (new IssueDocumentUrl)->handle($document, $owner);

        $this->assertStringContainsString('signature=', $url); // short-lived signed URL, unguessable ULID path
        $this->assertDatabaseHas('document_access_logs', ['member_document_id' => $document->id, 'actor_id' => $owner->id]);
        $this->assertSame(1, DocumentAccessLog::query()->count());
    }

    public function test_the_accounting_export_totals_reconcile_with_the_financial_report(): void
    {
        $member = Member::factory()->create(['organisation_id' => $this->org->id]);
        Dispensation::factory()->create([
            'organisation_id' => $this->org->id, 'location_id' => $this->location->id, 'member_id' => $member->id,
            'status' => DispensationStatus::COMPLETED, 'total_cents' => 8500, 'cash_cents' => 8500, 'wallet_cents' => 0,
            'dispensed_at' => now(),
        ]);

        // The accounting export is a Spanish-format document (decimal comma, no symbol).
        app()->setLocale('es');
        $period = Period::thisMonth();
        $export = AccountingExport::forPeriod($this->org->id, [$this->location->id], $period);
        $report = new FinancialReport($this->org->id, [$this->location->id], $period);

        $reportIncome = (int) ($this->reportTotal($report, 'methods', 'importe'));
        $this->assertSame($reportIncome, $export->totals()['income_cents']);
        $this->assertSame(8500, $export->totals()['income_cents']);
        $this->assertStringContainsString('85,00', $export->csv()); // surplus/income rendered
    }

    private function reportTotal(FinancialReport $report, string $key, string $col): int
    {
        foreach ($report->tables() as $t) {
            if ($t->key === $key) {
                return (int) ($t->totals[$col] ?? 0);
            }
        }

        return 0;
    }
}
