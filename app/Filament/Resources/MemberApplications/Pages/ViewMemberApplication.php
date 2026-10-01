<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMemberApplication extends ViewRecord
{
    use ReturnsToList;

    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        // The invitation actions (prompt 154, gated on isInviteLive()) and the review decision — the same actions as the
        // list, not copies. Prompt 344: everyday first, the two red ones (reject, revoke) last, so neither sits before
        // a harmless button.
        return [
            EditAction::make(),
            MemberApplicationResource::copyLinkAction(),
            MemberApplicationResource::resendAction(),
            MemberApplicationResource::approveAction(),
            MemberApplicationResource::waitingListAction(),
            MemberApplicationResource::rejectAction(),
            MemberApplicationResource::revokeAction(),
        ];
    }
}
