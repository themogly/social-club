<?php

namespace App\Filament\Resources\Genetics;

use App\Models\Genetic;
use App\Observers\GeneticObserver;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Prompt 308 — the strain page's delete and the list's bulk delete, both ending in `Genetic::delete()`, where the ONE
 * guard lives ({@see GeneticObserver::deleting()}). These only turn its refusal into words: the page
 * says where the stock is; the bulk delete deletes what it can and lists what it skipped, in one notification.
 */
class GeneticDeletion
{
    public static function action(): DeleteAction
    {
        return DeleteAction::make()->action(function (DeleteAction $action, Genetic $record): void {
            try {
                $record->delete();
            } catch (DomainException $e) {
                Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                $action->halt();
            }
            $action->success();
        });
    }

    public static function bulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()->action(function (Collection $records): void {
            $deleted = 0;
            $skipped = [];
            foreach ($records as $genetic) {
                try {
                    $genetic->delete();
                    $deleted++;
                } catch (DomainException $e) {
                    $skipped[] = '<strong>'.e($genetic->name).'</strong>: '.e($e->getMessage());
                }
            }

            $title = trans_choice(':count genética borrada|:count genéticas borradas', $deleted, ['count' => $deleted]);
            if ($skipped === []) {
                Notification::make()->success()->title($title)->send();

                return;
            }
            Notification::make()->warning()->persistent()
                ->title($title.' · '.trans_choice(':count no se ha borrado|:count no se han borrado', count($skipped), ['count' => count($skipped)]))
                ->body(new HtmlString(implode('<br>', $skipped)))
                ->send();
        });
    }
}
