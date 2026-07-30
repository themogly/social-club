<?php

namespace App\Filament\Resources\DocumentTemplates\Pages;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    public function getSubheading(): ?string
    {
        return __('Al guardar una plantilla se crea una versión nueva; los documentos ya generados no se modifican. No constituye asesoramiento legal.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Nueva plantilla')),
        ];
    }
}
