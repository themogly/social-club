<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\ConsentRecord;
use App\Models\User;
use App\Support\VaultUrl;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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
                // HOW the consent was captured (prompts 210 + 220). Without this the table shows a version and
                // a date for three materially different things: the person's own tick, a paper form a member of
                // staff attested, and a signature drawn on screen. The channel is the whole distinction.
                TextColumn::make('channel')->label(__('Vía'))
                    ->badge()
                    ->formatStateUsing(fn (ConsentRecord $record): string => $record->channel->label())
                    ->color(fn (ConsentRecord $record): string => $record->channel->isApplicantsOwnAct() ? 'success' : 'warning'),
                TextColumn::make('attestedBy.name')->label(__('Registrado por'))->placeholder('—')->toggleable(),
                TextColumn::make('ip')->label(__('IP'))->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                // A signed consent is only evidence if somebody can produce the signature (prompt 220). Short-
                // lived, user-bound, access-logged signed URL — never a path, never a public file.
                Action::make('signature')
                    ->label(__('Ver firma'))
                    ->icon('heroicon-m-pencil-square')
                    ->visible(fn (ConsentRecord $record): bool => $record->isSigned())
                    ->url(fn (ConsentRecord $record): ?string => ($user = Auth::user()) instanceof User
                        ? VaultUrl::consentSignature($record, $user)
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading(__('Sin consentimientos registrados'))
            ->emptyStateDescription(__('Los consentimientos RGPD del socio se capturan al aprobar su alta y aparecerán aquí.'));
    }
}
