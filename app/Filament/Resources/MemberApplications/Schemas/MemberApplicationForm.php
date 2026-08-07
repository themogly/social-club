<?php

namespace App\Filament\Resources\MemberApplications\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

/**
 * What may be EDITED on an application, which is very little (admin audit, Phase C).
 *
 * This form used to offer `status` as a free Select over every ApplicationStatus case, and
 * `reject_reason` beside it — on an Edit page whose policy requires `applications.review`, which STAFF
 * holds (prompt 174). Measured before this branch: a STAFF user opened a submitted application whose
 * applicant was **14 years old**, set the status to APPROVED, and saved. The result was an application
 * marked APPROVED with **no member created**, `resulting_member_id` null, no versioned consent recorded,
 * no duplicate search and no age gate — because none of `ApproveApplication` was on that path.
 *
 * It was a second, ungated writer to a state one Action owns — the same failure the codebase refuses for
 * stock (`RecordStockMovement`) and pricing (`ResolvePrice`). Worse, it walked straight through prompt
 * 174's reasoning: `members.create` is withheld from STAFF *precisely* so they cannot produce a member
 * without the age gate, the duplicate search and the consent capture, and this let them record the outcome
 * anyway.
 *
 * So the status transitions live only where they always should have: the `approve`, `reject` and
 * `waitingList` actions on the resource, which call the Actions and enforce every gate. The sede is the one
 * thing left here, because reassigning an application to a location breaks nothing.
 */
class MemberApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('location_id')
                    ->label(__('Sede'))
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Sin sede asignada')),
            ]);
    }
}
