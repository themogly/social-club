<?php

namespace App\Filament\Resources\Articles\Actions;

use App\Actions\Stock\AddArticleToLocations;
use App\Filament\Resources\Articles\Schemas\ArticleForm;
use App\Filament\Support\AllOption;
use App\Models\Article;
use App\Models\Location;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * *Añadir a otra sede* (prompt 297) — on the product list's row and its edit page. The product, with its own opening
 * stock, at more sedes: a choice of the sedes the user works at that do not already have a product of that name, with
 * *Todas las sedes restantes* first. The copies join the product's group, so edits can be applied to them later.
 */
final class AddToSedesAction
{
    public static function make(): Action
    {
        return Action::make('addToSedes')
            ->label(__('Añadir a otra sede'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading(fn (Article $record): string => __('Añadir «:name» a otra sede', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('Añadir'))
            ->authorize(fn (): bool => Auth::user()?->can('create', Article::class) === true)
            ->visible(fn (Article $record): bool => ! $record->trashed() && self::sedeOptions($record) !== [])
            ->schema(fn (Article $record): array => [
                Select::make('location_id')
                    ->label(__('Sedes'))
                    ->multiple()
                    ->options(fn (): array => count($options = self::sedeOptions($record)) > 1
                        ? [AllOption::KEY => __('Todas las sedes restantes')] + $options
                        : $options)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Select $component, mixed $state, mixed $old, Get $get, Set $set) use ($record): void {
                        $component->state(AllOption::sync((array) $state, (array) $old, array_keys(self::sedeOptions($record))));
                        ArticleForm::zeroNewOpeningStock($get, $set);
                    }),
                Grid::make(['default' => 1, 'sm' => 2])->schema(fn (Get $get): array => collect(AllOption::chosen($get('location_id')))
                    ->map(fn (string $id): TextInput => TextInput::make("stock_at.{$id}")
                        ->label(__('Existencias en :sede', ['sede' => self::sedeOptions($record)[$id] ?? '']))
                        ->numeric()
                        ->minValue(0)
                        ->default(0))
                    ->all()),
            ])
            ->action(function (Article $record, array $data): void {
                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);
                $opening = collect(AllOption::chosen($data['location_id'] ?? []))
                    ->mapWithKeys(fn (string $id): array => [$id => (int) data_get($data, "stock_at.{$id}", 0)])
                    ->all();

                try {
                    $copies = (new AddArticleToLocations)->handle($record, $opening, $actor);
                } catch (DomainException $e) {
                    throw ValidationException::withMessages(['mountedActions.0.data.location_id' => $e->getMessage()]);
                }

                Notification::make()->success()
                    ->title(__('Producto creado en :count sedes', ['count' => $copies->count()]))
                    ->send();
            });
    }

    /**
     * Sedes the user works at where this product is not yet sold under its name.
     *
     * @return array<string, string>
     */
    public static function sedeOptions(Article $record): array
    {
        return collect(Location::assignableOptions())
            ->reject(fn (string $name, string $id): bool => AddArticleToLocations::nameTakenAt($record, $id))
            ->all();
    }
}
