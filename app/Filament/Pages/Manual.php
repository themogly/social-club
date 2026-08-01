<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Help;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The club manual (prompt 99) — the help hub that teaches the tasks people actually do (the five GUIDES)
 * and says what every screen is for (the TOPICS), filtered to what the reader's ROLE can act on. Content
 * only, static, no queries: a member of staff never sees the settings or RGPD material; the owner sees
 * everything. The eighth-pricing example is computed live through ResolvePrice, so the number shown is the
 * number the till charges. The glossary of terms lives on its own page (Glosario), linked from here.
 */
class Manual extends Page
{
    protected string $view = 'filament.pages.manual';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 0; // above the glossary

    protected static ?string $slug = 'ayuda/manual';

    public static function getNavigationLabel(): string
    {
        return __('Manual');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Ayuda');
    }

    public function getTitle(): string
    {
        return __('Manual');
    }

    public function getSubheading(): ?string
    {
        return __('Cómo se hacen las tareas del club y para qué sirve cada pantalla. Solo ves lo que tu rol puede hacer.');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        return [
            'guides' => Help::guidesVisibleTo($user),
            'topics' => Help::topicsVisibleTo($user),
            'eighthExample' => Help::eighthExample(),
        ];
    }
}
