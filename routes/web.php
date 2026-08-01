<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\BarReceiptController;
use App\Http\Controllers\CounterLocationController;
use App\Http\Controllers\DispensationReceiptController;
use App\Http\Controllers\Member\AnnouncementController;
use App\Http\Controllers\Member\EventController;
use App\Http\Controllers\Member\NotificationController;
use App\Http\Controllers\MemberDocumentController;
use App\Http\Controllers\Socio\AuthController as SocioAuthController;
use App\Http\Controllers\Socio\PwaController;
use App\Livewire\Counter\BarPos;
use App\Livewire\Counter\CheckInScreen;
use App\Livewire\Counter\DispensaryPos;
use App\Livewire\Counter\TillSession;
use Illuminate\Support\Facades\Route;

// Authenticated, signed, short-lived access to a member document on the private
// disk (issued by App\Actions\Members\IssueDocumentUrl). The `signed` middleware
// enforces expiry; the ULID path is not guessable.
Route::middleware(['web', 'auth', 'signed'])
    ->get('members/documents/{document}', [MemberDocumentController::class, 'show'])
    ->name('members.documents.show');

// No public web routes. This app has no public/marketing surface (Spanish CSCs
// may not advertise — a legal constraint, see NOTES §A). The Filament admin panel
// is mounted at "/" by App\Providers\Filament\AdminPanelProvider and lands staff
// on the dashboard. Counter apps (dispensary/bar POS, check-in) and the member PWA
// register their own authenticated routes in their respective feature prompts.
//
// Local-only developer routes (e.g. /dev/mail) live in routes/dev.php, loaded only
// in the local environment from bootstrap/app.php.

// Counter apps run OUTSIDE the Filament panel on their own tablet-first authenticated
// routes (full-page Livewire components with the shared `counter` layout). The
// check-in "door" is the first; the dispensary + bar POS (prompts 11/12) follow the
// same pattern. The active location comes from ActiveScope; the component resolves the
// operator's first assigned sede when none is set, and 403s a user without checkin.manage.
Route::middleware(['web', 'auth'])
    ->get('/counter/checkin', CheckInScreen::class)
    ->name('counter.checkin');

// The till (caja) terminal — same tablet-first pattern as the door. Opening/closing a
// session and recording cash movements all happen here (never inside Filament, which is
// oversight-only). Closing is a BLIND arqueo: the expected figure is withheld until the
// operator has entered their count. Gated in the component on till.open OR till.close.
Route::middleware(['web', 'auth'])
    ->get('/counter/till', TillSession::class)
    ->name('counter.till');

// The dispensary POS — the same tablet-first pattern. A THIN shell over the domain
// Actions: CommitDispensation is the compliance boundary (membership/carencia/limits/
// stock/pricing enforced atomically). Gated in the component on pos.use; contributions
// attach to the open till session. The printable contribution ticket is served by a
// ULID route and authorization-checked through DispensationPolicy (never a guessable id).
Route::middleware(['web', 'auth'])
    ->get('/counter/pos', DispensaryPos::class)
    ->name('counter.pos');

Route::middleware(['web', 'auth'])
    ->get('/counter/pos/receipt/{dispensation}', [DispensationReceiptController::class, 'show'])
    ->name('counter.pos.receipt');

// Switch the counter's working sede (prompt 89). The ONLY writer of `counter.location_id`, gated by
// LocationSwitcher's server-side assignment check — never a raw setLocation from client input, and it
// never touches the admin panel's scope. POST + redirect back so the counter screen re-mounts on the sede.
Route::middleware(['web', 'auth'])
    ->post('/counter/location', [CounterLocationController::class, 'switch'])
    ->name('counter.location');

// The bar / merch POS — the auxiliary-income counterpart, same tablet-first pattern. A
// THIN shell over CommitOrder (freezes the item snapshot, depletes UNIT stock, optionally
// charges a member wallet, posts cash to the SHARED till). Gated in the component on
// pos.bar. The socio is OPTIONAL (cash guests are fine); wallet payment requires one.
// The printable SALE ticket (venta / ticket — distinct from the contribution vocabulary)
// is served by a ULID route and authorization-checked through OrderPolicy.
Route::middleware(['web', 'auth'])
    ->get('/counter/bar', BarPos::class)
    ->name('counter.bar');

Route::middleware(['web', 'auth'])
    ->get('/counter/bar/receipt/{order}', [BarReceiptController::class, 'show'])
    ->name('counter.bar.receipt');

// The member PWA (prompt 15) — the SECOND guard. Passwordless magic-link auth; every
// area route sits behind auth:member and is scoped to the authenticated socio (there is
// NO member id in any URL, so one member can never reach another's data). This is the
// only member-facing surface; it is never public or indexable (noindex is global).
Route::middleware('web')->prefix('socio')->name('socio.')->group(function () {
    Route::get('login', [SocioAuthController::class, 'show'])->name('login');
    Route::post('login', [SocioAuthController::class, 'sendLink'])->middleware('throttle:5,1')->name('login.send');
    Route::get('login/verify/{token}', [SocioAuthController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');

    Route::middleware('auth:member')->group(function () {
        Route::get('/', [PwaController::class, 'home'])->name('home');
        Route::get('menu', [PwaController::class, 'menu'])->name('menu');
        Route::get('historial', [PwaController::class, 'history'])->name('history');
        Route::get('mis-datos', [PwaController::class, 'export'])->name('export');
        Route::post('logout', [SocioAuthController::class, 'logout'])->name('logout');
    });
});

// Member PWA — club communications, push & the tokenised application (prompt 15).
// Same guard contract as above: every authenticated route is scoped to the socio and
// carries NO member id. The application form is the SINGLE unauthenticated member-facing
// route, gated only by a valid invite token (a hash lookup — never a guessable id).
Route::middleware('web')->prefix('socio')->name('socio.')->group(function () {
    // Public: the tokenised invite opens the pre-registration form on the prospect's phone.
    Route::get('solicitud/{token}', [ApplicationController::class, 'show'])->name('application');
    Route::post('solicitud/{token}', [ApplicationController::class, 'store'])
        ->middleware('throttle:10,1')->name('application.store');

    Route::middleware('auth:member')->group(function () {
        Route::get('avisos', [AnnouncementController::class, 'index'])->name('announcements');

        Route::get('eventos', [EventController::class, 'index'])->name('events');
        Route::post('eventos/{event}/rsvp', [EventController::class, 'rsvp'])->name('events.rsvp');

        Route::get('notificaciones', [NotificationController::class, 'edit'])->name('notifications');
        Route::post('notificaciones/preferencias', [NotificationController::class, 'updatePreferences'])->name('notifications.prefs');
        Route::post('push/suscribir', [NotificationController::class, 'subscribe'])->name('push.subscribe');
        Route::post('push/cancelar', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');
    });
});
