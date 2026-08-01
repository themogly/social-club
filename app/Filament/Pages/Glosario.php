<?php

namespace App\Filament\Pages;

use App\Support\Help;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The glossary of the club's terms of art (prompt 92) — the single highest-value piece of help, because
 * the vocabulary is legally load-bearing and not guessable (aportación vs venta). Content-only, static and
 * searchable (Alpine, no queries), reachable by everyone with panel access. Terms stay Spanish; the page
 * explains them. Nothing here changes behaviour.
 */
class Glosario extends Page
{
    protected string $view = 'filament.pages.glosario';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'ayuda/glosario';

    public static function getNavigationLabel(): string
    {
        return __('Glosario');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Ayuda');
    }

    public function getTitle(): string
    {
        return __('Glosario');
    }

    public function getSubheading(): ?string
    {
        return __('Los términos que usa el club. Se mantienen en español porque son términos con valor legal; aquí se explican.');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'terms' => Help::glossary(),
            // The per-screen guides (layer 2/5): what each screen is for, collected into the help index.
            'guides' => array_values(Help::TOPICS),
        ];
    }
}
