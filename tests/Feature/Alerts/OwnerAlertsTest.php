<?php

namespace Tests\Feature\Alerts;

use App\Actions\Till\OpenTill;
use App\Enums\AlertType;
use App\Enums\LocationKind;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Mail\AlertSummaryMail;
use App\Mail\TelegramDisconnectedMail;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\HeartbeatLog;
use App\Models\Location;
use App\Models\Member;
use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Models\User;
use App\Support\ActiveScope;
use App\ViewModels\SystemHealth;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ChangesRolePermissions;
use Tests\TestCase;

/**
 * Prompt 311 — the owner hears about low stock and a few operational problems without opening the panel: one grouped
 * Telegram message per person per run for what NEWLY crossed the line (fire once, clear on recovery), and a morning email
 * of everything still active. The two strain alerts ask for different jobs — move it from the store, or buy/harvest —
 * so they read differently and never fire together for the same strain and sede. No member data, ever.
 */
class OwnerAlertsTest extends TestCase
{
    use ChangesRolePermissions, RefreshDatabase;

    private Organisation $org;

    private Location $centro;

    private Location $norte;

    private Location $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        config(['services.telegram.token' => 'test-token', 'services.telegram.username' => 'club_avisos_bot', 'services.telegram.webhook_secret' => 'secreto']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->centro = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Centro', 'timezone' => 'Europe/Madrid']);
        $this->norte = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Sede Norte', 'timezone' => 'Europe/Madrid']);
        $this->store = Location::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Almacén', 'kind' => LocationKind::ALMACEN]);
        $this->owner = $this->recipient(Role::OWNER, [$this->centro, $this->norte, $this->store], '1001');
        $this->freshHeartbeats();
    }

    /** Every heartbeat fresh, so only the alert under test fires (a missing or old heartbeat IS a System alert). */
    private function freshHeartbeats(): void
    {
        foreach (array_keys((new SystemHealth)->heartbeats()) as $component) {
            HeartbeatLog::beat($component);
        }
    }

    /** @param  list<Location>  $at */
    private function recipient(Role $role, array $at, ?string $chatId): User
    {
        $user = User::factory()->create(['locale' => 'es']);
        $user->assignRole($role->value);
        $user->locations()->sync(array_map(fn (Location $l): string => $l->id, $at));
        if ($chatId !== null) {
            $user->linkTelegram($chatId);
        }

        return $user->fresh();
    }

    private function evaluate(): void
    {
        $this->artisan('alerts:evaluate')->assertSuccessful();
    }

    /** @return list<array{chat: string, text: string}> the Telegram messages sent so far */
    private function sent(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/sendMessage'))
            ->map(fn (array $pair): array => ['chat' => (string) $pair[0]['chat_id'], 'text' => (string) $pair[0]['text']])
            ->values()->all();
    }

    private function papel(int $stock): Article
    {
        return Article::factory()->create(['organisation_id' => $this->org->id, 'location_id' => $this->centro->id, 'name' => 'Papel', 'stock' => $stock, 'low_stock_threshold' => 10, 'active' => true]);
    }

