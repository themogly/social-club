<?php

namespace App\Filament\Resources\Minutes\Pages;

use App\Actions\Documents\CreateMinute as CreateMinuteAction;
use App\Enums\MinuteBook;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Wraps App\Actions\Documents\CreateMinute — the domain owns sequential per-book
 * numbering and the quorum computed against members active at the meeting date. This
 * page never writes the acta itself; it hands the validated form data to the action.
 */
class CreateMinute extends CreateRecord
{
    protected static string $resource = MinuteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organisation = Organisation::query()->findOrFail(app(ActiveScope::class)->organisationId());

        /** @var User $actor */
        $actor = Auth::user();

        return (new CreateMinuteAction)->handle(
            $organisation,
            MinuteBook::from((string) $data['book']),
            $data,
            $actor,
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
