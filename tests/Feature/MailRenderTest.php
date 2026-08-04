<?php

namespace Tests\Feature;

use App\Mail\ExampleClubMail;
use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\DevMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Renders EVERY registered mailable (DevMail::previews) as it would actually SEND (via the array transport, so
 * both the HTML and the plain-text part are built exactly as a recipient receives them). Mocked mail never
 * renders, so a template error is otherwise invisible until production. Every new mailable MUST be added to
 * DevMail so it lands here.
 *
 * Prompt 150: every member-facing email is branded with the CLUB (its name/logo via OrganisationIdentity),
 * never the product ("CSC platform"), and every one carries a plain-text alternative part (multipart/alternative).
 */
class MailRenderTest extends TestCase
{
    use RefreshDatabase;

    private function activeClub(string $name, ?string $logoPath = null): Organisation
    {
        $org = Organisation::factory()->create(['name' => $name, 'logo_path' => $logoPath]);
        app(ActiveScope::class)->setOrganisation($org->id);

        return $org;
    }

    public function test_every_mailable_is_club_branded_with_html_and_text_parts(): void
    {
        // Render as they would send in production, so a legitimate absolute app link uses the real domain.
        config(['app.url' => 'https://mi-club.example']);
        URL::forceRootUrl('https://mi-club.example');
        $this->activeClub('Asociación Verde');

        $mailer = Mail::mailer('array');
        $transport = $mailer->getSymfonyTransport();

        $previews = DevMail::previews();
        $this->assertNotEmpty($previews, 'There should be at least one registered mailable.');

        foreach ($previews as $key => $mailable) {
            $mailer->to('socia@example.test')->send($mailable);
            $email = $transport->messages()->last()->getOriginalMessage();
            $html = (string) $email->getHtmlBody();
            $text = (string) $email->getTextBody();

            // Branded with the CLUB — its name is present, and the product name never leaks (prompt 150).
            $this->assertStringContainsString('Asociación Verde', $html, "[{$key}] HTML must carry the club name.");
            $this->assertStringNotContainsString('CSC platform', $html, "[{$key}] HTML leaks the product name.");

            // A plain-text alternative part exists (multipart/alternative) and is itself club-branded.
            $this->assertNotSame('', trim($text), "[{$key}] must have a plain-text alternative part.");
            $this->assertStringContainsString('Asociación Verde', $text, "[{$key}] text part must carry the club name.");
            $this->assertStringNotContainsString('CSC platform', $text, "[{$key}] text part leaks the product name.");

            // Never a hot-linked image or a dev host.
            $this->assertStringNotContainsString('src="http', $html, "[{$key}] hot-links an image instead of embedding it.");
            $this->assertStringNotContainsString('127.0.0.1', $html, "[{$key}] leaks a dev host URL.");
            $this->assertStringNotContainsString('localhost', $html, "[{$key}] leaks a localhost URL.");
        }
    }

    public function test_an_uploaded_club_logo_is_embedded_by_cid_never_hot_linked(): void
    {
        Storage::fake('public');
        // A 1×1 PNG stands in for the club's uploaded logo.
        Storage::disk('public')->put('org/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMEAQBLmpX7AAAAAElFTkSuQmCC'
        ));
        $this->activeClub('Asociación Verde', 'org/logo.png');

        $mailer = Mail::mailer('array');
        $mailer->to('socia@example.test')->send(new ExampleClubMail('María García'));
        $html = (string) $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();

        // A real send attaches the logo and references it by CID — never a data: URI or a hot-linked URL.
        $this->assertStringContainsString('cid:', $html, 'An uploaded club logo must be CID-embedded.');
        $this->assertStringNotContainsString('src="http', $html);
    }
}
