<?php

namespace App\Filament\Resources\Members\Tables;

use App\Enums\MemberKind;
use App\Enums\MemberStatus;
use App\Models\Member;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('member_no')->label(__('Nº socio'))->searchable()->sortable(),
                TextColumn::make('first_name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('last_name')->label(__('Apellidos'))->searchable()->sortable(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (MemberStatus $state): string => match ($state) {
                        MemberStatus::ACTIVE => 'success',
                        MemberStatus::APPLICANT => 'warning',
                        MemberStatus::SUSPENDED, MemberStatus::EXPELLED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('kind')->label(__('Tipo'))->badge()->color('warning')
                    ->state(fn (Member $record): ?string => $record->isTemporary() ? __('Temporal') : null),
                IconColumn::make('is_therapeutic')->label(__('Terapéutico'))->boolean()->toggleable(),
                TextColumn::make('joined_at')->label(__('Alta'))->date()->sortable()->toggleable(),
                TextColumn::make('email')->label(__('Correo'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->label(__('Teléfono'))->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(MemberStatus::cases())
                        ->mapWithKeys(fn (MemberStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                TernaryFilter::make('is_therapeutic')
                    ->label(__('Terapéutico')),
                // The everyday list excludes temporary members by default (kind = STANDARD);
                // switch to Temporal to find them deliberately, or clear to show all (prompt 31).
                SelectFilter::make('kind')
                    ->label(__('Tipo de socio'))
                    ->options(collect(MemberKind::cases())
                        ->mapWithKeys(fn (MemberKind $case): array => [$case->value => $case->label()])
                        ->all())
                    ->default(MemberKind::STANDARD->value),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
