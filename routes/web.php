<?php

use App\Http\Controllers\MemberDocumentController;
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
