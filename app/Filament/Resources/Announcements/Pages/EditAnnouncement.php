<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Notifications\NewAnnouncementNotification;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Only push when the publish date CHANGES (i.e. it was just published/re-published),
     * so editing the body of an already-live announcement does not re-notify everyone.
     */
    protected function afterSave(): void
    {
        if ($this->record instanceof Announcement && $this->record->wasChanged('published_at')) {
            NewAnnouncementNotification::dispatchFor($this->record);
        }
    }
}
