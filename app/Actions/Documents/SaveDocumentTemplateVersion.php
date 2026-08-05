<?php

namespace App\Actions\Documents;

use App\Models\DocumentTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Append a new version of a document template (prompt 16, extracted in the code-style audit). History is never
 * mutated in place: each save creates a NEW row whose version is one past the highest EVER used for that type
 * (trashed rows counted, so a number is never reused), and — when the new version is active — the previously
 * active row of that type is deactivated inside the same transaction, so exactly one version of a type is active.
 * The Filament Create/Edit pages call this; the domain write does not live on the Resource.
 */
class SaveDocumentTemplateVersion
{
    public function handle(string $type, string $body, bool $active): DocumentTemplate
    {
        return DB::transaction(function () use ($type, $body, $active): DocumentTemplate {
            $next = (int) DocumentTemplate::query()->withTrashed()
                ->where('type', $type)->max('version') + 1;

            if ($active) {
                DocumentTemplate::query()->where('type', $type)->where('active', true)
                    ->update(['active' => false]);
            }

            return DocumentTemplate::create([
                'type' => $type,
                'body' => $body,
                'version' => $next,
                'active' => $active,
            ]);
        });
    }
}
