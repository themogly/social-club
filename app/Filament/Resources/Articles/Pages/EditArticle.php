<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\AuditsResourceChanges;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    use AuditsResourceChanges;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
}
