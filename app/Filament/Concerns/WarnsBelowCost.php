<?php

namespace App\Filament\Concerns;

use App\Filament\Support\ReturnFocus;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Schemas\Components\Wizard;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\HtmlString;

/**
 * Prompt 295 (Shane's note 5) — a create page that takes a cost and a sale price asks before saving a price below cost.
 *
 * `App\Support\BelowCost` decides; this only asks. Before the record is written — and, in the wizard, when leaving the *Precio*
 * step, because the cost was entered two steps earlier — a modal lists each affected price beside its cost. *Volver y
 * corregir* (the default) closes it with nothing written and the cursor in the first affected field; *Continuar*
 * carries on as entered. What was confirmed is remembered by its figures, so changing a price or the cost asks again.
 */
trait WarnsBelowCost
{
    /** The figures the owner said *Continuar* to. A warning, not a gate: nothing is protected by this value. */
    public string $belowCostConfirmed = '';

    /**
     * The below-cost prices in the form as it stands now.
     *
     * @return list<array{field: string, price_cents: int, cost_cents: int, line: string}>
     */
    abstract protected function belowCostOffences(): array;

    /** The form field an offence points at (`per_gram`, `per_unit`, `per_eighth`), for the cursor on *Volver y corregir*. */
    abstract protected function belowCostField(string $offence): string;

    protected function beforeCreate(): void
    {
        $this->askIfBelowCost();
    }

    /** Ask, and stop here, unless these figures were already confirmed. `$step`: the wizard step to leave on *Continuar*. */
    public function askIfBelowCost(?int $step = null): void
    {
        $offences = $this->belowCostOffences();

        if ($offences === [] || $this->belowCostConfirmed === md5(serialize($offences))) {
            return;
        }

        $this->mountAction('belowCost', ['step' => $step]);

        throw new Halt;
    }

    public function belowCostAction(): Action
    {
        return Action::make('belowCost')
            ->requiresConfirmation()
            ->color('warning')
            ->modalHeading(__('El precio de venta es menor que el coste'))
            ->modalDescription(fn (): HtmlString => new HtmlString(collect($this->belowCostOffences())
                ->map(fn (array $offence): string => '<span class="block">'.e($offence['line']).'</span>')
                ->implode('')))
            ->modalSubmitActionLabel(__('Continuar'))
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label(__('Volver y corregir'))
                ->extraAttributes(ReturnFocus::to($this->firstField())))
            ->extraModalWindowAttributes(ReturnFocus::listener())
            ->modalAutofocus(false)
            ->action(function (array $arguments): void {
                $this->belowCostConfirmed = md5(serialize($this->belowCostOffences()));

                if (($arguments['step'] ?? null) !== null) {
                    /** @var Wizard|null $wizard */
                    $wizard = $this->getSchema('form')?->getComponent(fn ($component): bool => $component instanceof Wizard, withHidden: true);
                    $wizard?->nextStep((int) $arguments['step']);

                    return;
                }

                $this->create();
            });
    }

    /** The first affected field, where *Volver y corregir* puts the cursor. */
    private function firstField(): string
    {
        $first = $this->belowCostOffences()[0]['field'] ?? null;

        return $first !== null ? $this->belowCostField($first) : '';
    }

    /** A euro amount typed into the form, in cents — or null when the field is empty. */
    protected static function typedCents(mixed $euros): ?int
    {
        return filled($euros) && is_numeric($euros) ? Money::fromEuros((string) $euros)->cents : null;
    }
}
