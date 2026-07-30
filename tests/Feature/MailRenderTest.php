<?php

namespace Tests\Feature;

use App\Support\DevMail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Renders EVERY registered mailable (DevMail::previews). Mocked mail never
 * renders, so a template error is otherwise invisible until production. Every
 * new mailable MUST be added to DevMail so it lands here. (This catches TEMPLATE
 * errors — not stale-cache failures; see the accessor-with-fallback rule.)
 */
class MailRenderTest extends TestCase
{
    public function test_all_registered_mailables_render_cleanly(): void
    {
        // Render as they would send in production, so a legitimate absolute app link
        // (e.g. the magic-link) uses the real domain. A template that HARD-CODES a dev
        // host still trips the assertions below.
        config(['app.url' => 'https://mi-club.example']);
        URL::forceRootUrl('https://mi-club.example');

        $previews = DevMail::previews();
        $this->assertNotEmpty($previews, 'There should be at least one registered mailable.');

        foreach ($previews as $key => $mailable) {
            $html = $mailable->render();

            // The logo uses $message->embed(): a real send attaches it as a CID;
            // render()/preview inlines it as a data: URI. Either way it is embedded,
            // never a hot-linked <img src="http…"> that would break for recipients.
            $this->assertStringContainsString('data:image/png;base64,', $html, "[{$key}] should embed its logo inline.");
            $this->assertStringNotContainsString('src="http', $html, "[{$key}] hot-links an image instead of embedding it.");
            $this->assertStringNotContainsString('127.0.0.1', $html, "[{$key}] leaks a dev host URL.");
            $this->assertStringNotContainsString('localhost', $html, "[{$key}] leaks a localhost URL.");
        }
    }
}
