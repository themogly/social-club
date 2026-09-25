<?php

namespace App\Filament\Resources\Batches\Schemas;

use App\Models\Genetic;
use App\Models\Location;
use App\Support\ActiveScope;
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
                        // WHERE the stock lands (prompt 238). Stock always belongs to a sede — it drives the
                        // per-premises legal ceiling and the registro de dispensación — so intake must NAME it
                        // rather than inherit an invisible scope. Defaults to the sede in the topbar; BLANK in
                        // the "all sedes" rollup so an owner picks deliberately (never a guessed first row,
                        // prompt 148); disabled and pre-filled when the club has a single sede, where there is
                        // nothing to choose. Fixed at intake, like the genetic — disabled once the batch exists.
                        Select::make('location_id')
                            ->label(__('Sede'))
                            ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (): ?string => app(ActiveScope::class)->locationId())
                            ->required()
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create' || self::singleSede())
                            // A disabled field is not submitted by default; the single-sede value still must be.
                            ->dehydrated()
                            ->helperText(fn (string $operation): ?string => $operation === 'create' && ! self::singleSede()
                                ? __('El stock pertenece a esta sede y no se puede mover luego.')
                                : null),

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

    /** One sede in the org: nothing to choose, so the field is pre-filled and locked. */
    private static function singleSede(): bool
    {
        return Location::query()->count() === 1;
    }
}
