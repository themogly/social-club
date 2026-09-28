<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A query builder that refuses mass updates and deletes (prompt 281) — for append-only records, where a model event alone
 * would not stop `Model::query()->update()`. Inserts and reads are untouched.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        throw new RuntimeException('This record is append-only — no mass update.');
    }

    public function delete()
    {
        throw new RuntimeException('This record is append-only — no mass delete.');
    }

    public function forceDelete()
    {
        throw new RuntimeException('This record is append-only — no mass delete.');
    }
}
