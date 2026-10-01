<?php

namespace App\Console\Commands;

use App\Enums\ProductType;
use App\Models\Article;
use App\Models\Genetic;
use App\Support\VapeLikeName;
use Illuminate\Console\Command;

/**
 * Prompt 347 — what was entered before the strain form made the type a choice: strains whose name looks like a vape but
 * whose type is not *Vapeador*, bar products whose name looks like a cannabis vape, and the strains that are not
 * published (the form no longer has the toggle; existing values were kept). READ-ONLY — it lists, the owner decides.
 */
class FindVapeLike extends Command
{
    protected $signature = 'csc:find-vape-like';

    protected $description = 'List strains and bar products that look like vapes entered with the wrong type, and unpublished strains (read-only)';

    public function handle(): int
    {
        $strains = Genetic::query()->withoutGlobalScopes()->whereNull('deleted_at')->orderBy('name')->get()
            ->filter(fn (Genetic $g): bool => VapeLikeName::strain($g->name) && $g->product_type !== ProductType::VAPE);
        $this->info('Genéticas con nombre de vapeador y otro tipo: '.$strains->count());
        $this->table(['Genética', 'Tipo', 'Organización'], $strains->map(fn (Genetic $g): array => [$g->name, $g->product_type->label(), $g->organisation_id])->all());
        if ($strains->isNotEmpty()) {
            $this->line('Nota: pasar de peso a unidad está restringido si la genética ya tiene existencias o historial. Revísalas una a una.');
        }

        $articles = Article::query()->withoutGlobalScopes()->whereNull('deleted_at')->orderBy('name')->get()
            ->filter(fn (Article $a): bool => VapeLikeName::barProduct($a->name));
        $this->info('Productos de barra que parecen vapeadores con cannabis: '.$articles->count());
        $this->table(['Producto', 'Sede'], $articles->map(fn (Article $a): array => [$a->name, $a->location_id])->all());

        $unpublished = Genetic::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('published', false)->orderBy('name')->get();
        $this->info('No publicadas: '.$unpublished->count());
        $this->table(['Genética', 'Activa'], $unpublished->map(fn (Genetic $g): array => [$g->name, $g->active ? 'sí' : 'no'])->all());

        return self::SUCCESS;
    }
}
