<?php

namespace App\Filament\Resources\DocumentTemplates\Schemas;

use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DocumentTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('Tipo de documento'))
                    ->options(DocumentTemplateResource::typeOptions())
                    ->required()
                    // The type is fixed for a template lineage — a new version keeps the type.
                    ->disabledOn('edit'),

                Placeholder::make('version_hint')
                    ->label(__('Versión'))
                    ->content(__('Al guardar se crea una versión nueva; las anteriores se conservan intactas y los documentos ya generados no cambian.')),

                Textarea::make('body')
                    ->label(__('Cuerpo de la plantilla'))
                    ->helperText(__('Texto que se imprime en el documento generado.'))
                    ->rows(12)
                    ->columnSpanFull(),

                Toggle::make('active')
                    ->label(__('Activa'))
                    ->helperText(__('La versión activa es la que se usa al generar nuevos documentos de este tipo.'))
                    ->default(true),
            ]);
    }
}
