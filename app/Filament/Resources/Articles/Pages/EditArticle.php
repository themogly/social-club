<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Actions\Stock\UpdateArticleAcrossSedes;
use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Articles\Actions\AddToSedesAction;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\AllOption;
use App\Models\Article;
use App\Models\User;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditArticle extends EditRecord
{
    use AuditsResourceChanges;
    use ReturnsToList;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AddToSedesAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // A base-price (or any) change to a bar article is audited (prompt 48) — the price everyone pays.
    protected function beforeSave(): void
    {
        $this->captureAuditDiff();
    }

    protected function afterSave(): void
    {
        $this->writeAuditLog('article.updated');
    }

    /**
     * Seed the virtual euro field from the stored integer cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Article $article */
        $article = $this->getRecord();
        $data['price_eur'] = $article->price_cents->cents / 100;

        // A Money cast object cannot live in Livewire form state — the virtual euro
        // field carries the value instead.
        unset($data['price_cents']);

        return $data;
    }

    /**
     * Convert the edited euros back to integer cents in price_cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['price_cents'] = (int) round_half_up(((float) ($data['price_eur'] ?? 0)) * 100);
        unset($data['price_eur']);

        return $data;
    }

    /**
     * Prompt 297 — the save, a sede correction and the ticked sedes of the group, in one transaction
     * ({@see UpdateArticleAcrossSedes}). A refused move (the product has history, or a sale landed while the form was
     * open) is an error on the Sede field, and nothing is saved.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Article $record */
        $siblings = AllOption::chosen($data['apply_to'] ?? []);
        $locationId = is_string($data['location_id'] ?? null) ? $data['location_id'] : null;
        unset($data['apply_to'], $data['location_id']);

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        try {
            return (new UpdateArticleAcrossSedes)->handle($record, $data, $locationId, $siblings, $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.location_id' => $e->getMessage()]);
        }
    }
}
