<?php

namespace App\Filament\Resources\MembershipTiers\Schemas;

use App\Enums\MembershipPeriod;
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
                TextInput::make('default_fee_eur')
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

                Textarea::make('benefits')
                    ->label(__('Ventajas'))
                    ->columnSpanFull(),

                Toggle::make('active')
                    ->label(__('Activa'))
                    ->default(true),
            ]);
    }
}
