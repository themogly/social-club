<?php

namespace App\Filament\Pages;

use App\ViewModels\Rat as RatRegistry;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RAT — Registro de Actividades de Tratamiento (RGPD Art. 30). Rendered live FROM the
 * system (App\ViewModels\Rat): controller identity from the Organisation, retention from
 * Settings, data categories from the real model surface — never a hand-typed PDF. Cannabis
 * consumption + therapeutic status are flagged as Article 9 special-category data.
 * Gated on `audit.view` (owner). Page + PDF export; nothing here is legal advice.
 */
class Rat extends Page
{
    protected string $view = 'filament.pages.rat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'rat';

    public static function getNavigationLabel(): string
    {
        return __('RAT — Registro de tratamientos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Registro de Actividades de Tratamiento');
    }

    public function getSubheading(): ?string
    {
        return __('Artículo 30 RGPD. Generado a partir de los datos del sistema. No constituye asesoramiento legal.');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('audit.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rat = new RatRegistry;

        return [
            'controller' => $rat->controller(),
            'activities' => $rat->activities(),
            'security' => $rat->securityMeasures(),
            'hasArticle9' => $rat->hasArticle9(),
            'generatedAt' => $rat->generatedAt(),
        ];
    }

    public function exportPdf(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $rat = new RatRegistry;
        $content = Pdf::loadView('documents.rat', [
            'controller' => $rat->controller(),
            'activities' => $rat->activities(),
            'security' => $rat->securityMeasures(),
            'generatedAt' => $rat->generatedAt(),
            'orgName' => (string) config('app.name'),
        ])->output();

        return response()->streamDownload(fn () => print ($content), 'rat-'.now()->format('Ymd').'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
