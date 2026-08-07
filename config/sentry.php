<?php

use App\Support\SentryScrubber;

/**
 * Only the options this project sets DELIBERATELY. Everything else falls through to the package defaults —
 * sentry-laravel's ServiceProvider calls mergeConfigFrom(), so an omitted key is the vendor's, not null.
 *
 * This file exists because there was none, and the defaults are wrong for a system holding Article 9 data.
 * See App\Support\SentryScrubber for the full reasoning.
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Never attach the authenticated user, their IP or their cookies to an error report. The club's members
    // are identifiable people and a stack trace is not a lawful basis for shipping them anywhere.
    'send_default_pii' => false,

    // THE fix. Defaults to 'medium', and RequestIntegration::captureRequestBody() gates on this size ALONE
    // — not on send_default_pii — so leaving it default ships the whole POST body: the raw MRZ, the member
    // application payload, the counter PIN, the staff password. 'none' is the only correct value here.
    'max_request_body_size' => 'none',

    // Defence in depth for what arrives by another door (query string, headers, `extra` context). A CALLABLE
    // ARRAY, not a Closure — a closure in config breaks `config:cache`.
    'before_send' => [SentryScrubber::class, 'handle'],

    // Breadcrumbs record what happened before the error. SQL BINDINGS are the dangerous ones: they carry the
    // literal values of whatever was being written — a document number, an email, a PIN hash comparison.
    'breadcrumbs' => [
        'sql_queries' => true,
        'sql_bindings' => false,
    ],

];
