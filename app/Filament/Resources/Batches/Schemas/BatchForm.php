<?php

namespace App\Filament\Resources\Batches\Schemas;

use App\Models\Genetic;
use App\Support\DocumentUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Lote'))
                    ->schema([
                        Select::make('genetic_id')
                            ->label(__('Genética'))
                            ->relationship('genetic', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            // The strain is fixed at intake — never reassign an existing batch.
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),

                        // Intake quantity — only at creation, and in the genetic's own unit:
                        // grams for a WEIGHT genetic, whole units for a UNIT genetic. Stock
                        // thereafter moves solely through the ledger (Ajuste / Merma), never a free edit.
                        TextInput::make('grams')
                            ->label(__('Cantidad (g)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(fn (Get $get): bool => ! self::isUnitGenetic($get('genetic_id')))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ! self::isUnitGenetic($get('genetic_id'))),

                        TextInput::make('units')
                            ->label(__('Cantidad (uds)'))
                            ->numeric()
                            ->minValue(1)
                            ->step(1)
                            ->required(fn (Get $get): bool => self::isUnitGenetic($get('genetic_id')))
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && self::isUnitGenetic($get('genetic_id'))),

                        TextInput::make('cost_per_gram_eur')
                            ->label(__('Coste por gramo (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        DatePicker::make('acquired_or_harvested_on')
                            ->label(__('Fecha de adquisición/cosecha')),

                        DatePicker::make('expires_on')
                            ->label(__('Caducidad')),

                        FileUpload::make('lab_report_path')
                            ->label(__('Informe de laboratorio'))
                            ->disk('documents')
                            ->getUploadedFileUsing(DocumentUpload::withoutDirectUrl())
                            ->visibility('private')
                            ->maxSize(DocumentUpload::maxKilobytes())
                            ->helperText(DocumentUpload::helperText()),

                        Textarea::make('notes')
                            ->label(__('Notas'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /** Is the currently-selected genetic dispensed by unit (preroll/edible)? */
    private static function isUnitGenetic(?string $geneticId): bool
    {
        return $geneticId !== null && (Genetic::query()->find($geneticId)?->isUnitType() ?? false);
    }
}
