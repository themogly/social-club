<?php

namespace App\Filament\Resources\MemberApplications\Schemas;

use App\Enums\ApplicationStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MemberApplicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('location_id')
                    ->label(__('Sede'))
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('status')
                    ->label(__('Estado'))
                    ->options(collect(ApplicationStatus::cases())
                        ->mapWithKeys(fn (ApplicationStatus $case): array => [$case->value => $case->label()])
                        ->all())
                    ->required(),

                Textarea::make('reject_reason')
                    ->label(__('Motivo del rechazo'))
                    ->columnSpanFull(),
            ]);
    }
}
