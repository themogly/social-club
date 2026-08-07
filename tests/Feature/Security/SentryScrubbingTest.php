<?php

namespace Tests\Feature\Security;

use App\Support\SentryScrubber;
use Sentry\Event;
use Tests\TestCase;

/**
 * "Error monitoring configured for PRIVACY … verified that a captured event is actually scrubbed" — the
 * security audit's Phase 3, which had never been run (Phase C carry-forward).
 *
 * There was no config/sentry.php, so every option was a library default, and `max_request_body_size`
 * defaults to `'medium'` while `RequestIntegration::captureRequestBody()` gates on that size ALONE — NOT on
 * `send_default_pii`. With a DSN set, any unhandled exception on a POST shipped the whole body.
 */
class SentryScrubbingTest extends TestCase
{
    /** What a POST to prompt 179's MRZ endpoint would have carried into an error report. */
    private const MRZ = 'IDESP12345678Z9<<<<<<<<<<<<<<<8001014M3001018ESP<<<<<<<<<<<4GARCIA<<ANA<MARIA<<<<<<<<<<<<';

    public function test_the_request_body_is_never_captured_in_the_first_place(): void
    {
        // The actual fix. Everything below is defence in depth behind this one line.
        $this->assertSame('none', config('sentry.max_request_body_size'));
    }

    public function test_the_authenticated_user_and_their_ip_are_never_attached(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
    }

    public function test_sql_bindings_are_kept_out_of_breadcrumbs(): void
    {
        // Bindings carry the literal values being written — a document number, an email, a PIN.
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
    }

    public function test_the_scrubber_is_wired_and_serialisable_for_config_cache(): void
    {
        $before = config('sentry.before_send');

        $this->assertSame([SentryScrubber::class, 'handle'], $before);
        $this->assertIsCallable($before);
        // A Closure here would make `php artisan config:cache` fail outright, which on deploy means the fix
        // is silently absent exactly when it matters.
        $this->assertIsArray($before);
    }

    public function test_a_captured_event_carrying_an_mrz_and_a_pin_is_actually_scrubbed(): void
    {
        // Shaped as Sentry's own RequestIntegration would populate it, then run through the CONFIGURED
        // callback rather than the class directly — so the wiring is under test too, not just the scrubber.
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://club.test/socio/solicitud/tok123/leer',
            'method' => 'POST',
            'query_string' => ['token' => 'tok123', 'redirect' => '/socio'],
            'cookies' => ['laravel_session' => 'abcdef'],
            'headers' => ['Authorization' => 'Bearer sk_live_x', 'Accept' => 'text/html'],
            'data' => [
                'mrz' => self::MRZ,
                'operatorPin' => '4321',
                'document_number' => '12345678Z',
                'first_name' => 'Ana',
                '_token' => 'csrf',
            ],
        ]);
        $event->setExtra([
            'member' => ['email' => 'ana@example.test', 'phone' => '+34600111222'],
            'basket_lines' => 3,
        ]);

        $scrubbed = call_user_func(config('sentry.before_send'), $event, null);

        $this->assertNotNull($scrubbed);
        $blob = (string) json_encode([$scrubbed->getRequest(), $scrubbed->getExtra()]);

        // Assert against the ACTUAL serialised event, not against the shape we think it has.
        foreach ([self::MRZ, '4321', '12345678Z', 'ana@example.test', '+34600111222', 'sk_live_x', 'abcdef'] as $secret) {
            $this->assertStringNotContainsString($secret, $blob, "A secret survived scrubbing: {$secret}");
        }

        // The body is removed outright rather than redacted, so a config regression cannot bring it back
        // in readable form; cookies likewise.
        $this->assertArrayNotHasKey('data', $scrubbed->getRequest());
        $this->assertArrayNotHasKey('cookies', $scrubbed->getRequest());

        // …and the report is still worth having: the non-identifying context survives.
        $this->assertSame('POST', $scrubbed->getRequest()['method']);
        $this->assertStringContainsString('/leer', $scrubbed->getRequest()['url']);
        $this->assertSame(3, $scrubbed->getExtra()['basket_lines']);
    }

    public function test_redaction_is_by_key_and_reaches_nested_values(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['ctx' => ['nested' => ['member_email' => 'x@y.test', 'count' => 2]]]);

        $scrubbed = SentryScrubber::handle($event);

        $this->assertNotNull($scrubbed);
        $this->assertSame('[redacted]', $scrubbed->getExtra()['ctx']['nested']['member_email']);
        $this->assertSame(2, $scrubbed->getExtra()['ctx']['nested']['count']);
    }
}
