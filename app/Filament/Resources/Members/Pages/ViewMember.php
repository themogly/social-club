<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
    use ReturnsToList;

    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        // From the record's view, *Editar* is the everyday act: it leads, then the edit page's own header (344).
        return [EditAction::make(), ...MemberResource::headerActions()];
    }
}
