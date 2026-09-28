<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Actions\Stock\IntakeArticle;
use App\Filament\Resources\Articles\ArticleResource;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

    /**
     * Opening stock enters through the LEDGER (prompt 104) — exactly as IntakeBatch does for batches. The
     * article is created EMPTY, then its opening balance is an INTAKE movement through the single stock writer,
     * atomically, so every article reconciles (sum of qty_units movements == stock) instead of the ledger
     * holding only depletions and summing negative. Zero opening stock writes no spurious movement.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $openingStock = (int) ($data['stock'] ?? 0);
        unset($data['stock']);

        // The Sede field makes a missing sede a form error first; the model guard (prompt 294) is the backstop, shown
        // on the same field rather than as a 500.
        try {
            return (new IntakeArticle)->handle($data, $openingStock, ['operator_id' => Auth::id()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.location_id' => $e->getMessage()]);
        }
    }

    /** Back to the list (prompt 295, Shane's note on the catalogue's create pages). */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
