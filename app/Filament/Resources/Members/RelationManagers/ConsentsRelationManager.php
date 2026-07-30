<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\ConsentRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The member's consent history — one row per consent per version (consent is never a
 * scalar). Read-only: consents are captured at approval / withdrawal through the domain
 * actions, never edited here. Cannabis-consumption and therapeutic status are Article 9
 * special-category data, so their lawful basis rests on this explicit, versioned record.
 */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Consentimientos');
    }

    public function table(Table $table): Table
    {
        return $table
            // Captured through ApproveApplication / withdrawal — never created or edited here.
            ->defaultSort('granted_at', 'desc')
            ->columns([
                TextColumn::make('purpose')->label(__('Finalidad'))->wrap(),
                TextColumn::make('consent_text_version')->label(__('Versión'))->badge(),
                TextColumn::make('granted_at')->label(__('Otorgado'))->dateTime()->sortable(),
                TextColumn::make('withdrawn_at')->label(__('Retirado'))->dateTime()->placeholder('—')->sortable(),
                IconColumn::make('active')
                    ->label(__('Vigente'))
                    ->state(fn (ConsentRecord $record): bool => $record->withdrawn_at === null)
                    ->boolean(),
                TextColumn::make('ip')->label(__('IP'))->placeholder('—')->toggleable(),
            ]);
    }
}
