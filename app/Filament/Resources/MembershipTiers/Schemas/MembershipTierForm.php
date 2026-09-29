<?php

namespace App\Filament\Resources\MembershipTiers\Schemas;

use App\Enums\MembershipPeriod;
use App\Filament\Forms\DecimalInput;
use App\Support\Settings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MembershipTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),

                // Virtual euro field: the model stores integer cents in
                // default_fee_cents. The Create/Edit pages convert euros ↔ cents
                // (mutateFormDataBeforeCreate/Save and mutateFormDataBeforeFill).
                DecimalInput::make('default_fee_eur')
                    ->label(__('Cuota (€)'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),

                Select::make('default_period')
                    ->label(__('Periodo'))
                    ->options(collect(MembershipPeriod::cases())
                        ->mapWithKeys(fn (MembershipPeriod $case): array => [$case->value => $case->label()])
                        ->all())
                    ->required(),

                // Virtual gram fields: the model stores integer centigrams in
                // daily_limit_cg / monthly_limit_cg (nullable per-tier overrides of the
                // organisation limits). The Create/Edit pages convert grams ↔ centigrams.
                DecimalInput::make('daily_limit_g')
                    ->hint(fn (): ?string => Settings::limitsEnabled() ? null : __('Límites desactivados en Ajustes')) // kept, not cleared (296)
                    ->label(__('Límite diario (g)'))
                    ->helperText(__('Opcional. Sustituye el límite diario de la organización.'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),

                DecimalInput::make('monthly_limit_g')
                    ->hint(fn (): ?string => Settings::limitsEnabled() ? null : __('Límites desactivados en Ajustes')) // kept, not cleared (296)
                    ->label(__('Techo mensual (g)'))
                    ->helperText(__('Opcional. Sustituye el techo mensual de la organización.'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),

                // Prompt 278 (owner decision 1a) — prices live on the batch, so a tier's price is a % DISCOUNT on any
                // batch. It competes with the member's other discounts; the best single one applies.
                DecimalInput::make('discount_pct')
                    ->label(__('Descuento de la tarifa (%)'))
                    ->helperText(__('Opcional. Se descuenta del precio de cualquier lote a los socios de esta tarifa; si tienen otro descuento, se aplica el mejor.'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100),

                Textarea::make('benefits')
                    ->label(__('Ventajas'))
                    ->columnSpanFull(),

                Toggle::make('active')
                    ->label(__('Activa'))
                    ->default(true),
            ]);
    }
}
