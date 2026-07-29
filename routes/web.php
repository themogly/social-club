<?php

// No public web routes. This app has no public/marketing surface (Spanish CSCs
// may not advertise — a legal constraint, see NOTES §A). The Filament admin panel
// is mounted at "/" by App\Providers\Filament\AdminPanelProvider and lands staff
// on the dashboard. Counter apps (dispensary/bar POS, check-in) and the member PWA
// register their own authenticated routes in their respective feature prompts.
//
// Local-only developer routes (e.g. /dev/mail) live in routes/dev.php, loaded only
// in the local environment from bootstrap/app.php.
