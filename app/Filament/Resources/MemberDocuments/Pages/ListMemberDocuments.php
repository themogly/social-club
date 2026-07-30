<?php

namespace App\Filament\Resources\MemberDocuments\Pages;

use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListMemberDocuments extends ListRecords
{
    protected static string $resource = MemberDocumentResource::class;

    public function getSubheading(): ?string
    {
        return __('Cada apertura queda registrada en el historial de accesos. No constituye asesoramiento legal.');
    }
}
