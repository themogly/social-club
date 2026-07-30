<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Enums\MemberStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Resumen'))
                    ->schema([
                        ImageEntry::make('photo_path')
                            ->label(__('Foto'))
                            ->disk('documents')
                            ->visibility('private')
                            ->circular(),

                        TextEntry::make('member_no')
                            ->label(__('Nº de socio')),

                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (MemberStatus $state): string => match ($state) {
                                MemberStatus::ACTIVE => 'success',
                                MemberStatus::APPLICANT => 'warning',
                                MemberStatus::SUSPENDED, MemberStatus::EXPELLED => 'danger',
                                default => 'gray',
                            }),

                        IconEntry::make('is_therapeutic')
                            ->label(__('Terapéutico'))
                            ->boolean(),

                        TextEntry::make('joined_at')
                            ->label(__('Alta'))
                            ->date(),

                        TextEntry::make('carencia_ends_at')
                            ->label(__('Fin de carencia'))
                            ->date(),

                        TextEntry::make('declared_monthly_cg')
                            ->label(__('Previsión mensual (cg)')),
                    ])
                    ->columns(2),
            ]);
    }
}
