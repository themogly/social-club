<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpensePaidFrom;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * OVERHEADS ONLY. The amount is edited in euros and converted to integer cents on
 * the Create/Edit pages via Money; paid_from deliberately omits TILL_CASH (that is
 * petty cash, recorded at the counter). A frequency turns the row into a recurring
 * TEMPLATE. The receipt lives on the PRIVATE `documents` disk, never public.
 */
class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Gasto general'))
                    ->description(__('Gastos pagados fuera de la caja (banco, tarjeta u otros). La caja chica se registra en el mostrador.'))
                    ->schema([
                        Select::make('category_id')
                            ->label(__('Categoría'))
                            ->relationship('category', 'name', fn (Builder $query): Builder => $query->where('active', true))
                            ->searchable()
                            ->preload()
                            ->required(),

                        // TILL_CASH is deliberately ABSENT: drawer spend is petty cash, recorded at the
                        // counter (RecordTillExpense → PETTY_CASH movement) so the arqueo reconciles.
                        // CASH here is cash that NEVER touched the till (supplier on delivery, rent, a
                        // tradesman, owner's pocket) — money out, no drawer implication (prompt 67).
                        Select::make('paid_from')
                            ->label(__('Pagado desde'))
                            ->options([
                                ExpensePaidFrom::CASH->value => __('Efectivo (fuera de caja)'),
                                ExpensePaidFrom::BANK->value => __('Banco'),
                                ExpensePaidFrom::CARD->value => __('Tarjeta'),
                                ExpensePaidFrom::OTHER->value => __('Otro'),
                            ])
                            ->default(ExpensePaidFrom::CASH->value) // the club's normal case is cash (owner)
                            ->required(),

                        TextInput::make('amount_eur')
                            ->label(__('Importe (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        DatePicker::make('incurred_on')
                            ->label(__('Fecha'))
                            ->default(now())
                            ->required(),

                        Select::make('supplier_id')
                            ->label(__('Proveedor'))
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload(),

                        Select::make('location_id')
                            ->label(__('Sede'))
                            ->relationship('location', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText(__('Opcional. Un gasto general puede no estar ligado a una sede.')),

                        Select::make('recurrence_frequency')
                            ->label(__('Frecuencia (opcional)'))
                            ->options([
                                'MONTHLY' => __('Mensual'),
                                'QUARTERLY' => __('Trimestral'),
                                'YEARLY' => __('Anual'),
                            ])
                            ->helperText(__('Con una frecuencia, se crea una plantilla recurrente en vez de un gasto puntual.'))
                            // Recurrence is a create-time decision — a concrete expense never becomes a template.
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        Textarea::make('note')
                            ->label(__('Nota'))
                            ->columnSpanFull(),

                        FileUpload::make('receipt_path')
                            ->label(__('Justificante'))
                            ->disk('documents')
                            ->visibility('private')
                            ->columnSpanFull(),

                        Placeholder::make('staff_payment_notice')
                            ->label(__('Aviso sobre pagos de personal'))
                            ->content(__('Registrar aquí un pago de personal NO salda la obligación real (nómina, IRPF, Seguridad Social ni gobernanza): el tesorero debe tramitarla por separado. Este registro solo la documenta.'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
