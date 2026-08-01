<?php

namespace Tests\Feature\Members;

use App\Actions\Dispensing\CommitDispensation;
use App\Actions\Members\ImportMembers;
use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\ConsentRecord;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\MemberNumber;
use App\Support\MembersRegister;
use App\Support\Settings;
use App\Support\StockCeiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 131 — the member CSV import carries the paper libro de socios's facts (the real join date and the
 * member's own number), records the consent the member signed on paper (never a fabricated one), enrols a
 * membership so the imported member is actually servable, and lets the preview show the stock-ceiling
 * consequence before anyone commits. Every new column is optional, so an original-columns CSV is unchanged.
 */
class MemberImportPaperRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'first_name,last_name,email,phone,date_of_birth,document_type,document_number,declared_monthly_g,member_no,joined_at,left_at,status,location,tier,membership_start,consent_date,consent_text_version';

    private const COLUMNS = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'document_type', 'document_number', 'declared_monthly_g', 'member_no', 'joined_at', 'left_at', 'status', 'location', 'tier', 'membership_start', 'consent_date', 'consent_text_version'];

    private Organisation $org;

    private Location $location;

    private MembershipTier $tier;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro']);
        $this->tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id, 'name' => 'General']);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $cols */
    private function row(array $cols): string
    {
        return implode(',', array_map(fn (string $k): string => (string) ($cols[$k] ?? ''), self::COLUMNS));
    }

    /** @param list<string> $rows */
    private function csv(array $rows, string $header = self::HEADER): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp_').'.csv';
        file_put_contents($path, implode("\n", array_merge([$header], $rows)));
        $this->paths[] = $path;

        return $path;
    }

    private function adult(): string
    {
        return now()->subYears(35)->toDateString();
    }

    // 1 — the join date and member number come from the CSV, and the libro de socios prints them.
    public function test_it_keeps_the_paper_join_date_and_member_number_and_the_register_prints_them(): void
    {
        $path = $this->csv([
            $this->row(['first_name' => 'Marta', 'last_name' => 'Díaz', 'date_of_birth' => $this->adult(),
                'member_no' => 'M-00042', 'joined_at' => '2019-03-15', 'status' => 'ACTIVE',
                'location' => 'Sede Centro', 'tier' => 'General', 'membership_start' => '2019-03-15']),
        ]);

        (new ImportMembers)->import($path);

        $member = Member::query()->where('member_no', 'M-00042')->first();
        $this->assertNotNull($member);
        $this->assertSame('2019-03-15', $member->joined_at->toDateString());

        $register = collect(MembersRegister::asAt($this->org->id, now()->toDateString()))->firstWhere('member_no', 'M-00042');
        $this->assertNotNull($register);
        $this->assertSame('M-00042', $register['member_no']);
        $this->assertSame('2019-03-15', $register['alta']); // alta ← joined_at, not the import date
    }

    // 2 — the org counter ends above the highest imported number, and the next enrolment does not collide.
    public function test_it_advances_the_number_sequence_above_the_highest_imported(): void
    {
        $path = $this->csv([
            $this->row(['first_name' => 'Ana', 'last_name' => 'Uno', 'date_of_birth' => $this->adult(), 'member_no' => 'M-00040']),
            $this->row(['first_name' => 'Beto', 'last_name' => 'Dos', 'date_of_birth' => $this->adult(), 'member_no' => 'M-00042']),
        ]);

        (new ImportMembers)->import($path);

        // Never left below an imported number (42 is the highest).
        $this->assertGreaterThanOrEqual(42, (int) $this->org->fresh()->member_no_sequence);

        // The next enrolment allocates a fresh number that collides with nobody (it is free, above the imports).
        $next = MemberNumber::next($this->org->id);
        $this->assertSame('M-00043', $next);
        $this->assertSame(0, Member::query()->where('member_no', $next)->count()); // free — no imported member holds it
    }

    // 3 — a clashing member number is a PREVIEW error, not a mid-import failure.
    public function test_a_member_number_clash_is_a_preview_error_not_an_import_failure(): void
    {
        // (a) the same number twice in the file — both rows error, nothing is created.
        $duplicated = $this->csv([
            $this->row(['first_name' => 'A', 'last_name' => 'X', 'date_of_birth' => $this->adult(), 'member_no' => 'M-00050']),
            $this->row(['first_name' => 'B', 'last_name' => 'Y', 'date_of_birth' => $this->adult(), 'member_no' => 'M-00050']),
        ]);
        $preview = (new ImportMembers)->preview($duplicated);
        $this->assertSame(0, $preview['created']);
        $this->assertCount(2, $preview['errors']);

        // (b) a number already held by another member — a preview error, and import() creates nothing and
        // does NOT throw a unique-index violation halfway through.
        Member::factory()->create(['organisation_id' => $this->org->id, 'member_no' => 'M-00099']);
        $clashing = $this->csv([
            $this->row(['first_name' => 'C', 'last_name' => 'Z', 'date_of_birth' => $this->adult(), 'member_no' => 'M-00099']),
        ]);
        $preview = (new ImportMembers)->preview($clashing);
        $this->assertSame(0, $preview['created']);
        $this->assertNotEmpty($preview['errors']);

        $before = Member::query()->count();
        $result = (new ImportMembers)->import($clashing);
        $this->assertSame(0, $result['created']);
        $this->assertSame($before, Member::query()->count());
    }

    // 4 — consent is recorded from the CSV, never fabricated; a row without is imported and flagged pending.
    public function test_consent_is_recorded_from_the_csv_and_never_defaulted(): void
    {
        $path = $this->csv([
            $this->row(['first_name' => 'Con', 'last_name' => 'Sent', 'date_of_birth' => $this->adult(),
                'consent_date' => '2022-05-01', 'consent_text_version' => 'paper-2022']),
            $this->row(['first_name' => 'Sin', 'last_name' => 'Firma', 'date_of_birth' => $this->adult()]),
        ]);

        $preview = (new ImportMembers)->preview($path);
        $this->assertSame(2, $preview['created']);
        $this->assertSame(1, $preview['consent_pending']);

        (new ImportMembers)->import($path);

        $withConsent = Member::query()->where('last_name', 'Sent')->firstOrFail();
        $this->assertTrue($withConsent->hasConsent());
        $consent = $withConsent->consents()->firstOrFail();
        $this->assertSame('paper-2022', $consent->consent_text_version);
        $this->assertSame('2022-05-01', $consent->granted_at->toDateString());

        $withoutConsent = Member::query()->where('last_name', 'Firma')->firstOrFail();
        $this->assertFalse($withoutConsent->hasConsent()); // visibly consent-pending

        // No row EVER silently gets the current digital version.
        $currentDigital = (string) Settings::get('consent_text_version', '1.0');
        $this->assertSame(0, ConsentRecord::query()->where('consent_text_version', $currentDigital)->count());
    }

    // 4b — half the consent pair (a date but no version) is refused, not fabricated.
    public function test_a_partial_consent_pair_is_an_error_not_a_fabricated_version(): void
    {
        $path = $this->csv([
            $this->row(['first_name' => 'Media', 'last_name' => 'Firma', 'date_of_birth' => $this->adult(), 'consent_date' => '2022-05-01']),
        ]);

        $preview = (new ImportMembers)->preview($path);
        $this->assertSame(0, $preview['created']);
        $this->assertNotEmpty($preview['errors']);
    }

    // 5 — the end-to-end test that says the migration actually worked: an imported member is servable NOW.
    public function test_an_imported_member_with_a_membership_can_be_dispensed_to_immediately(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        GeneticPrice::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true,
        ]);
        $batch = Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id,
            'location_id' => $this->location->id, 'remaining_cg' => 100000, 'status' => BatchStatus::OPEN,
        ]);

        $path = $this->csv([
            $this->row(['first_name' => 'Servi', 'last_name' => 'Ble', 'date_of_birth' => $this->adult(),
                'member_no' => 'M-00007', 'joined_at' => '2020-01-01', 'status' => 'ACTIVE',
                'location' => 'Sede Centro', 'tier' => 'General', 'membership_start' => '2020-01-01']),
        ]);
        (new ImportMembers)->import($path);

        $member = Member::query()->where('member_no', 'M-00007')->firstOrFail();
        $this->assertTrue($member->hasActiveMembership());

        // No carencia block, no "sin membresía activa en esta sede" refusal — it just commits.
        $dispensation = (new CommitDispensation)->handle(
            $member,
            $this->location,
            [['genetic_id' => $genetic->id, 'batch_id' => $batch->id, 'grams_cg' => 100]],
        );

        $this->assertNotNull($dispensation->id);
        $this->assertSame(1000, $dispensation->total_cents->cents); // 1.00 g × €10,00/g
    }

    // 6 — importing members active raises the sede ceiling by exactly the expected amount; inactive does not.
    public function test_only_active_imported_members_raise_the_stock_ceiling(): void
    {
        $rows = [];
        foreach (range(1, 3) as $i) {
            $rows[] = $this->row(['first_name' => "Activo{$i}", 'last_name' => 'Uno', 'date_of_birth' => $this->adult(),
                'status' => 'ACTIVE', 'location' => 'Sede Centro', 'tier' => 'General']);
        }
        foreach (range(1, 2) as $i) {
            $rows[] = $this->row(['first_name' => "Baja{$i}", 'last_name' => 'Dos', 'date_of_birth' => $this->adult(),
                'status' => 'INACTIVE', 'location' => 'Sede Centro', 'tier' => 'General']);
        }

        (new ImportMembers)->import($this->csv($rows));

        $ceiling = StockCeiling::forLocation($this->location->fresh());
        $this->assertSame(3, $ceiling['active_members']); // only the 3 active, not all 5
        $this->assertSame(3 * $ceiling['daily_limit_cg'] * $ceiling['ceiling_days'], $ceiling['ceiling_cg']);
    }

    // 7 — the preview reports the resulting ceiling and writes nothing.
    public function test_the_preview_reports_the_resulting_ceiling_and_writes_nothing(): void
    {
        $rows = [
            $this->row(['first_name' => 'Prev', 'last_name' => 'Uno', 'date_of_birth' => $this->adult(),
                'status' => 'ACTIVE', 'location' => 'Sede Centro', 'tier' => 'General']),
            $this->row(['first_name' => 'Prev', 'last_name' => 'Dos', 'date_of_birth' => $this->adult(),
                'status' => 'ACTIVE', 'location' => 'Sede Centro', 'tier' => 'General']),
        ];
        $path = $this->csv($rows);

        $membersBefore = Member::query()->count();
        $preview = (new ImportMembers)->preview($path);

        $this->assertSame($membersBefore, Member::query()->count()); // nothing written
        $this->assertSame(0, Membership::query()->count());

        $this->assertArrayHasKey($this->location->id, $preview['ceilings']);
        $ceiling = $preview['ceilings'][$this->location->id];
        $this->assertSame(2, $ceiling['added_active']);
        $this->assertSame(2, $ceiling['active_members']); // 0 current + 2 imported

        $base = StockCeiling::forLocation($this->location);
        $this->assertSame(2 * $base['daily_limit_cg'] * $base['ceiling_days'], $ceiling['ceiling_cg']);
    }

    // 8 — an existing CSV of only the original columns imports exactly as it does today.
    public function test_an_original_columns_csv_imports_exactly_as_before(): void
    {
        $header = 'first_name,last_name,email,phone,date_of_birth,document_type,document_number,declared_monthly_g';
        $path = $this->csv([
            "Juan,Pérez,juan@example.com,600000001,{$this->adult()},DNI,11111111A,30",
        ], $header);

        $result = (new ImportMembers)->import($path);
        $this->assertSame(1, $result['created']);

        $member = Member::query()->where('last_name', 'Pérez')->firstOrFail();
        $this->assertSame(now()->toDateString(), $member->joined_at->toDateString()); // join date defaults to today
        $this->assertNotEmpty($member->member_no);                                    // a number is generated
        $this->assertFalse($member->hasActiveMembership());                           // no membership (as today)
        $this->assertFalse($member->hasConsent());                                    // consent-pending (as today)
    }
}
