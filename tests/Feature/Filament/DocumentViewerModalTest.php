<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use Filament\Actions\Action;
use Tests\TestCase;

/**
 * Prompt 252 — admin documents and signatures open in a MODAL, never a new tab.
 *
 * The three openers (the member-documents resource, the member form's per-type "Ver documento", and the
 * consents relation manager's "Ver firma") issued the same short-lived signed URL and then `window.open`ed or
 * `openUrlInNewTab`ed it — a hidden tab. They now show it in a Filament modal: an <img> for images, an <iframe>
 * for PDFs with a SAME-TAB "Abrir en el visor" fallback. The signed URL and its lifetime are unchanged.
 */
class DocumentViewerModalTest extends TestCase
{
    /** @return list<string> */
    private function openerSources(): array
    {
        return array_map(fn (string $p): string => (string) file_get_contents(base_path($p)), [
            'app/Filament/Resources/MemberDocuments/MemberDocumentResource.php',
            'app/Filament/Resources/Members/Schemas/MemberForm.php',
            'app/Filament/Resources/Members/RelationManagers/ConsentsRelationManager.php',
        ]);
    }

    public function test_the_three_openers_use_the_shared_modal_viewer_not_a_new_tab(): void
    {
        foreach ($this->openerSources() as $source) {
            $this->assertStringContainsString('->modalContent(', $source, 'an opener no longer shows the document in a modal');
            $this->assertStringContainsString('filament.documents.viewer', $source, 'an opener does not use the shared viewer');
            $this->assertStringNotContainsString('window.open(', $source);
            $this->assertStringNotContainsString('openUrlInNewTab(', $source);
        }

        $this->assertFileExists(resource_path('views/filament/documents/viewer.blade.php'));
    }

    public function test_the_view_action_is_a_modal_action_not_a_url(): void
    {
        $action = MemberDocumentResource::viewDocumentAction();

        $this->assertInstanceOf(Action::class, $action);
        // A URL action would open a tab; a modal action carries no static URL.
        $this->assertNull($action->getUrl());
    }

    public function test_the_viewer_renders_an_image_inline_and_a_pdf_in_a_same_tab_iframe(): void
    {
        $image = view('filament.documents.viewer', ['url' => 'https://vault.test/sig.png', 'isPdf' => false])->render();
        $this->assertStringContainsString('<img', $image);
        $this->assertStringNotContainsString('<iframe', $image);
        $this->assertStringContainsString('https://vault.test/sig.png', $image);

        $pdf = view('filament.documents.viewer', ['url' => 'https://vault.test/doc.pdf', 'isPdf' => true])->render();
        $this->assertStringContainsString('<iframe', $pdf);
        $this->assertStringContainsString(__('Abrir en el visor'), $pdf);
        $this->assertStringNotContainsString('target="_blank"', $pdf, 'the PDF fallback opens a new tab');
    }
}
