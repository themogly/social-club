<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * Money lives as integer cents in price_cents; the form edits euros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['price_cents'] = (int) round_half_up(((float) ($data['price_eur'] ?? 0)) * 100);
        unset($data['price_eur']);

        return $data;
    }
}
