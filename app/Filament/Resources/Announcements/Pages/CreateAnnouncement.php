<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Notifications\NewAnnouncementNotification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] ??= Auth::id();

        return $data;
    }

    /** A newly created, already-published announcement pushes to in-scope socios. */
    protected function afterCreate(): void
    {
        if ($this->record instanceof Announcement) {
            NewAnnouncementNotification::dispatchFor($this->record);
        }
    }
}
