<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\Money;
use App\Support\VaultUrl;
use App\Support\Weight;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class MemberInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Resumen'))
                    ->schema([
                        // The encrypted photo is shown via the signed, access-logged endpoint (prompt 113),
                        // NOT a raw disk URL — so viewing the member's file logs the view, like a document.
                        ImageEntry::make('photo')
                            ->label(__('Foto'))
                            // Null-guarded: label introspection (AuditFieldLabeler) builds this schema with no record.
                            ->state(fn (?Member $record): ?string => $record instanceof Member && ($u = Auth::user()) instanceof User ? VaultUrl::photo($record, $u) : null)
                            ->visible(fn (?Member $record): bool => $record instanceof Member && filled($record->photo_path))
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
                            ->label(__('Previsión mensual (g)'))
                            ->formatStateUsing(fn (?int $state): ?string => filled($state)
                                ? Weight::fromCentigrams($state)->formatted()
                                : null),

                        // Wallet balance (all locations) — live from the ledger, never stored.
                        TextEntry::make('wallet_balance')
                            ->label(__('Saldo del monedero'))
                            ->state(fn (Member $record): string => Money::fromCents(
                                (int) $record->walletTransactions()->withoutGlobalScopes()->sum('amount_cents')
                            )->formatted())
                            ->helperText(__('Suma de todas las sedes.')),

                        // Consumption limits gauge — the SAME figures ResolveMemberLimits feeds the POS/PWA.
                        TextEntry::make('limits')
                            ->label(__('Límites de consumo'))
                            ->state(fn (Member $record): string => self::limitsSummary($record)),
                    ])
                    ->columns(2),
            ]);
    }

    private static function limitsSummary(Member $member): string
    {
        $location = self::gaugeLocation($member);
        if ($location === null) {
            return __('Sin sede activa');
        }

        $snapshot = (new ResolveMemberLimits)->handle($member, $location);

        return __('Diario: :du / :dl · Mensual: :mu / :ml (:pct%)', [
            'du' => Weight::fromCentigrams($snapshot->dailyUsedCg)->formatted(),
            'dl' => Weight::fromCentigrams($snapshot->dailyLimitCg)->formatted(),
            'mu' => Weight::fromCentigrams($snapshot->monthlyUsedCg)->formatted(),
            'ml' => Weight::fromCentigrams($snapshot->monthlyLimitCg)->formatted(),
            'pct' => $snapshot->monthlyPercent(),
        ]);
    }

    /** The sede to resolve limits against: the active location, else the member's latest active membership. */
    private static function gaugeLocation(Member $member): ?Location
    {
        $locationId = app(ActiveScope::class)->locationId()
            ?? $member->memberships()->withoutGlobalScopes()
                ->where('status', MembershipStatus::ACTIVE->value)
                ->latest('id')->value('location_id');

        return $locationId !== null
            ? Location::query()->withoutGlobalScopes()->find($locationId)
            : null;
    }
}
