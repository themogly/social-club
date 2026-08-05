<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMemberApplication extends ViewRecord
{
    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The invitation actions the operator came here for — copy link / resend / revoke (prompt 154),
            // gated on isInviteLive() so a dead link is never offered. Same actions as the list, not a copy.
            ...MemberApplicationResource::inviteActions(),
            EditAction::make(),
            ...MemberApplicationResource::recordActions(),
        ];
    }
}
