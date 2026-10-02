<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\Guides\Guide;
use App\Support\Guides\GuideLibrary;
use App\Support\Guides\GuideRenderer;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Prompt 353 — one guide in the panel, opened from *Ayuda → Manual → Guías*. The same page as on the counter and a staff
 * phone ({@see GuideRenderer}); a managers' guide is a 404 for anyone who may not read it. Not in the navigation: the
 * Manual lists the guides.
 */
class Guia extends Page
{
    protected string $view = 'filament.pages.guia';

    protected static ?string $slug = 'ayuda/guia';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'g')]
    public string $guide = '';

    private ?Guide $resolved = null;

    public function mount(): void
    {
        $this->resolve();
    }

    public function getTitle(): string
    {
        return $this->resolve()->title;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $guide = $this->resolve();

        return ['page' => ['guide' => $guide] + GuideRenderer::render($guide, fn (string $file): string => GuideLibrary::imageUrl($guide, $file))];
    }

    private function resolve(): Guide
    {
        if ($this->resolved === null) {
            $user = auth()->user();
            $guide = GuideLibrary::find($this->guide);
            abort_if($guide === null || ! GuideLibrary::canRead($user instanceof User ? $user : null, $guide), 404);
            $this->resolved = $guide;
        }

        return $this->resolved;
    }
}
