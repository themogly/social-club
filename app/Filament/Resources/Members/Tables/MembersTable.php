<?php

namespace App\Filament\Resources\Members\Tables;

use App\Enums\MemberKind;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Scopes\LocationScope;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Wallet balance is a DERIVED column — sum it in ONE aggregate subquery (across all
            // locations), never a per-row query, so the list stays fast on thousands of members.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum(
                ['walletTransactions' => fn (Builder $sub): Builder => $sub->withoutGlobalScope(LocationScope::class)],
                'amount_cents',
            ))
            ->columns([
                TextColumn::make('member_no')->label(__('Nº socio'))->searchable()->sortable(),
                // Derived (prompt 93): a member with no active membership cannot be dispensed to. Never stored.
                TextColumn::make('membership_gap')
                    ->label(__('Alta'))
                    ->badge()
                    ->state(fn (Member $record): string => $record->hasActiveMembership() ? __('Completa') : __('Sin membresía'))
                    ->color(fn (Member $record): string => $record->hasActiveMembership() ? 'success' : 'warning')
                    ->tooltip(fn (Member $record): ?string => $record->hasActiveMembership() ? null : __('Sin una membresía activa no se le puede dispensar. Enrola una cuota.')),
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
                // ->placeholder: Filament wraps every cell of a clickable row in an <a>, so a cell that
                // renders NOTHING becomes a link with no accessible text — ten of them per page, which is
                // what a screen reader hears on this table (a11y audit). A placeholder gives the link
                // content, and an em dash is the ordinary table convention for "no value" besides.
                TextColumn::make('kind')->label(__('Tipo'))->badge()->color('warning')->placeholder('—')
                    ->state(fn (Member $record): ?string => $record->isTemporary() ? __('Temporal') : null),
                IconColumn::make('is_therapeutic')->label(__('Terapéutico'))->boolean()->toggleable(),
                // Prompt 72: DERIVED — a warning badge (only) when a generated declaration no longer matches
                // the member's declared forecast. Toggleable + hidden by default: computing it loads each
                // member's documents, so it is opt-in for an audit rather than an N+1 on the main list.
                // Prompt 85: DERIVED "card not sent" — no email to send to, or none issued yet. Toggleable +
                // hidden by default (it queries each member's tokens), so staff can find cardless members.
                TextColumn::make('card_missing')
                    ->label(__('Carné QR'))
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'warning')
                    ->state(fn (Member $record): ?string => ! $record->cardMissing()
                        ? null
                        : (blank($record->email) ? __('Sin correo') : __('Pendiente')))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('declaration_stale')
                    ->label(__('Declaración'))
                    ->badge()
                    ->placeholder('—')
                    ->color('warning')
                    ->state(fn (Member $record): ?string => $record->hasStaleDeclaration() ? __('Desactualizada') : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                // Prompt 131: DERIVED — a member imported off the paper register carries no RGPD consent until it
                // is collected. Flags exactly who to chase. Toggleable + hidden by default (it queries each
                // member's consents), so it is opt-in for a consent sweep rather than an N+1 on the main list.
                TextColumn::make('consent_pending')
                    ->label(__('Consentimiento'))
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'warning')
                    ->state(fn (Member $record): ?string => $record->hasConsent() ? null : __('Pendiente'))
                    ->tooltip(fn (Member $record): ?string => $record->hasConsent() ? null : __('Sin consentimiento RGPD registrado. Recoge y registra el consentimiento del socio.'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('joined_at')->label(__('Alta'))->date()->sortable()->toggleable(),
                TextColumn::make('wallet_transactions_sum_amount_cents')
                    ->label(__('Monedero'))
                    ->state(fn (Member $record): string => Money::fromCents((int) $record->getAttribute('wallet_transactions_sum_amount_cents'))->formatted())
                    ->toggleable(),
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
                // Members with an ACTIVE membership at the chosen sede (a socio is org-wide, so this
                // narrows by where they're currently enrolled).
                SelectFilter::make('location')
                    ->label(__('Sede'))
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('memberships', fn (Builder $sub): Builder => $sub
                            ->where('location_id', $data['value'])
                            ->where('status', MembershipStatus::ACTIVE->value))
                        : $query),
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
            // One overflow trigger instead of two labelled buttons (prompt 170). The actions column
            // is the LAST column, so it is the first thing to fall off a narrow viewport — and at 1440
            // this table had 17px of headroom, meaning the next action added anywhere would push it off
            // for everyone. ~150px becomes ~50px; Ver/Editar stay one tap away.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin socios'))
            ->emptyStateDescription(__('El libro de socios está vacío. Da de alta a la primera persona, o invítala para que rellene sus datos desde su móvil.'));
    }
}
