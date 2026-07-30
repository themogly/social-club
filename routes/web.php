<?php

use App\Http\Controllers\BarReceiptController;
use App\Http\Controllers\DispensationReceiptController;
use App\Http\Controllers\MemberDocumentController;
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
