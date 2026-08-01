<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Members\AssignMemberDiscount;
use App\Actions\Members\RemoveMemberDiscount;
use App\Actions\Members\UpdateMemberDiscount;
use App\Actions\Pricing\ResolveArticleDiscount;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountMode;
use App\Models\Discount;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Per-member discount (prompt 08/27/119). A member is given one of the organisation's PRE-MADE discount
 * templates (Dispensario ▸ Descuentos) — CHOSEN from a picker, never re-typed by hand. That link is what
 * lets the grant keep the template's own scope: {@see ResolvePrice} and
 * {@see ResolveArticleDiscount} both honour the discount's `applies_to`
 * (Genéticas / Barra / Ambos) and `category_id`, so a "genetics only" therapeutic rate does NOT leak onto
 * the bar the way a hand-typed 15% did.
 *
 * The hand-typed free-value path was REMOVED from the UI (prompt 119): a new rate is created once as a named,
 * auditable, reusable Discount and then assigned — there is no secondary escape hatch (the owner's ask, and the
 * right default). Legacy inline rows created before this change still price through the resolver and remain
 * visible as `Personalizado`, editable only to change their expiry or be removed — never silently dropped.
 *
 * Owner-only (member.discount.assign, prompt 02); managers and staff never see the tab. Every assign / edit /
 * remove goes through its single-writer Action and is audited (who, from → to, and the captured reason).
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
                // WHICH discount this member has — the template's name, or `Personalizado` for a legacy inline
                // row. (Named "Descuento", never "Tipo": Tipo on the templates list means the discount's KIND.)
                TextColumn::make('discount_name')
                    ->label(__('Descuento'))
                    ->state(fn (MemberDiscount $record): string => $record->discount_id !== null
                        ? (string) $record->discount?->name
                        : __('Personalizado')),
                TextColumn::make('value')
                    ->label(__('Valor'))
                    ->state(fn (MemberDiscount $record): string => $this->formatValue($record)),
                TextColumn::make('expires_at')->label(__('Caduca'))->date('d/m/Y')->placeholder('—'),
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
            ->emptyStateDescription(__('Este socio paga la tarifa estándar. Asigna un descuento predefinido si procede.'));
    }

    /** Assign a pre-made discount to this member (chosen, never hand-typed). */
    protected function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('Asignar descuento'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->schema($this->assignSchema())
            ->action(function (array $data): void {
                $member = $this->getOwnerRecord();
                $actor = Auth::user();
                if (! $member instanceof Member || ! $actor instanceof User) {
                    return;
                }

                (new AssignMemberDiscount)->handle($member, $actor, [
                    'discount_id' => $data['discount_id'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]);

                Notification::make()->title(__('Descuento asignado'))->success()->send();
            });
    }

    /**
     * Edit an existing member discount — only its EXPIRY (audited from → to with a fresh reason). The value
     * belongs to the chosen template (or is frozen on a legacy inline row); to change a rate, remove and
     * reassign a discount, so the value stays named and auditable.
     */
    protected function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('Editar caducidad'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (MemberDiscount $record): array => ['expires_at' => $record->expires_at])
            ->schema($this->editSchema())
            ->action(function (MemberDiscount $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                (new UpdateMemberDiscount)->handle($record, $actor, [
                    'expires_at' => $data['expires_at'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]);

                Notification::make()->title(__('Descuento actualizado'))->success()->send();
            });
    }

    /** Remove a member discount (audited). */
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
     * The assign schema: pick one of the org's active discounts (the value + scope come with it), an optional
     * expiry, and a reason for the audit. No free-value fields — a new rate is a new named Discount (prompt 119).
     *
     * @return list<Component>
     */
    protected function assignSchema(): array
    {
        return [
            Select::make('discount_id')->label(__('Descuento'))
                ->options(fn (): array => $this->discountOptions())
                ->required()
                ->native(false)
                ->searchable()
                ->helperText(__('Elige un descuento predefinido. Se crean en Dispensario ▸ Descuentos.')),
            DatePicker::make('expires_at')->label(__('Caduca (opcional)'))
                ->native(false)->displayFormat('d/m/Y')->placeholder('dd/mm/aaaa')
                ->helperText(__('Deja en blanco para un descuento sin caducidad.')),
            TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255)
                ->helperText(__('Queda registrado en la auditoría.')),
        ];
    }

    /**
     * The edit schema: expiry + reason only. A member discount's value is not editable by hand — it is the
     * template's (or a frozen legacy inline value).
     *
     * @return list<Component>
     */
    protected function editSchema(): array
    {
        return [
            DatePicker::make('expires_at')->label(__('Caduca (opcional)'))
                ->native(false)->displayFormat('d/m/Y')->placeholder('dd/mm/aaaa')
                ->helperText(__('Deja en blanco para un descuento sin caducidad.')),
            TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255)
                ->helperText(__('Queda registrado en la auditoría.')),
        ];
    }

    /**
     * The organisation's ACTIVE discounts, as [id => "Name — value (scope)"]. Scoped to the active org by the
     * Discount model's global scope, so another org's discounts are never offered; inactive ones are filtered.
     *
     * @return array<string, string>
     */
    protected function discountOptions(): array
    {
        return Discount::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Discount $discount): array => [$discount->id => $this->discountLabel($discount)])
            ->all();
    }

    /** "Staff — 10 %", "Therapeutic — 15 % (genéticas)" — name, value and scope, so the choice is unambiguous. */
    private function discountLabel(Discount $discount): string
    {
        $value = $discount->mode === DiscountMode::PERCENT
            ? number_format((int) $discount->value_bp / 100, 0).' %'
            : Money::fromCents((int) $discount->value_cents?->cents)->formatted();

        $scope = match ($discount->applies_to) {
            DiscountAppliesTo::GENETIC => __('genéticas'),
            DiscountAppliesTo::ARTICLE => __('barra'),
            DiscountAppliesTo::BOTH => __('todo'),
        };

        return "{$discount->name} — {$value} ({$scope})";
    }

    private function formatValue(MemberDiscount $record): string
    {
        // Linked template: the value lives on the Discount (value_cents is a Money cast there).
        if ($record->discount_id !== null && $record->discount !== null) {
            $discount = $record->discount;

            return $discount->mode === DiscountMode::PERCENT && $discount->value_bp !== null
                ? '−'.number_format($discount->value_bp / 100, 2).'%'
                : ($discount->value_cents !== null ? '−'.Money::fromCents((int) $discount->value_cents->cents)->formatted() : '—');
        }

        // Legacy inline value (plain int cents on MemberDiscount).
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
