<?php

namespace App\Filament\Resources\Batches\Schemas;

use App\Filament\Forms\CameraOrFile;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Rules\GramAmount;
use App\Support\ActiveScope;
use App\Support\DocumentUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;

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
                            ->options(fn (): array => Location::assignableOptions(includeStores: true)) // stock may be received at the store (277)
                            ->default(fn (): ?string => app(ActiveScope::class)->locationId())
                            ->required()
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create' || self::singleSede())
                            // A disabled field is not submitted by default; the single-sede value still must be.
                            ->dehydrated()
                            ->helperText(fn (string $operation): ?string => $operation === 'create' && ! self::singleSede()
                                ? __('Donde entra el stock. Después se puede trasladar con «Trasladar».')
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

                        // Prompt 282 — the club's own name for the batch. Free, not unique (one harvest across several
                        // strains shares it), editable at any time; a rename reaches every part of the lote. The lote
                        // number stays the fixed traceability key and is not a field.
                        TextInput::make('label')
                            ->label(__('Nombre'))
                            ->maxLength(60)
                            ->helperText(__('Como lo llamáis vosotros, p. ej. «Cosecha verano 2026». Opcional.')),

                        // Prompt 298 — the grow's or supplier's own lote number, if there is one; left empty, a readable one is
                        // generated (AMN-260912-3). Only at intake: the lote number is the fixed traceability key.
                        TextInput::make('batch_no')
                            ->label(__('Nº de lote propio'))
                            ->maxLength(40)
                            ->helperText(__('El número del cultivo o del proveedor, si lo tiene. Si lo dejas vacío, se genera uno.'))
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        // On the batch's own page the lote number is shown clearly, with a copy button.
                        TextEntry::make('lote_number')
                            ->label(__('Nº de lote'))
                            ->state(fn (?Batch $record): ?string => $record?->batch_no)
                            ->copyable()
                            ->copyMessage(__('Copiado'))
                            ->icon(Heroicon::OutlinedClipboardDocument) // the visible "copy" cue: a click copies it
                            ->iconPosition(IconPosition::After)
                            ->tooltip(__('Copiar'))
                            ->weight(FontWeight::SemiBold)
                            ->visible(fn (string $operation): bool => $operation !== 'create'),

                        // Intake quantity — only at creation, and in the genetic's own unit:
                        // grams for a WEIGHT genetic, whole units for a UNIT genetic. Stock
                        // thereafter moves solely through the ledger (Ajuste / Merma), never a free edit.
                        TextInput::make('grams')
                            ->label(__('Cantidad (g)'))
                            ->numeric()
                            ->rule(new GramAmount)
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

                        // Prompt 278 (Ben's 271) — the SALE price is the batch's: required at intake, so no batch is ever
                        // received unpriced. Changed later only through the audited "Precio" action (prices.manage).
                        TextInput::make('sale_price_eur')
                            ->label(fn (Get $get): string => self::isUnitGenetic($get('genetic_id')) ? __('Precio por unidad (€)') : __('Precio por gramo (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        TextInput::make('price_per_eighth_eur')
                            ->label(__('Precio por octavo — 3,5 g (€)'))
                            ->helperText(__('Opcional.'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && ! self::isUnitGenetic($get('genetic_id'))),

                        DatePicker::make('acquired_or_harvested_on')
                            ->label(__('Fecha de adquisición/cosecha')),

                        DatePicker::make('expires_on')
                            ->label(__('Caducidad')),

                        CameraOrFile::field(FileUpload::make('lab_report_path')
                            ->label(__('Informe de laboratorio'))
                            ->disk('documents')
                            ->getUploadedFileUsing(DocumentUpload::withoutDirectUrl())
                            ->visibility('private')
                            ->maxSize(DocumentUpload::maxKilobytes())
                            ->helperText(DocumentUpload::helperText()), camera: 'environment', accept: 'image/*,application/pdf'),

                        // Photos of THIS harvest (278) — shown on the members' menu and the counter; public disk (a product
                        // photo is not personal data), resized on upload so the menu stays light.
                        CameraOrFile::field(FileUpload::make('images')
                            ->label(__('Fotos del lote'))
                            ->image()
                            ->imageEditor()
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('1200')
                            ->imageResizeTargetHeight('1200')
                            ->disk('public')
                            ->directory('batches')
                            ->multiple()
                            ->columnSpanFull(), camera: 'environment'),

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
