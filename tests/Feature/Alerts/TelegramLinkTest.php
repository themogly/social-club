<?php

namespace Tests\Feature\Alerts;

use App\Actions\Alerts\IssueTelegramLink;
use App\Actions\ResolveLocale;
use App\Enums\AlertType;
use App\Enums\Role;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 311 — linking a person's Telegram: a one-time `t.me/<bot>?start=<code>` link (random, stored HASHED, 10 minutes,
 * single use); the bot's webhook receives `/start <code>` and is refused without Telegram's secret header; the chat id is
 * stored ENCRYPTED. `/stop` unlinks.
 */
class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => 'test-token', 'services.telegram.username' => 'club_avisos_bot', 'services.telegram.webhook_secret' => 'secreto']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([Location::factory()->create(['organisation_id' => $org->id])->id]);
    }

    private function code(): string
    {
        $url = (new IssueTelegramLink)->handle($this->owner);
        $this->assertStringStartsWith('https://t.me/club_avisos_bot?start=', $url);

        return substr($url, strlen('https://t.me/club_avisos_bot?start='));
    }

    private function webhook(string $text, ?string $secret = 'secreto', int $chat = 555111): TestResponse
    {
        return $this->postJson(route('telegram.webhook'),
            ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'], 'text' => $text]],
            $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    public function test_a_valid_start_links_the_chat_encrypted_and_replies(): void
    {
        $code = $this->code();
        $this->assertFalse(DB::table('telegram_link_codes')->where('code_hash', $code)->exists(), 'the code is stored in clear');

        $this->webhook('/start '.$code)->assertOk();

        $this->assertSame('555111', $this->owner->fresh()->telegram_chat_id);
        $this->assertStringNotContainsString('555111', (string) DB::table('users')->where('id', $this->owner->id)->value('telegram_chat_id'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage') && (string) $request['chat_id'] === '555111'
            && str_contains((string) $request['text'], __('Conectado. Te avisaré aquí.', [], (new ResolveLocale)->handle($this->owner))));
    }

    public function test_a_wrong_or_missing_secret_is_refused_and_nothing_is_stored(): void
    {
        $code = $this->code();

        $this->webhook('/start '.$code, 'otro')->assertForbidden();
        $this->webhook('/start '.$code, null)->assertForbidden();

        $this->assertNull($this->owner->fresh()->telegram_chat_id);
    }

    public function test_an_expired_or_reused_code_links_nothing(): void
    {
        $code = $this->code();
        $this->travel(11)->minutes();
        $this->webhook('/start '.$code)->assertOk();
        $this->assertNull($this->owner->fresh()->telegram_chat_id);

        $this->travelBack();
        $fresh = $this->code();
        $this->webhook('/start '.$fresh, chat: 1)->assertOk();
        $this->owner->fresh()->unlinkTelegram();
        $this->webhook('/start '.$fresh, chat: 2)->assertOk();
        $this->assertNull($this->owner->fresh()->telegram_chat_id, 'a used code linked a second chat');
    }

    public function test_stop_unlinks_the_chat(): void
    {
        $this->webhook('/start '.$this->code())->assertOk();
        $this->webhook('/stop')->assertOk();

        $this->assertNull($this->owner->fresh()->telegram_chat_id);
    }

    // --- The profile's Avisos section ---------------------------------------------------------------------------------

    public function test_the_profile_offers_telegram_saves_choices_and_shows_a_one_time_link(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner);

        $page = Livewire::test(EditProfile::class)
            ->assertSee(__('Avisos'))
            ->assertSee(__('Telegram, en el momento'))
            ->set('alerts.channels', ['email'])
            ->set('alerts.types', [AlertType::RESTOCK_FROM_STORE->value])
            ->call('saveAlerts');
        $this->assertSame(['email'], $this->owner->fresh()->alertChannels());
        $this->assertSame([AlertType::RESTOCK_FROM_STORE->value], $this->owner->fresh()->alertTypes());

        $page->mountAction('connectTelegram')->assertMountedActionModalSeeHtml('https://t.me/club_avisos_bot?start=');
        $this->assertSame(1, DB::table('telegram_link_codes')->where('user_id', $this->owner->id)->whereNull('used_at')->count());
    }

    public function test_with_no_bot_token_the_telegram_option_is_hidden(): void
    {
        config(['services.telegram.token' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner);

        Livewire::test(EditProfile::class)
            ->assertSee(__('Avisos'))
            ->assertDontSee(__('Telegram, en el momento'))
            ->assertActionHidden('connectTelegram');
    }

    public function test_without_the_permission_there_is_no_avisos_section(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create();
        $staff->assignRole(Role::STAFF->value);
        $staff->givePermissionTo('panel.access');
        $this->actingAs($staff);

        Livewire::test(EditProfile::class)->assertDontSee(__('Correo de la mañana (08:00) con lo que siga activo'));
    }
}
