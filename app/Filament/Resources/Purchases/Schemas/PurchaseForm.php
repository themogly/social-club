<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Filament\Forms\CameraOrFile;
use App\Models\Batch;
use App\Models\Location;
use App\Support\DocumentUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Amount and paid are edited in euros and converted to integer cents on the Create
 * page via Money (owing = amount − paid). The optional batch link + intake grams let
 * a cannabis purchase carry its cost-per-gram onto the batch (grams → centigrams);
 * those are a create-time intake decision. The invoice lives on the PRIVATE disk.
 */
class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Compra'))
                    ->schema([
                        Select::make('supplier_id')
                            ->label(__('Proveedor'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('location_id')
                            ->label(__('Sede'))
                            ->relationship('location', 'name', modifyQueryUsing: fn (Builder $query): Builder => Location::limitToAssignable($query))
                            ->searchable()
                            ->preload(),

                        TextInput::make('amount_eur')
                            ->label(__('Importe (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        TextInput::make('paid_eur')
                            ->label(__('Pagado (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(__('El saldo pendiente es el importe menos lo pagado.')),

                        DatePicker::make('purchased_on')
                            ->label(__('Fecha'))
                            ->default(now())
                            ->required(),

                        KeyValue::make('items')
                            ->label(__('Productos'))
                            ->keyLabel(__('Concepto'))
                            ->valueLabel(__('Detalle'))
                            ->columnSpanFull(),

                        CameraOrFile::field(FileUpload::make('invoice_path')
                            ->label(__('Factura'))
                            ->disk('documents')
                            ->getUploadedFileUsing(DocumentUpload::withoutDirectUrl())
                            ->visibility('private')
                            ->maxSize(DocumentUpload::maxKilobytes())
                            ->helperText(DocumentUpload::helperText())
                            ->columnSpanFull(), camera: 'environment', accept: 'image/*,application/pdf'),
                    ])
                    ->columns(2),

                Section::make(__('Entrada de stock (opcional)'))
                    ->description(__('Vincula la compra a un lote para trasladarle el coste por gramo.'))
                    ->schema([
                        Select::make('batch_id')
                            ->label(__('Lote'))
                            // Prompt 282 — shown as "Amnesia · Cosecha verano 2026 · B-7QX2KD" and found by the club's name
                            // as well as the lote number.
                            ->relationship('batch', 'batch_no', fn (Builder $query) => $query->with('genetic'))
                            ->getOptionLabelFromRecordUsing(fn (Batch $record): string => $record->displayName())
                            ->searchable(['label', 'batch_no'])
                            ->preload(),

                        TextInput::make('intake_grams')
                            ->label(__('Cantidad de entrada (g)'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('Al indicar una cantidad, se traslada el coste por gramo al lote seleccionado.')),
                    ])
                    ->columns(2)
                    // Carrying cost onto a batch is an intake decision, taken once at creation.
                    ->visible(fn (string $operation): bool => $operation === 'create'),
            ]);
    }
}
