<?php

namespace App\Filament\Resources\Discounts\Schemas;

use App\Enums\CategoryAppliesTo;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use App\Models\Discount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Nombre'))->required()->maxLength(255),

            Select::make('kind')->label(__('Tipo'))
                ->options(collect(DiscountKind::cases())->mapWithKeys(fn (DiscountKind $c) => [$c->value => $c->label()])->all())
                ->required(),

            // PERCENTAGE ONLY (prompt 168). `mode` is no longer a question. It was one of the two
            // placeholder selects that made the create button do nothing, a fixed amount was never
            // supported on the article path (prompt 55), and on the genetics path it was actively
            // WRONG: ResolvePrice ranked candidates by what each saves on ONE GRAM's rate while
            // PriceResult applied the winner to the WHOLE subtotal, so a €3 fixed beat a 10% on a
            // 10 g / €100 order and the member was charged €7 more. A one-option dropdown would be
            // the same mistake as showing two money fields at once, so there is no dropdown at all —
            // the mode is set by CreateDiscount.
            TextInput::make('value_pct')
                ->label(__('Porcentaje (%)'))
                ->numeric()
                // A discount worth nothing is not a discount. One used to be creatable by leaving this
                // blank (normalise() cast the missing value through `(float) null`) or by typing 0 —
                // active, assignable, on the templates list, and silently taking nothing off anything.
                ->required()
                ->minValue(0.01)
                ->maxValue(100)
                ->step(0.01)
                // Hidden on a legacy fixed-amount row: the form cannot express one, and a required
                // percentage box would make such a row impossible to edit at all. EditDiscount
                // round-trips its value untouched instead.
                ->visible(fn (?Discount $record): bool => $record === null || $record->mode === DiscountMode::PERCENT)
                ->helperText(__('Entre 0,01 y 100. Un descuento del 0 % no es un descuento.')),

            // "Se aplica a" is no longer asked either — the owner's instruction is that discounts apply
            // to flower. The COLUMN, the enum and both resolvers are untouched, so existing rows keep
            // their scope and the bar keeps discounting; see DECISIONS for the cost of that choice.
            Select::make('category_id')
                ->label(__('Categoría (opcional)'))
                // Filtered to GENETIC categories. Unfiltered it offered bar categories too, so a
                // flower-only discount could be pointed at one, match nothing, and still look configured.
                ->relationship(
                    'category',
                    'name',
                    fn (Builder $query): Builder => $query->where('applies_to', CategoryAppliesTo::GENETIC->value),
                )
                ->searchable()
                ->preload(),

            Select::make('locations')->label(__('Sedes donde aplica'))->relationship('locations', 'name')->multiple()->preload(),

            Toggle::make('active')->label(__('Activo'))->default(true),
        ]);
    }

    /** The only mode a discount can now be authored in. */
    public static function mode(): DiscountMode
    {
        return DiscountMode::PERCENT;
    }

    /** The only scope a discount can now be authored with. */
    public static function appliesTo(): DiscountAppliesTo
    {
        return DiscountAppliesTo::GENETIC;
    }
}
