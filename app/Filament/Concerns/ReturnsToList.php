<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;

/**
 * Prompt 334 — Ben: "When you edit, like a tier or something, it stays on the edit page. There should be a link to view
 * all, and it should be on all pages." Used by EVERY create, edit and view page under `app/Filament/Resources`
 * (`ReturnsToListTest` walks them and refuses a page without it):
 *
 *  - **Saving returns to the list** — after an edit and after a create (*Crear y crear otro* still stays on a blank form),
 *    with Filament's own notification. The list keeps its saved filters (308). A page with a deliberate target of its own
 *    declares `getRedirectUrl()` and that wins (the acta opens on its page to be completed and signed).
 *  - **The hubs** — an edit page whose resource has relation managers (a member's memberships, a strain's prices…) also
 *    gets *Guardar y seguir editando*, which saves and stays, so the tabs below are one step away.
 *  - **The way back** — a *← {the list's name}* header action, FIRST, secondary, at the 44 px floor, to the list. A dirty
 *    create or edit form asks before leaving: the panel's `unsavedChangesAlerts()`.
 */
trait ReturnsToList
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /** The way back, first among the header actions whatever the page declares. */
    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();

        $back = $this->backToListAction();
        $this->cacheAction($back);
        array_unshift($this->cachedHeaderActions, $back);
    }

    protected function backToListAction(): Action
    {
        $resource = static::getResource();

        return Action::make('backToList')
            ->label($resource::getTitleCasePluralModelLabel())
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->size(Size::Large)
            ->url($resource::getUrl('index'))
            ->visible(fn (): bool => $resource::canViewAny())
            ->extraAttributes(['class' => 'min-h-11', 'data-back-to-list' => 'true']);
    }

    /**
     * Filament's own form actions, composed from its named builders (a View page has none) — plus, on a hub's edit page,
     * *Guardar y seguir editando* after *Guardar*.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        if ($this instanceof EditRecord) {
            return [
                $this->getSaveFormAction(),
                ...(static::getResource()::getRelations() !== [] ? [
                    Action::make('saveAndKeepEditing')->label(__('Guardar y seguir editando'))->color('gray')->action('saveAndKeepEditing'),
                ] : []),
                $this->getCancelFormAction(),
            ];
        }

        if ($this instanceof CreateRecord) {
            return [
                $this->getCreateFormAction(),
                ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
                $this->getCancelFormAction(),
            ];
        }

        return [];
    }

    /** A hub's second save: the same save, staying on the record (its relation managers are right below). */
    public function saveAndKeepEditing(): void
    {
        if ($this instanceof EditRecord) {
            $this->save(shouldRedirect: false);
        }
    }
}
