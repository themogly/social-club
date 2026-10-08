<?php

namespace Tests\Feature\Alerts;

use App\Enums\Role;
use App\Exceptions\TelegramTokenRejectedException;
use App\Filament\Pages\SystemHealth as SystemHealthPage;
use App\Jobs\SendTelegramMessage;
use App\Mail\TelegramAlertByEmailMail;
use App\Mail\TelegramDisconnectedMail;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\SentryScrubber;
use App\Support\Telegram;
use App\ViewModels\SystemHealth;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Tests\Support\StringifyingStore;
use Tests\TestCase;

/**
 * Prompt 363 — Sentry on production: «Telegram answered 401», four times per alert, while the owner's urgent alerts never
 * arrived. 401/404 mean the BOT TOKEN is wrong (configuration): no retry, the alert goes by email instead, the fault is
 * flagged on Salud del sistema and reported to Sentry at most once an hour — and the token never reaches a log or Sentry.
 */
class TelegramTokenRejectedTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAH-secret_token_value';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.username' => 'club_avisos_bot', 'services.telegram.webhook_secret' => 'secreto']);
        $this->seed(RolePermissionSeeder::class);
        $org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($org->id);
        $this->owner = User::factory()->create(['locale' => 'es']);
        $this->owner->assignRole(Role::OWNER->value);
        $this->owner->locations()->sync([Location::factory()->create(['organisation_id' => $org->id])->id]);
        $this->owner->forceFill(['telegram_chat_id' => '555111'])->save();
        Mail::fake();
        // ONE fake, answering whatever the test last set (repeated Http::fake() calls stack: the first stub keeps winning).
        Http::fake(['api.telegram.org/*' => fn () => ($this->answer)()]);
        $this->telegramAnswers(200);
    }

    /** @var \Closure(): mixed */
    private \Closure $answer;

    private function telegramAnswers(int $status, array $body = []): void
    {
        $this->answer = fn () => Http::response($body + ['ok' => $status < 300, 'description' => $status < 300 ? null : 'Unauthorized', 'result' => ['username' => 'club_avisos_bot']], $status);
    }

    private function send(string $text = 'Stock bajo: Amnesia'): SendTelegramMessage
    {
        $job = (new SendTelegramMessage($this->owner->id, $text))->withFakeQueueInteractions();
        $job->handle();

        return $job;
    }

    // 1 — a rejected token: no retry, the alert by email, the flag set.
    public function test_a_401_does_not_retry_and_sends_the_alert_by_email(): void
    {
        $this->telegramAnswers(401);
        $job = $this->send();

        $job->assertNotReleased();
        $job->assertNotFailed();
        Mail::assertQueued(TelegramAlertByEmailMail::class, fn (TelegramAlertByEmailMail $mail): bool => $mail->hasTo($this->owner->email)
            && $mail->text === 'Stock bajo: Amnesia' && $mail->locale === 'es');
        $this->assertNotNull(Telegram::tokenRejectedAt());
    }

    // 2 — ten alerts, one Sentry report an hour, ten emails.
    public function test_ten_rejected_alerts_report_once_and_email_ten_times(): void
    {
        Exceptions::fake();
        $this->telegramAnswers(401);
        foreach (range(1, 10) as $i) {
            $this->send("Aviso {$i}");
        }

        Exceptions::assertReportedCount(1);
        Mail::assertQueuedCount(10);

        $this->travel(61)->minutes();
        $this->send();
        Exceptions::assertReportedCount(2);
    }

    // 3 — a malformed token answers 404.
    public function test_a_404_is_a_rejected_token_too(): void
    {
        $this->telegramAnswers(404);
        $this->send()->assertNotReleased();

        Mail::assertQueued(TelegramAlertByEmailMail::class);
        $this->assertNotNull(Telegram::tokenRejectedAt());
    }

    // 4 — a passing outage keeps the retries; 429 honours retry_after.
    public function test_an_outage_retries_and_a_429_waits_as_told(): void
    {
        $this->telegramAnswers(500);
        try {
            $this->send();
            $this->fail('a 500 must be retried');
        } catch (RuntimeException $e) {
            $this->assertSame('Telegram answered 500', $e->getMessage());
        }

        $this->answer = fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        try {
            $this->send();
            $this->fail('a timeout must be retried');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }

        $this->telegramAnswers(429, ['parameters' => ['retry_after' => 5]]);
        $this->send()->assertReleased(delay: 5);
        $this->assertNull(Telegram::tokenRejectedAt(), 'an outage is not a bad token');
        Mail::assertNothingQueued();
    }

    // 5 — a person who blocked the bot: unchanged.
    public function test_a_403_still_unlinks_and_sends_one_disconnected_email(): void
    {
        $this->telegramAnswers(403);
        $this->send();

        $this->assertNull($this->owner->fresh()->telegram_chat_id);
        Mail::assertQueued(TelegramDisconnectedMail::class, 1);
        Mail::assertNotQueued(TelegramAlertByEmailMail::class);
        $this->assertNull(Telegram::tokenRejectedAt());
    }

    // 6 — a good send clears the flag, and the health row reads normal again.
    public function test_a_successful_send_clears_the_flag_and_the_health_row(): void
    {
        $this->telegramAnswers(401);
        $this->send();
        $this->assertNotNull((new SystemHealth)->alerts()['telegram_rejected_at']);

        $this->telegramAnswers(200);
        $this->send();
        $this->assertNull(Telegram::tokenRejectedAt());
        $this->assertNull((new SystemHealth)->alerts()['telegram_rejected_at']);
    }

    // 7 — a real check of the token, and the webhook refuses a bad one.
    public function test_telegram_check_and_set_webhook_test_the_token_for_real(): void
    {
        $this->telegramAnswers(200);
        $this->artisan('telegram:check')->expectsOutputToContain('@club_avisos_bot')->assertSuccessful();

        $this->telegramAnswers(401);
        $this->artisan('telegram:check')->expectsOutputToContain('401')->assertFailed();
        $this->artisan('telegram:set-webhook')->assertFailed();
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'setWebhook'));
    }

    // 8 — the token never reaches a log line, an exception message or a Sentry breadcrumb / event.
    public function test_the_token_never_reaches_a_log_an_exception_or_sentry(): void
    {
        Log::spy();
        Exceptions::fake();
        $this->telegramAnswers(401);
        $this->send();

        Exceptions::assertReported(fn (TelegramTokenRejectedException $e): bool => ! str_contains($e->getMessage(), self::TOKEN));
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => str_contains((string) $m, self::TOKEN))]);

        $crumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, ['url' => 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage', 'method' => 'POST']);
        $scrubbed = SentryScrubber::breadcrumb($crumb);
        $this->assertNotNull($scrubbed);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($scrubbed->getMetadata()));

        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('cURL error 28 for https://api.telegram.org/bot'.self::TOKEN.'/getMe'))]);
        $event->setMessage('failed https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        $clean = SentryScrubber::handle($event);
        $this->assertStringNotContainsString(self::TOKEN, (string) $clean?->getExceptions()[0]->getValue());
        $this->assertStringNotContainsString(self::TOKEN, (string) $clean?->getMessage());
    }

    // 369 — Redis hands the flag back as a string: the health row must still show.
    public function test_the_flag_reads_back_from_a_store_that_returns_numbers_as_strings_like_redis(): void
    {
        Cache::extend('stringifying', fn (): Repository => new Repository(new StringifyingStore));
        config(['cache.stores.stringifying' => ['driver' => 'stringifying'], 'cache.default' => 'stringifying']);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:38:23'));
        Telegram::markTokenRejected(401);
        $this->assertIsString(Cache::get('telegram.token_rejected_at'), 'the double behaves as Redis does');

        $this->assertSame(CarbonImmutable::parse('2026-10-08 10:38:23')->getTimestamp(), Telegram::tokenRejectedAt()?->getTimestamp());
        $this->assertNotNull((new SystemHealth)->alerts()['telegram_rejected_at']);

        $owner = User::factory()->create();
        $owner->assignRole(Role::OWNER->value);
        $this->assertStringContainsString(e(__('Token de Telegram rechazado — revisa TELEGRAM_BOT_TOKEN')),
            Livewire::actingAs($owner)->test(SystemHealthPage::class)->html());
    }
}
