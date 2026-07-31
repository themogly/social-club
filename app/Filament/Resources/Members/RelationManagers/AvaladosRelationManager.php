<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Enums\MemberStatus;
use App\Models\Member;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Prompt 51 — the members this socio sponsors (avala). Read-only; the aval relationship is set on the
 * sponsored member's own record. Lets a manager see, from a sponsor, who they vouched for.
 */
class AvaladosRelationManager extends RelationManager
{
    protected static string $relationship = 'avalados';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Avalados');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('member_no')->label(__('Nº socio'))->searchable(),
                TextColumn::make('full_name')
                    ->label(__('Nombre'))
                    ->state(fn (Member $record): string => $record->fullName()),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (MemberStatus $state): string => match ($state) {
                        MemberStatus::ACTIVE => 'success',
                        MemberStatus::APPLICANT => 'warning',
                        MemberStatus::SUSPENDED, MemberStatus::EXPELLED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->emptyStateHeading(__('Sin avalados'))
            ->emptyStateDescription(__('Este socio no avala a ningún otro socio.'));
    }
}
