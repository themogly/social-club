<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Members\RemoveMemberDiscount;
use App\Actions\Members\UpdateMemberDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\DiscountMode;
use App\Models\Member;
use App\Models\MemberDiscount;
use App\Models\User;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Per-member custom discount (prompt 08/27). The org-wide discount TEMPLATES live in
 * their own resource (Dispensario ▸ Descuentos); this tab assigns a bespoke value to
 * ONE member, which {@see ResolvePrice} already consumes. It does
 * NOT add a second pricing path — it only sets the value the resolver reads.
 *
 * Owner-only (member.discount.assign, prompt 02). Custom per-member discounts are
 * GLOBAL (they apply to every genetic for that member) — matching how ResolvePrice
 * evaluates an inline member discount; category-scoping stays a property of the org
 * templates. Every assign/edit/remove writes an audit entry (who, what, from → to,
 * plus the captured reason).
 */
class DiscountsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberDiscounts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Descuentos');
    }

    /** Owner-only. Managers and staff never see the tab (denial covered by a test). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('member.discount.assign') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->state(fn (MemberDiscount $record): string => $record->discount_id !== null
                        ? (string) $record->discount->name
                        : __('Personalizado')),
                TextColumn::make('value')
                    ->label(__('Descuento'))
                    ->state(fn (MemberDiscount $record): string => $this->formatValue($record)),
                TextColumn::make('expires_at')->label(__('Caduca'))->date()->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->state(fn (MemberDiscount $record): string => $this->isExpired($record) ? __('Caducado') : __('Activo'))
                    ->color(fn (MemberDiscount $record): string => $this->isExpired($record) ? 'danger' : 'success'),
                TextColumn::make('assignedBy.name')->label(__('Asignado por'))->placeholder('—'),
            ])
            ->headerActions([$this->assignAction()])
            ->recordActions([$this->editAction(), $this->removeAction()])
            ->emptyStateHeading(__('Sin descuentos personalizados'))
            ->emptyStateDescription(__('Este socio paga la tarifa estándar. Asigna un descuento personalizado si procede.'));
    }

    /** Assign a bespoke discount to this member. */
    protected function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Asignar descuento'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->schema($this->formSchema())
            ->action(function (array $data): void {
                $member = $this->getOwnerRecord();
                $actor = Auth::user();
                if (! $member instanceof Member || ! $actor instanceof User) {
                    return;
                }

                [$mode, $bp, $cents] = $this->resolveValue($data);

                (new AssignMemberDiscount)->handle($member, $actor, [
                    'mode' => $mode,
                    'value_bp' => $bp,
                    'value_cents' => $cents,
                    'expires_at' => $data['expires_at'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]);

                Notification::make()->title(__('Descuento asignado'))->success()->send();
            });
    }

    /** Edit an existing custom discount (audited from → to with a fresh reason). */
    protected function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('Editar'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (MemberDiscount $record): array => [
                'mode' => $record->mode?->value,
                'value_pct' => $record->value_bp !== null ? $record->value_bp / 100 : null,
                'value_eur' => $record->value_cents !== null ? $record->value_cents / 100 : null,
                'expires_at' => $record->expires_at,
            ])
            ->schema($this->formSchema())
            ->action(function (MemberDiscount $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                [$mode, $bp, $cents] = $this->resolveValue($data);

                (new UpdateMemberDiscount)->handle($record, $actor, [
                    'mode' => $mode,
                    'value_bp' => $bp,
                    'value_cents' => $cents,
                    'expires_at' => $data['expires_at'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]);

                Notification::make()->title(__('Descuento actualizado'))->success()->send();
            });
    }

    /** Remove a custom discount (audited). */
    protected function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('Quitar'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->schema([
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255)
                    ->helperText(__('Queda registrado en la auditoría.')),
            ])
            ->action(function (MemberDiscount $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                (new RemoveMemberDiscount)->handle($record, $actor, $data['reason'] ?? null);

                Notification::make()->title(__('Descuento retirado'))->success()->send();
            });
    }

    /**
     * The shared assign/edit schema. Both value fields are shown with helper text (as
     * the org discount form does); only the one matching the chosen mode is required.
     *
     * @return list<Component>
     */
    protected function formSchema(): array
    {
        return [
            Select::make('mode')->label(__('Tipo'))
                ->options([
                    DiscountMode::PERCENT->value => __('Porcentaje'),
                    DiscountMode::FIXED->value => __('Importe fijo'),
                ])
                ->required()
                ->live(),
            TextInput::make('value_pct')->label(__('Porcentaje (%)'))
                ->numeric()->minValue(0)->maxValue(100)
                ->required(fn (Get $get): bool => $get('mode') === DiscountMode::PERCENT->value)
                ->helperText(__('Sólo si el tipo es porcentaje.')),
            TextInput::make('value_eur')->label(__('Importe (€)'))
                ->numeric()->minValue(0)
                ->required(fn (Get $get): bool => $get('mode') === DiscountMode::FIXED->value)
                ->helperText(__('Sólo si el tipo es importe fijo.')),
            DatePicker::make('expires_at')->label(__('Caduca (opcional)'))
                ->helperText(__('Deja en blanco para un descuento sin caducidad.')),
            TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255)
                ->helperText(__('Queda registrado en la auditoría.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: DiscountMode, 1: ?int, 2: ?int} mode, value_bp, value_cents
     */
    private function resolveValue(array $data): array
    {
        $mode = DiscountMode::from((string) $data['mode']);

        return $mode === DiscountMode::PERCENT
            ? [$mode, (int) round_half_up(((float) ($data['value_pct'] ?? 0)) * 100), null]
            : [$mode, null, (int) round_half_up(((float) ($data['value_eur'] ?? 0)) * 100)];
    }

    private function formatValue(MemberDiscount $record): string
    {
        if ($record->mode === DiscountMode::PERCENT && $record->value_bp !== null) {
            return '−'.number_format($record->value_bp / 100, 2).'%';
        }

        if ($record->value_cents !== null) {
            return '−'.Money::fromCents((int) $record->value_cents)->formatted();
        }

        return '—';
    }

    private function isExpired(MemberDiscount $record): bool
    {
        return $record->expires_at !== null && $record->expires_at->isPast();
    }
}