    private function strain(string $name, int $centroCg, int $storeCg): Genetic
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => $name, 'active' => true]);
        GeneticPrice::create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id,
            'tier_id' => null, 'price_per_gram_cents' => 1000, 'active' => true, 'low_stock_threshold_cg' => 5000]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id, 'remaining_cg' => $centroCg, 'expires_on' => null]);
        if ($storeCg > 0) {
            Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->store->id, 'remaining_cg' => $storeCg, 'expires_on' => null]);
        }

        return $genetic;
    }

    private function active(AlertType $type): int
    {
        return OwnerAlertState::query()->where('type', $type->value)->whereNull('cleared_at')->count();
    }

    // --- 1. Fire once, clear, fire again --------------------------------------------------------------------------------

    public function test_a_product_at_its_threshold_sends_one_grouped_message_once_and_again_after_recovering(): void
    {
        $papel = $this->papel(10);

        $this->evaluate();
        $this->assertCount(1, $this->sent());
        $this->assertSame('1001', $this->sent()[0]['chat']);
        $this->assertStringContainsString('Papel', $this->sent()[0]['text']);
        $this->assertStringContainsString('Sede Centro', $this->sent()[0]['text']);
        $this->assertSame(1, $this->active(AlertType::PRODUCTS_LOW));

        $this->evaluate();
        $this->assertCount(1, $this->sent(), 'a product that stayed low was announced twice');

        $papel->forceFill(['stock' => 40])->save();
        $this->evaluate();
        $this->assertSame(0, $this->active(AlertType::PRODUCTS_LOW));
        $this->assertCount(1, $this->sent());

        $papel->forceFill(['stock' => 3])->save();
        $this->evaluate();
        $this->assertCount(2, $this->sent());
    }

    public function test_one_run_sends_one_message_per_person_however_many_alerts(): void
    {
        $this->papel(2);
        $this->strain('Amnesia Haze', 3800, 85000);
        $this->strain('Lemon Haze', 1250, 0);

        $this->evaluate();

        $this->assertCount(1, $this->sent());
        $text = $this->sent()[0]['text'];
        $this->assertLessThan(strpos($text, 'Lemon Haze'), strpos($text, 'Amnesia Haze'), 'restock first, running out second');
    }

    // --- 2. Expiry, the till, the system -----------------------------------------------------------------------------------

    public function test_expiry_the_till_and_a_stale_heartbeat_fire_and_clear(): void
    {
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id, 'name' => 'Critical']);
        $batch = Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id,
            'remaining_cg' => 9000, 'expires_on' => now()->addDays(10)->toDateString()]);
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->centro->id,
            'remaining_cg' => 9000, 'expires_on' => now()->addDays(30)->toDateString()]);
        $this->actingAs($this->owner);
        $till = (new OpenTill)->handle($this->centro, 'POS-1', 10000);
        $till->forceFill(['opened_at' => now()->subHours(17)])->save();
        HeartbeatLog::query()->where('component', 'scheduler')->update(['ran_at' => now()->subHour()]);

        $this->evaluate();
        $this->assertSame(1, $this->active(AlertType::BATCH_EXPIRING));
        $this->assertSame(1, $this->active(AlertType::TILL_OPEN_TOO_LONG));
        $this->assertSame(1, $this->active(AlertType::SYSTEM));

        $batch->forceFill(['remaining_cg' => 0])->save();
        $till->forceFill(['opened_at' => now()->subHours(2)])->save();
        HeartbeatLog::beat('scheduler');
        $this->evaluate();
        $this->assertSame(0, $this->active(AlertType::BATCH_EXPIRING));
        $this->assertSame(0, $this->active(AlertType::TILL_OPEN_TOO_LONG));
        $this->assertSame(0, $this->active(AlertType::SYSTEM));
    }

    // --- 2a. The two strain alerts ----------------------------------------------------------------------------------------

    public function test_the_two_strain_alerts_switch_between_restock_and_running_out(): void
    {
        $amnesia = $this->strain('Amnesia Haze', 3800, 85000);

        $this->evaluate();
        $this->assertSame([1, 0], [$this->active(AlertType::RESTOCK_FROM_STORE), $this->active(AlertType::RUNNING_OUT)]);
        $this->assertStringContainsString('850.00 g', $this->sent()[0]['text']);
        $this->assertStringContainsString('Almacén', $this->sent()[0]['text']);

        // The store runs out → the restock alert clears and "nothing in the store" fires, once.
        Batch::query()->withoutGlobalScopes()->where('location_id', $this->store->id)->update(['remaining_cg' => 0]);
        $this->evaluate();
        $this->assertSame([0, 1], [$this->active(AlertType::RESTOCK_FROM_STORE), $this->active(AlertType::RUNNING_OUT)]);
        $this->assertCount(2, $this->sent());
        $this->evaluate();
        $this->assertCount(2, $this->sent());

        // A delivery to the store switches it back.
        Batch::factory()->create(['organisation_id' => $this->org->id, 'genetic_id' => $amnesia->id, 'location_id' => $this->store->id, 'remaining_cg' => 20000, 'expires_on' => null]);
        $this->evaluate();
        $this->assertSame([1, 0], [$this->active(AlertType::RESTOCK_FROM_STORE), $this->active(AlertType::RUNNING_OUT)]);

        // The sede restocked → whichever was active clears.
        Batch::query()->withoutGlobalScopes()->where('location_id', $this->centro->id)->update(['remaining_cg' => 90000]);
        $this->evaluate();
        $this->assertSame([0, 0], [$this->active(AlertType::RESTOCK_FROM_STORE), $this->active(AlertType::RUNNING_OUT)]);
    }

    public function test_a_strain_low_with_nothing_anywhere_is_only_running_out(): void
    {
        $this->strain('Lemon Haze', 1250, 0);

        $this->evaluate();

        $this->assertSame([0, 1], [$this->active(AlertType::RESTOCK_FROM_STORE), $this->active(AlertType::RUNNING_OUT)]);
        $this->assertStringContainsString('Lemon Haze', $this->sent()[0]['text']);
        $this->assertStringContainsString(__(AlertType::RUNNING_OUT->label()), $this->sent()[0]['text']);
    }

    // --- 3. Who gets what -----------------------------------------------------------------------------------------------

    public function test_only_people_at_the_sede_with_the_permission_hear_about_it(): void
    {
        $norteOnly = $this->recipient(Role::MANAGER, [$this->norte], '2002');
        $this->setRolePermission(Role::STAFF, 'panel.access', true);
        $staff = $this->recipient(Role::STAFF, [$this->centro], '3003'); // no alerts.receive
        $this->papel(1);

        $this->evaluate();

        $this->assertSame(['1001'], array_column($this->sent(), 'chat'));
        $this->assertFalse($staff->can('alerts.receive'));
        $this->assertTrue($norteOnly->can('alerts.receive'));
    }

    public function test_a_person_can_take_only_the_restock_alert(): void
    {
        $this->owner->forceFill(['alert_preferences' => ['channels' => ['telegram'], 'types' => [AlertType::RESTOCK_FROM_STORE->value]]])->save();
        $this->papel(1);

        $this->evaluate();
        $this->assertSame([], $this->sent());

        $this->strain('Amnesia Haze', 3800, 85000);
        $this->evaluate();
        $this->assertCount(1, $this->sent());
    }

    // --- 5. A blocked bot -------------------------------------------------------------------------------------------------

    public function test_a_blocked_bot_clears_the_chat_and_sends_one_email(): void
    {
        Http::swap(new HttpFactory); // a second fake would only append behind setUp's
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        $this->papel(1);

        $this->evaluate();

        $this->assertNull($this->owner->fresh()->telegram_chat_id);
        Mail::assertQueued(TelegramDisconnectedMail::class, 1);
    }

    // --- 6. The morning email ---------------------------------------------------------------------------------------------

    public function test_the_morning_email_lists_every_active_alert_by_sede_and_is_not_sent_when_nothing_is_active(): void
    {
        $this->owner->forceFill(['alert_preferences' => ['channels' => ['email']]])->save();
        $this->travelTo(now('Europe/Madrid')->setTime(7, 50)->utc());
        $this->freshHeartbeats();
        $this->papel(1);
        $this->evaluate();
        Mail::assertNotQueued(AlertSummaryMail::class);

        $this->travelTo(now('Europe/Madrid')->setTime(8, 5)->utc());
        $this->freshHeartbeats();
        $this->evaluate();
        Mail::assertQueued(AlertSummaryMail::class, fn (AlertSummaryMail $mail): bool => $mail->hasTo($this->owner->email)
            && str_contains($mail->render(), 'Sede Centro') && str_contains($mail->render(), 'Papel'));
        $this->evaluate();
        Mail::assertQueued(AlertSummaryMail::class, 1);

        // The next morning with nothing active: nothing.
        Article::query()->withoutGlobalScopes()->update(['stock' => 100]);
        $this->evaluate();
        $this->travel(1)->days();
        $this->freshHeartbeats();
        $this->evaluate();
        Mail::assertQueued(AlertSummaryMail::class, 1);
    }

    // --- 7. No Telegram configured ----------------------------------------------------------------------------------------

    public function test_with_no_bot_token_nothing_breaks_and_the_email_summary_still_goes(): void
    {
        config(['services.telegram.token' => null]);
        $this->owner->forceFill(['alert_preferences' => ['channels' => ['telegram', 'email']]])->save();
        $this->travelTo(now('Europe/Madrid')->setTime(8, 30)->utc());
        $this->freshHeartbeats();
        $this->papel(1);

        $this->evaluate();

        $this->assertSame([], $this->sent());
        Mail::assertQueued(AlertSummaryMail::class, 1);
    }

    // --- 8. No member data --------------------------------------------------------------------------------------------------

    public function test_no_alert_carries_a_members_name_or_number(): void
    {
        $members = Member::factory()->count(3)->create(['organisation_id' => $this->org->id, 'status' => MemberStatus::ACTIVE]);
        $this->owner->forceFill(['alert_preferences' => ['channels' => ['telegram', 'email']]])->save();
        $this->travelTo(now('Europe/Madrid')->setTime(8, 30)->utc());
        $this->freshHeartbeats();
        $this->papel(1);
        $this->strain('Amnesia Haze', 3800, 85000);

        $this->evaluate();

        $texts = array_column($this->sent(), 'text');
        Mail::assertQueued(AlertSummaryMail::class, function (AlertSummaryMail $mail) use (&$texts): bool {
            $texts[] = $mail->render();

            return true;
        });
        foreach ($members as $member) {
            foreach ($texts as $text) {
                $this->assertStringNotContainsString($member->first_name.' '.$member->last_name, $text);
                $this->assertStringNotContainsString((string) $member->member_no, $text);
            }
        }
        $this->assertNotEmpty(Http::recorded()->filter(fn (array $pair): bool => $pair[0] instanceof Request));
    }
}
