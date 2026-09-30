<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Actions\Stock\IntakeArticleAtLocations;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Support\AllOption;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateArticle extends CreateRecord
{
    use ReturnsToList;

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

    /** How many sedes the product was just created at (prompt 297), for the notification. */
    public int $createdAt = 0;

    /**
     * Opening stock enters through the LEDGER (prompt 104) — exactly as IntakeBatch does for batches. The
     * article is created EMPTY, then its opening balance is an INTAKE movement through the single stock writer,
     * atomically, so every article reconciles (sum of qty_units movements == stock) instead of the ledger
     * holding only depletions and summing negative. Zero opening stock writes no spurious movement.
     *
     * Prompt 297 — one product per sede ticked, each with its own opening stock, all or nothing
     * ({@see IntakeArticleAtLocations}); created together, they share a `group_id`.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $sedes = AllOption::chosen($data['location_id'] ?? null);
        $opening = count($sedes) === 1
            ? [$sedes[0] => (int) ($data['stock'] ?? 0)]
            : collect($sedes)->mapWithKeys(fn (string $id): array => [$id => (int) data_get($data, "stock_at.{$id}", 0)])->all();
        unset($data['stock'], $data['stock_at'], $data['location_id']);

        // The Sede field makes a missing sede a form error first; the model guard (prompt 294) is the backstop, shown
        // on the same field rather than as a 500.
        if ($opening === []) {
            throw ValidationException::withMessages(['data.location_id' => __('No se puede crear sin sede: elige una sede.')]);
        }

        try {
            $articles = (new IntakeArticleAtLocations)->handle($data, $opening, ['operator_id' => Auth::id()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.location_id' => $e->getMessage()]);
        }
        $this->createdAt = $articles->count();

        return $articles->first();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->createdAt > 1 ? __('Producto creado en :count sedes', ['count' => $this->createdAt]) : parent::getCreatedNotificationTitle();
    }
}
