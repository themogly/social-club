<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditMemberApplication extends EditRecord
{
    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
