<?php

namespace App\Filament\Resources\Minutes\Pages;

use App\Filament\Resources\Minutes\MinuteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing is only reachable for an UNSIGNED draft (MinutePolicy withholds update once
 * signed; the model is the backstop). Quorum present tracks the attendee list; quorum
 * required stays as the action computed it at the meeting date.
 */
class EditMinute extends EditRecord
{
    protected static string $resource = MinuteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            MinuteResource::pdfAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['quorum_present'] = count(array_filter((array) ($data['attendees'] ?? []), 'is_string'));

        return $data;
    }
}
