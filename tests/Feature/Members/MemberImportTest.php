<?php

namespace Tests\Feature\Members;

use App\Actions\Members\ImportMembers;
use App\Models\Member;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);

        // An existing member the CSV will try (and fail) to duplicate.
        Member::factory()->create([
            'organisation_id' => $this->org->id,
            'first_name' => 'Ana', 'last_name' => 'Ruiz', 'date_of_birth' => '1980-02-02',
        ]);

        $adult = now()->subYears(30)->toDateString();
        $minor = now()->subYears(15)->toDateString();
        $this->csvPath = tempnam(sys_get_temp_dir(), 'members_').'.csv';
        file_put_contents($this->csvPath, implode("\n", [
            'first_name,last_name,email,phone,date_of_birth,document_type,document_number,declared_monthly_g',
            "Juan,Pérez,juan@example.com,600000001,{$adult},DNI,11111111A,30",
            "Luis,Menor,luis@example.com,600000002,{$minor},DNI,22222222B,30",   // under-age -> error
            'Ana,Ruiz,ana@example.com,600000003,1980-02-02,DNI,33333333C,30',     // duplicate -> skipped
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
        parent::tearDown();
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $before = Member::count();

        $report = (new ImportMembers)->preview($this->csvPath);

        $this->assertSame($before, Member::count());   // nothing written
        $this->assertSame(1, $report['created']);       // Juan would be created
        $this->assertSame(1, $report['skipped']);       // Ana is a duplicate
        $this->assertNotEmpty($report['errors']);       // Luis is under age
    }

    public function test_real_import_is_idempotent_on_re_run(): void
    {
        $first = (new ImportMembers)->import($this->csvPath);
        $this->assertSame(1, $first['created']);
        $afterFirst = Member::count();

        $second = (new ImportMembers)->import($this->csvPath);
        $this->assertSame(0, $second['created']);       // idempotent — all now duplicates
        $this->assertSame($afterFirst, Member::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'members.imported']);
    }
}
