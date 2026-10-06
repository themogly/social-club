<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Strip personal data out of an error report before it leaves the building (security audit, Phase C).
 *
 * There was no `config/sentry.php` at all: Sentry was wired only by `Integration::handles($exceptions)` in
 * bootstrap/app.php, so every option was a library default. `sentry/sentry`'s `max_request_body_size`
 * defaults to `'medium'`, and `RequestIntegration::captureRequestBody()` gates on that size ALONE — it is
 * NOT gated on `send_default_pii`. So the moment `SENTRY_LARAVEL_DSN` was set, any unhandled exception on a
 * POST would ship the whole parsed body to a third-party processor: the raw MRZ from prompt 179 (name, date
 * of birth and document number in one string), the member application payload, the counter operator's PIN in
 * a Livewire update, and the staff password on login — Laravel's `dontFlash` protects the SESSION, not this.
 *
 * That matters most at the worst moment: the DSN goes in as part of going to production, which is the same
 * deploy that brings real members' Article 9 data.
 *
 * The config sets `max_request_body_size => 'none'`, which is the actual fix. This is defence in depth for
 * everything that arrives by another door — the query string, and any `extra` context a future call site
 * attaches — because one option in one file is a thin thing to rest Article 9 data on.
 *
 * Registered as a CALLABLE ARRAY, never a Closure: a closure in a config file breaks `config:cache`.
 */
class SentryScrubber
{
    /**
     * Keys whose VALUE never leaves the server. Matched case-insensitively as a substring, so
     * `operatorPin`, `pin_confirmation` and `PIN` all hit `pin`.
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'pin', 'password', 'secret', 'token', 'authorization', 'api_key', 'signature',
        'mrz', 'document_number', 'document_scan', 'dni', 'nif', 'date_of_birth', 'birth_date',
        'first_name', 'last_name', 'surname', 'given_names', 'email', 'phone', 'address', 'payload',
    ];

    private const REDACTED = '[redacted]';

    /**
     * Prompt 363 — a Telegram Bot API URL carries the bot TOKEN in its path (`/bot<token>/sendMessage`). It reaches Sentry
     * in an outgoing-request breadcrumb, or inside a cURL error message. Matched by shape, not by value.
     */
    private const TELEGRAM_TOKEN = '#/bot\d+:[A-Za-z0-9_-]+#';

    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        // Prompt 363 — the bot token, wherever a message or an exception value quotes a Telegram URL.
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubTelegram($exception->getValue()));
        }
        if ($event->getMessage() !== null) {
            $event->setMessage(self::scrubTelegram($event->getMessage()), $event->getMessageParams(),
                $event->getMessageFormatted() !== null ? self::scrubTelegram($event->getMessageFormatted()) : null);
        }

        $request = $event->getRequest();

        // The body should never be here at all (`max_request_body_size => 'none'`). Removed rather than
        // redacted so a future config regression cannot quietly reintroduce it in readable form.
        unset($request['data'], $request['cookies']);

        if (isset($request['query_string'])) {
            $request['query_string'] = self::redact($request['query_string']);
        }

        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = self::redact($request['headers']);
        }

        $event->setRequest($request);
        $event->setExtra(self::redact($event->getExtra()));

        return $event;
    }

    /** Prompt 363 — `before_breadcrumb`: an outgoing request to Telegram keeps its method and status, never the token. */
    public static function breadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        foreach ($breadcrumb->getMetadata() as $name => $value) {
            if (is_string($value)) {
                $breadcrumb = $breadcrumb->withMetadata((string) $name, self::scrubTelegram($value));
            }
        }
        if ($breadcrumb->getMessage() !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::scrubTelegram($breadcrumb->getMessage()));
        }

        return $breadcrumb;
    }

    private static function scrubTelegram(string $text): string
    {
        return (string) preg_replace(self::TELEGRAM_TOKEN, '/bot'.self::REDACTED, $text);
    }

    /**
     * Redact by KEY, recursively. Values are never inspected — a rule that reads values would have to
     * decide what a document number looks like, and would be wrong about somebody's.
     *
     * @param  mixed  $data
     * @return mixed
     */
    private static function redact($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $clean[$key] = self::isSensitive((string) $key) ? self::REDACTED : self::redact($value);
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
