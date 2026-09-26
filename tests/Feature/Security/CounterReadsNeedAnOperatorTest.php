<?php

namespace Tests\Feature\Security;

use App\Actions\Till\OpenTill;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Concerns\PostsLivewireOverHttp;
use Tests\TestCase;

/**
 * Prompt 260 — with nobody at the PIN, the counter reads nothing either (audit F4).
 *
 * 255 made "nobody identified ⇒ the counter authorises nothing" the rule for writes. Reads did not follow: a
 * signed-in tablet with no operator could call `lookupResults()` over Livewire and receive WHOLE `Member` rows —
 * DNI, date of birth, address, `is_therapeutic` (Article 9), `document_hash` — none of which the screen shows.
 * The read methods are no longer wire actions at all (the view still calls them), they return nothing with no
 * operator, and `document_hash` never serialises. Real HTTP requests to the real endpoint, as in 254.
 */
class CounterReadsNeedAnOperatorTest extends TestCase
{
    use PostsLivewireOverHttp, RefreshDatabase;

    private const PIN = '2468';

    private const DNI = '99887766Q';

    private const DOB = '1981-02-03';

    private Organisation $org;

    private Location $location;

    private User $device;

    private User $operator;

    private Member $planted;

    protected function setUp(): void
    {
        parent::setUp();
        // What a real tablet gets: production error pages. With debug on, a refused call's stack trace would quote
        // this test's own source (and its planted values) back into the response.
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);

        $this->device = $this->user(Role::OWNER, null);
        $this->operator = $this->user(Role::MANAGER, self::PIN);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $this->planted = Member::factory()->create([
            'organisation_id' => $this->org->id, 'first_name' => 'Ainhoa', 'last_name' => 'Zubizarreta',
            'member_no' => 'M-77123', 'document_number' => self::DNI, 'date_of_birth' => self::DOB,
            'is_therapeutic' => true,
        ]);

        // A signed-in tablet with its sede chosen — the state after an idle lock or a shift change.
        $this->actingAs($this->device);
        $this->post(route('counter.location'), ['location_id' => $this->location->id]);
        CounterOperator::clear();
    }

    private function user(Role $role, ?string $pin): User
    {
        $user = User::factory()->create(['pin' => $pin === null ? null : Hash::make($pin)]);
        $user->assignRole($role->value);
        $user->locations()->attach($this->location->id);

        return $user;
    }

    private function identify(): void
    {
        $this->livewirePost($this->snapshotFrom('/counter/members', 'counter.membership-counter'),
            ['operatorPin' => self::PIN], [['unlockOperator']])->assertOk();
        $this->assertNotNull(CounterOperator::id(), 'precondition: identified');
    }

    private function assertNoSpecialCategoryData(TestResponse $response, string $context): void
    {
        $body = (string) $response->getContent();
        foreach ([self::DNI, self::DOB, 'document_number', 'date_of_birth', 'is_therapeutic', 'document_hash', $this->planted->document_hash] as $needle) {
            $this->assertStringNotContainsString((string) $needle, $body, "{$context}: `{$needle}` reached the browser.");
        }
    }

    // --- 1. No operator, no data ------------------------------------------------------------------------------

    public function test_with_no_operator_the_search_is_refused_and_returns_no_member(): void
    {
        $snapshot = $this->snapshotFrom('/counter/members', 'counter.membership-counter');

        $called = $this->livewirePost($snapshot, ['lookup' => 'Zubi'], [['lookupResults']]);
        $this->assertNotSame(200, $called->getStatusCode(), 'lookupResults answered as a wire action');
        $this->assertStringNotContainsString('Zubizarreta', (string) $called->getContent());
        $this->assertNoSpecialCategoryData($called, 'no operator, called');

        // Nor through the render: typing into the box with nobody identified renders no results under the surface.
        $rendered = $this->livewirePost($snapshot, ['lookup' => 'Zubi'])->assertOk();
        $this->assertStringNotContainsString('Zubizarreta', (string) $rendered->getContent(), 'results rendered under the PIN surface');
    }

    // --- 2. Operator identified: shaped, not full ---------------------------------------------------------------

    public function test_with_an_operator_the_search_shows_the_row_and_nothing_more(): void
    {
        $this->identify();
        $snapshot = $this->snapshotFrom('/counter/members', 'counter.membership-counter');

        $called = $this->livewirePost($snapshot, ['lookup' => 'Zubi'], [['lookupResults']]);
        $this->assertNotSame(200, $called->getStatusCode(), 'lookupResults is still a wire action');
        $this->assertNoSpecialCategoryData($called, 'operator, called');

        $rendered = $this->livewirePost($snapshot, ['lookup' => 'Zubi'])->assertOk();
        $this->assertStringContainsString('Zubizarreta', (string) $rendered->getContent(), 'the result row is still shown');
        $this->assertStringContainsString('M-77123', (string) $rendered->getContent());
        $this->assertNoSpecialCategoryData($rendered, 'operator, rendered');
    }

    // --- 3. The application readers -----------------------------------------------------------------------------

    public function test_the_application_readers_are_not_wire_actions(): void
    {
        $application = MemberApplication::factory()->create([
            'organisation_id' => $this->org->id,
            'payload' => ['first_name' => 'Solicitante', 'last_name' => 'Distintivo', 'document_number' => '11223344X'],
        ]);

        foreach ([false, true] as $identified) {
            if ($identified) {
                $this->identify();
            }
            $snapshot = $this->snapshotFrom('/counter/members?alta='.$application->id, 'counter.membership-counter');

            foreach ([['altaApplication'], ['pendingAltaApplications'], ['worklist']] as $call) {
                $response = $this->livewirePost($snapshot, calls: [$call]);
                $this->assertNotSame(200, $response->getStatusCode(), "{$call[0]} answered as a wire action");
                $this->assertStringNotContainsString('11223344X', (string) $response->getContent());
            }
        }
    }

    // --- Belt and braces on the model ----------------------------------------------------------------------------

    public function test_the_blind_index_never_serialises(): void
    {
        $this->assertNotNull($this->planted->document_hash);
        $this->assertArrayNotHasKey('document_hash', $this->planted->toArray());
        $this->assertStringNotContainsString($this->planted->document_hash, $this->planted->toJson());
    }
}
