<?php

namespace App\Filament\Resources\Batches\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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
                            // The strain is fixed at intake — never reassign an existing batch.
                            ->disabled(fn (string $operation): bool => $operation !== 'create'),

                        // Intake quantity — only at creation. Stock thereafter moves solely
                        // through the ledger (the Ajuste / Merma row actions), never a free edit.
                        TextInput::make('grams')
                            ->label(__('Cantidad (g)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create'),

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
                            ->visibility('private'),

                        Textarea::make('notes')
                            ->label(__('Notas'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
