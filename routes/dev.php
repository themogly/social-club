<?php

use App\Http\Middleware\EnsureLocalEnvironment;
use App\Support\DevMail;
use Illuminate\Support\Facades\Route;

/*
 * Developer-only routes. Loaded on every environment but gated to `local` by the
 * EnsureLocalEnvironment middleware (404 elsewhere — asserted in test), so they
 * are safe to register unconditionally.
 */
Route::middleware(EnsureLocalEnvironment::class)->prefix('dev')->name('dev.')->group(function () {
    // Index of every registered mailable preview.
    Route::get('mail', function () {
        $items = collect(DevMail::previews())->keys()
            ->map(fn (string $key) => '<li style="margin:6px 0"><a href="'.url("dev/mail/{$key}").'">'.e($key).'</a></li>')
            ->implode('');

        return '<!doctype html><meta charset="utf-8"><title>Mail previews</title>'
            .'<body style="font-family:Inter,system-ui,sans-serif;max-width:640px;margin:40px auto;color:#0f172a">'
            .'<h1 style="color:#2563eb">Mail previews</h1><ul style="list-style:none;padding:0">'.$items.'</ul>';
    })->name('mail.index');

    // Render a single mailable in the browser (Laravel renders returned Mailables).
    Route::get('mail/{key}', fn (string $key) => DevMail::previews()[$key] ?? abort(404))->name('mail.show');
});
