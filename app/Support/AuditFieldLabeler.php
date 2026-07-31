<?php

namespace App\Support;

use App\Filament\Pages\ManageSettings;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves the plain-language label for one audit-log field by REUSING the labels already
 * defined on the field's Filament resource — Table, then Infolist, then Form (first exact
 * name match wins) — so there is no second label registry to drift (prompt 40). Settings
 * (`settings.updated`, a null auditable) resolves from `ManageSettings::form()`. A field with
 * no home anywhere (e.g. `members.imported`'s synthetic summary keys) falls back to
 * Str::headline() and is logged once per (model, field) so a real label can be added later.
 */
class AuditFieldLabeler
{
    /** @var array<string, class-string>|null model FQCN → resource class */
    private static ?array $resourceMap = null;

    /** @var array<string, array<string, string>> cache key → (field → label) */
    private static array $labelCache = [];

    /** @var array<string, true> dedupe of "no label" warnings per (model, field) per request */
    private static array $warned = [];

    public static function label(?string $modelClass, string $key): string
    {
        $labels = self::labelsFor($modelClass);

        if (isset($labels[$key]) && $labels[$key] !== '') {
            return $labels[$key];
        }

        self::warnOnce($modelClass, $key);

        return Str::headline($key);
    }

    /** @return array<string, string> */
    private static function labelsFor(?string $modelClass): array
    {
        $cacheKey = $modelClass ?? '__settings__';

        return self::$labelCache[$cacheKey] ??= $modelClass === null
            ? self::settingsLabels()
            : self::resourceLabels($modelClass);
    }

    /** @return array<string, string> */
    private static function settingsLabels(): array
    {
        try {
            $page = new ManageSettings;

            return self::schemaLabels($page->form(Schema::make($page)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private static function resourceLabels(string $modelClass): array
    {
        $resource = self::resourceMap()[$modelClass] ?? null;

        if ($resource === null) {
            return [];
        }

        // The schema/table builders need a live owner (Schema::make(null) throws in getLivewire()).
        // The resource's own List page is both HasTable and HasSchemas, and its mount/boot never runs.
        $owner = self::listPage($resource);

        if ($owner === null) {
            return [];
        }

        // Precedence via array union (left wins): Table, then Infolist fills gaps, then Form.
        // Table/Infolist columns tend to carry the literal DB column; a Form often uses an
        // edge-transformed virtual field name (amount_eur, not amount_cents) instead.
        return self::tableLabels($resource, $owner)
            + self::safe(fn () => self::schemaLabels($resource::infolist(Schema::make($owner))))
            + self::safe(fn () => self::schemaLabels($resource::form(Schema::make($owner))));
    }

    /**
     * @return array<string, string>
     */
    private static function tableLabels(string $resource, ListRecords $owner): array
    {
        return self::safe(function () use ($resource, $owner): array {
            $labels = [];

            foreach ($resource::table(Table::make($owner))->getColumns() as $column) {
                $name = $column->getName();
                $label = self::labelString($column->getLabel());

                if (is_string($name) && $name !== '' && $label !== null && ! isset($labels[$name])) {
                    $labels[$name] = $label;
                }
            }

            return $labels;
        });
    }

    /**
     * @return array<string, string>
     */
    private static function schemaLabels(Schema $schema): array
    {
        $labels = [];

        foreach ($schema->getFlatComponents() as $component) {
            if (! method_exists($component, 'getName') || ! method_exists($component, 'getLabel')) {
                continue;
            }

            $name = $component->getName();
            $label = self::labelString($component->getLabel());

            if (is_string($name) && $name !== '' && $label !== null && ! isset($labels[$name])) {
                $labels[$name] = $label;
            }
        }

        return $labels;
    }

    private static function listPage(string $resource): ?ListRecords
    {
        foreach ($resource::getPages() as $registration) {
            $pageClass = $registration->getPage();

            if (is_a($pageClass, ListRecords::class, true)) {
                /** @var ListRecords */
                return new $pageClass;
            }
        }

        return null;
    }

    /** @return array<string, class-string> */
    private static function resourceMap(): array
    {
        if (self::$resourceMap !== null) {
            return self::$resourceMap;
        }

        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('admin');
        $map = [];

        foreach ($panel->getResources() as $resource) {
            $map[$resource::getModel()] = $resource;
        }

        return self::$resourceMap = $map;
    }

    private static function labelString(mixed $label): ?string
    {
        if ($label instanceof Htmlable) {
            $label = $label->toHtml();
        }

        return is_string($label) && trim($label) !== '' ? $label : null;
    }

    private static function warnOnce(?string $modelClass, string $key): void
    {
        $signature = ($modelClass ?? '__settings__').'::'.$key;

        if (isset(self::$warned[$signature])) {
            return;
        }

        self::$warned[$signature] = true;

        Log::warning('Audit log: no label found for field', [
            'model' => $modelClass,
            'field' => $key,
        ]);
    }

    /**
     * @param  callable(): array<string, string>  $callback
     * @return array<string, string>
     */
    private static function safe(callable $callback): array
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Test hook: drop the per-request memoisation (resource map, label cache, warn dedupe). */
    public static function flush(): void
    {
        self::$resourceMap = null;
        self::$labelCache = [];
        self::$warned = [];
    }
}
