<?php

namespace Tests\Feature\Counter;

use App\Actions\Members\IssueMemberToken;
use App\Actions\Till\OpenTill;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Enums\SettingType;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\Concerns\FindsMembers;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\MembershipCounter;
use App\Livewire\Counter\TillSession;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prompt 194 — ONE member lookup, on every counter screen that identifies a socio.
 *
 * Before this there were SEVEN inputs across five screens, in two incompatible shapes. The dispensary and the
 * door each stacked a scan box directly above a name box, both of which already accepted what the other asked
 * for; the till, Socios and the bar offered a name box with no scan affordance at all, so a card scanned there
 * — a USB wedge reader types into whatever has focus and presses Enter — searched for a 48-character name and
 * found nothing. Between them they taught operators that scanning "works on Dispensario but not on Socios",
 * which is not a rule anybody designed.
 *
 * The behaviour lives in {@see FindsMembers}; these are the guarantees it has to keep.
 */
class OneMemberLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Inputs that search a catalogue, NOT a socio. Named rather than pattern-excluded on purpose: a new
     * product filter should trip the guard below once and be added here deliberately, so the day somebody
     * adds a sixth member search it is not waved through as "probably another filter".
     *
     * @var list<string>
     */
    /**
     * Bindings whose NAME trips the `(search|scan|lookup)` heuristic without being a member search.
     *
     * `geneticSearch` / `articleSearch` are catalogue searches (212). `altaDocumentScan` is prompt 215's ID
     * DOCUMENT upload on the staff sign-up form — a file input, not a search box; it matches only because
     * "scan" is in its name. Kept as a named exception rather than by loosening the heuristic, which is the
     * half of this guard that catches a sixth screen growing its own box.
     */
    private const NON_MEMBER_SEARCHES = ['geneticSearch', 'articleSearch', 'altaDocumentScan'];

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id, 'capacity' => 10]);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        app(ActiveScope::class)->setLocation($this->location->id);
        $this->actingAs($user);

        return $user;
    }

    private function eligibleMember(string $lastName = 'García'): Member
    {
        $member = Member::factory()->create([
            'organisation_id' => $this->org->id,
            'status' => MemberStatus::ACTIVE,
            'last_name' => $lastName,
            'date_of_birth' => now()->subYears(30),
            'carencia_ends_at' => now()->subDay(),
        ]);
        $tier = MembershipTier::factory()->create(['organisation_id' => $this->org->id]);
        Membership::factory()->create([
            'organisation_id' => $this->org->id,
            'member_id' => $member->id,
            'location_id' => $this->location->id,
            'tier_id' => $tier->id,
            'status' => MembershipStatus::ACTIVE,
            'fee_cents' => 0,
        ]);

        return $member;
    }

    // --- The acceptance test: ONE input ------------------------------------------

    /**
     * 194's own acceptance criterion, as a permanent guard rather than a one-off grep.
     *
     * Two legs, because either alone is defeatable. The first counts the canonical field: it must exist and
     * exactly one blade may render it. The second re-greps every input in the view tree for a member-search
     * binding, so a sixth screen that grows its own box fails here instead of quietly restoring the shape
     * this prompt removed.
     */
    public function test_the_product_contains_exactly_one_member_search_input(): void
    {
        $canonical = [];
        $strays = [];

        foreach ($this->bladeFiles() as $file) {
            $html = (string) file_get_contents($file);
            $relative = str_replace(base_path().'/', '', $file);

            if (str_contains($html, 'id="member-lookup"')) {
                $canonical[] = $relative;
            }

            preg_match_all('/<input\b[^>]*>/s', $html, $inputs);

            foreach ($inputs[0] as $input) {
                if (preg_match('/wire:model[^=]*="([^"]+)"/', $input, $bound) !== 1) {
                    continue;
                }

                $property = $bound[1];

                if ($property === 'lookup' || in_array($property, self::NON_MEMBER_SEARCHES, true)) {
                    continue;
                }

                if (preg_match('/(search|scan|lookup)/i', $property) === 1) {
                    $strays[] = $relative.' → wire:model="'.$property.'"';
                }
            }
        }

        $this->assertSame(
            ['resources/views/livewire/counter/partials/member-lookup.blade.php'],
            $canonical,
            'The member lookup is ONE field, rendered from ONE partial.',
        );

        $this->assertSame([], $strays, 'A second member-search input has appeared: '.implode(', ', $strays));

        // And the shape it replaced is gone, not merely unreferenced.
        $this->assertFileDoesNotExist(resource_path('views/livewire/counter/partials/member-identify.blade.php'));
    }

    /**
     * Every counter screen that identifies a socio composes the ONE behaviour rather than its own — and the
     * caja, which no longer identifies anybody, composes NONE of it.
     *
     * The till is kept in this list deliberately (prompt 201). Dropping it would have been the easy move and
     * the wrong one: "the till has no lookup" is a STRONGER position than "the till has exactly one", and a
     * guard that silently stops covering a screen is how the thing it guards comes back.
     */
    public function test_every_counter_screen_that_identifies_a_socio_uses_the_shared_lookup(): void
    {
        foreach ([CheckInScreen::class, DispensaryPos::class, MembershipCounter::class, BarPos::class] as $screen) {
            $this->assertContains(
                FindsMembers::class,
                class_uses_recursive($screen),
                $screen.' identifies socios and must do it through the shared lookup.',
            );
        }

        // The caja is about the drawer. Fee collection moved to Socios in prompt 201 because it was the one
        // panel that made the operator go and find a person on a screen that is not about people.
        $this->assertNotContains(
            FindsMembers::class,
            class_uses_recursive(TillSession::class),
            'the caja identifies nobody — it must not carry the lookup at all',
        );
    }

    // --- The throttle only counts what could have been a scan --------------------

    /**
     * Thirty consecutive name misses lock nothing.
     *
     * This is the risk 194 flagged and it was real: prompt 58's limiter distinguishes a scan HIT from a scan
     * MISS, not a scan from a typed name. Once every unresolved input passes through ResolveMemberByToken,
     * an operator searching thirty socios across a shift would trip a limiter built for someone brute-forcing
     * QR codes — and lock the door mid-service.
     */
    public function test_thirty_consecutive_name_misses_lock_nothing(): void
    {
        $operator = $this->operator();
        $component = Livewire::test(CheckInScreen::class);

        for ($i = 1; $i <= 30; $i++) {
            $component->set('lookup', 'Nadie'.$i)->call('submitLookup')
                ->assertSet('lookupSearched', true)
                ->assertSet('flashMessage', null);
        }

        $this->assertSame(0, RateLimiter::attempts('qr-scan:'.$operator->id), 'A typed name is not a failed scan.');

        // Nothing is locked: a genuine card still identifies immediately afterwards.
        $member = $this->eligibleMember();
        $component->set('lookup', (new IssueMemberToken)->handle($member))->call('submitLookup')
            ->assertSet('memberId', $member->id);
    }

    /** A malformed token — long and strictly alphanumeric, i.e. scan-SHAPED — still counts, and still locks. */
    public function test_a_malformed_scan_shaped_token_increments_the_throttle(): void
    {
        $operator = $this->operator();
        $component = Livewire::test(CheckInScreen::class);

        $component->set('lookup', Str::random(48))->call('submitLookup');

        $this->assertSame(1, RateLimiter::attempts('qr-scan:'.$operator->id));

        // The default ceiling is ten failures a minute; the eleventh is refused rather than looked up.
        for ($i = 2; $i <= 10; $i++) {
            $component->set('lookup', Str::random(48))->call('submitLookup');
        }

        $component->set('lookup', Str::random(48))->call('submitLookup')
            ->assertSet('flashType', 'error')
            ->assertSet('flashMessage', __('Demasiados intentos de escaneo. Espera unos segundos.'));
    }

    /** The dividing line itself: what an operator types is never scan-shaped, a token always is. */
    public function test_looks_like_a_scan_separates_a_token_from_anything_a_person_types(): void
    {
        $this->assertTrue(FindsMembers::looksLikeAScan(Str::random(48)));
        $this->assertTrue(FindsMembers::looksLikeAScan(Str::random(32)));

        foreach (['García', 'M-00042', 'Lucía García', '42', 'garcia lopez perez maria del carmen'] as $typed) {
            $this->assertFalse(FindsMembers::looksLikeAScan($typed), $typed.' is typed, not scanned.');
        }
    }

    // --- A scanned card identifies on every screen, readers flag either way ------

    /**
     * @return list<array{0: class-string, 1: bool}>
     */
    public static function readerFlagProvider(): array
    {
        return [
            'dispensary blocker, readers on' => [DispensaryPos::class, true],
            'dispensary blocker, readers off' => [DispensaryPos::class, false],
            'socios, readers on' => [MembershipCounter::class, true],
            'socios, readers off' => [MembershipCounter::class, false],
        ];
    }

    /**
     * A scanned token identifies from the dispensary blocking state and from Socios, with card_readers_enabled
     * on AND off — because the flag governs the WORDS only. Token resolution runs either way, so a club that
     * has not told the software it owns a reader can still scan a card and have it work.
     *
     * @param  class-string  $screen
     */
    #[DataProvider('readerFlagProvider')]
    public function test_a_scanned_token_identifies_with_the_readers_flag_either_way(string $screen, bool $readers): void
    {
        Settings::set('card_readers_enabled', $readers, SettingType::BOOL, $this->location->id);
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000); // the dispensary's till step, above member in the chain

        $member = $this->eligibleMember();
        $token = (new IssueMemberToken)->handle($member);

        $component = Livewire::test($screen)
            ->assertSee($readers
                ? __('Escanea la tarjeta o escribe un nombre / nº de socio')
                : __('Buscar socio por nombre o nº'))
            ->set('lookup', $token)
            ->call('submitLookup');

        // Each host does its own thing with the socio — the ONLY thing that differs between these screens.
        $screen === DispensaryPos::class
            ? $component->assertSet('memberId', $member->id)->assertSet('scanned', true)
            : $component->assertSet('feeMemberId', $member->id);

        $component->assertSee($member->member_no);
    }

    /** The bar attaches an optional socio through the same field — and a scanned card now works there at all. */
    public function test_a_scanned_token_attaches_the_socio_at_the_bar(): void
    {
        Settings::set('bar_attach_socio_enabled', true, SettingType::BOOL, $this->location->id);
        $this->operator();
        (new OpenTill)->handle($this->location, 'BAR-1', 10000);

        $member = $this->eligibleMember();

        Livewire::test(BarPos::class)
            ->set('lookup', (new IssueMemberToken)->handle($member))
            ->call('submitLookup')
            ->assertSet('memberId', $member->id)
            ->assertSee($member->member_no);
    }

    /**
     * The till used to point `Cobrar cuota` at whoever the shared field resolved. It has no field now.
     *
     * This test is re-expressed rather than deleted (prompt 201): the caja renders ZERO member lookups, at
     * every permission level. The screen it was written about still has an assertion about it, and that
     * assertion is stricter than the one it replaces.
     */
    public function test_the_till_renders_no_member_lookup_at_all(): void
    {
        foreach ([Role::OWNER, Role::MANAGER, Role::STAFF] as $role) {
            $this->operator($role);
            (new OpenTill)->handle($this->location, 'TILL-'.$role->value, 10000);

            $html = (string) $this->get(route('counter.till'))->assertOk()->getContent();

            $this->assertSame(0, substr_count($html, 'id="member-lookup"'), 'the caja asks nobody to find a socio ('.$role->value.')');
        }
    }

    // --- The results are touch targets -------------------------------------------

    /**
     * Every result row clears the counter's 44x44 floor. These are tapped on a tablet, at speed, by somebody
     * standing up — a row that only just fits the text is a mis-tap on the wrong socio.
     */
    public function test_every_result_row_is_a_44px_touch_target(): void
    {
        $this->operator();
        $this->eligibleMember('García');
        $this->eligibleMember('Garcés');

        $html = Livewire::test(CheckInScreen::class)
            ->set('lookup', 'Gar')
            ->call('submitLookup')
            ->assertOk() // FIRST: a Blade error renders the template source, and every assertion below passes against a 500
            ->html();

        preg_match_all('/<button[^>]*data-member-lookup-result[^>]*>/s', $html, $rows);

        $this->assertCount(2, $rows[0], 'both socios surfaced');

        foreach ($rows[0] as $row) {
            $this->assertStringContainsString('min-h-[2.75rem]', $row); // 44px
            $this->assertStringContainsString('w-full', $row);          // and the whole row is the target
        }
    }

    /** An unknown card is an empty RESULT, not an error — the deliberate behaviour change one box requires. */
    public function test_an_unrecognised_card_falls_through_to_the_search_rather_than_erroring(): void
    {
        $this->operator();

        Livewire::test(CheckInScreen::class)
            ->set('lookup', Str::random(48))
            ->call('submitLookup')
            ->assertOk()
            ->assertSet('memberId', null)
            ->assertSet('flashMessage', null)
            ->assertSee(__('Sin resultados.'));
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
