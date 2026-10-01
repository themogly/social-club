<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * Prompt 344 — a labelled section inside a header ⋯ group. Filament draws each nested `->dropdown(false)` group as its
 * own list between dividers, but with no title. This is that title: a heading row (never clickable — `toHtml()` draws a
 * plain line, there is no `action()`), shown only while at least one action in its section is visible, so a section a
 * role cannot use disappears whole rather than leaving a title over nothing.
 *
 * Usage: `MenuSection::more([MenuSection::of(__('Datos'), [...]), MenuSection::of(__('Eliminar'), [...], danger: true)])`.
 */
class MenuSection extends Action
{
    protected bool $danger = false;

    /**
     * @param  array<Action>  $actions
     */
    public static function of(string $label, array $actions, bool $danger = false): ActionGroup
    {
        $heading = static::make('menuSection'.Str::studly(Str::slug($label)))
            ->label($label)
            // isHiddenInGroup(), not isVisible(): the latter asks the enclosing group, which asks this heading — a loop.
            ->visible(fn (): bool => collect($actions)->contains(fn (Action $action): bool => ! $action->isHiddenInGroup()));
        $heading->danger = $danger;

        return ActionGroup::make([$heading, ...$actions])->dropdown(false);
    }

    /**
     * The one *Más acciones* button: secondary, at the 44 px floor, opening under its right edge with a height that
     * scrolls inside the viewport (340's batch rule) — so on a phone the whole list is on screen.
     *
     * @param  array<Action|ActionGroup>  $sections
     */
    public static function more(array $sections): ActionGroup
    {
        return ActionGroup::make($sections)
            ->label(__('Más acciones'))
            ->icon(Heroicon::OutlinedEllipsisHorizontal)
            ->color('gray')
            ->button()
            ->dropdownPlacement('bottom-end')
            ->dropdownWidth(Width::ExtraSmall) // 20 rem: «Solicitar supresión (RGPD)» whole, and still inside a 360 px screen
            ->dropdownMaxHeight('min(32rem, 70dvh)')
            ->extraAttributes(['class' => 'min-h-11', 'data-more-actions' => 'true']);
    }

    public function toHtml(): string
    {
        return '<div role="presentation" data-menu-section="'.e($this->getName()).'" class="fi-menu-section-heading'
            .($this->danger ? ' fi-menu-section-heading-danger' : '').'">'.e((string) $this->getLabel()).'</div>';
    }
}
