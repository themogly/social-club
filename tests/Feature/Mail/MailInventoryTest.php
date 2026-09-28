<?php

namespace Tests\Feature\Mail;

use App\Mail\ClubMail;
use App\Mail\ExampleClubMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Prompt 288 — the guard, so "every email the system promises is actually sent" cannot drift again.
 *
 * 287's defect was a counter that said "Invitación enviada" with no send behind it; 288's were an approval path that
 * never sent the approved email, three emails in the worker's language instead of the recipient's, and no retry. Each
 * check below would have caught one of them.
 */
class MailInventoryTest extends TestCase
{
    /** @return list<class-string> */
    private function mailables(): array
    {
        $classes = [];
        foreach ((new Finder)->files()->in(app_path('Mail'))->name('*.php') as $file) {
            $class = 'App\\Mail\\'.$file->getBasename('.php');
            if ($class !== ClubMail::class && $class !== ExampleClubMail::class) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @return array<string, string> path => source, for app/ minus app/Mail and the dev preview */
    private function productionSources(): array
    {
        $sources = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php')->notPath('Mail') as $file) {
            if ($file->getBasename() === 'DevMail.php') {
                continue;
            }
            // Code only: a docblock that says "Mail::to()->queue()" is prose, not a send.
            $code = '';
            foreach (token_get_all((string) file_get_contents((string) $file->getRealPath())) as $token) {
                $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
            }
            $sources[$file->getRealPath()] = $code;
        }

        return $sources;
    }

    public function test_every_mailable_has_a_real_sender(): void
    {
        $sources = implode("\n", $this->productionSources());

        foreach ($this->mailables() as $class) {
            $short = class_basename($class);
            $this->assertMatchesRegularExpression('/new '.$short.'\(|'.$short.'::from/', $sources,
                "{$class} is queued by nothing outside app/Mail and the dev preview — dead code, or a bug like 287's.");
        }
    }

    public function test_every_mailable_is_a_club_mail_with_retries_and_after_commit(): void
    {
        $this->assertTrue(is_subclass_of(ClubMail::class, ShouldQueueAfterCommit::class));

        foreach ($this->mailables() as $class) {
            $this->assertTrue(is_subclass_of($class, ClubMail::class), "{$class} must extend ClubMail (retries, after-commit, failure audit).");
        }

        $probe = new class extends ClubMail {};
        $this->assertSame(4, $probe->tries);
        $this->assertSame([30, 120, 600], $probe->backoff());
        $this->assertTrue(method_exists($probe, 'failed'));
    }

    public function test_every_send_is_queued_with_the_recipients_language(): void
    {
        foreach ($this->productionSources() as $path => $source) {
            if (! preg_match_all('/Mail::to\((?:[^;]|\n)*?;/', $source, $calls)) {
                continue;
            }
            foreach ($calls[0] as $call) {
                if (! str_contains($call, '->queue(')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/->locale\((?:[^;])*->queue\(/s', $call,
                    basename($path).": a Mail::to()->queue() without ->locale() renders in the worker's language.\n{$call}");
            }
        }
    }

    /**
     * Every UI string claiming an email went out, with the send that makes it true. A new claim fails this test until
     * someone adds it here with its sender — that is how 287's "Invitación enviada" would have been caught.
     */
    public function test_no_message_claims_an_email_it_did_not_send(): void
    {
        $known = [
            'Invitación enviada a :email. Puede darse de alta y firmar desde su móvil.' => 'SendApplicationInvite (ApplicationInviteMail)',
            'Invitación reenviada a :email.' => 'SendApplicationInvite (ApplicationInviteMail)',
            'Invitación reenviada (en cola)' => 'SendApplicationInvite (ApplicationInviteMail)',
            'Invitación creada y email en cola' => 'SendApplicationInvite (ApplicationInviteMail)',
            'Comprobante enviado al socio (en cola).' => 'DispensaryPos::emailReceipt (DispensationReceiptMail)',
            'Si tu correo está registrado, te hemos enviado un enlace de acceso.' => 'IssueMemberLoginLink (MemberLoginLinkMail)',
            'Cerrará el club entero de inmediato. Solo se reactiva desde el enlace enviado a los propietarios, por el plazo automático o por línea de comandos.' => 'InitiateLockdown (LockdownReactivationMail)',
            'Se reactiva desde el enlace enviado a los propietarios, por el plazo automático o por línea de comandos. No desde aquí.' => 'InitiateLockdown (LockdownReactivationMail)',
            'Tu número de socio/a es :no. Recibirás tu carné con el código QR en un correo aparte.' => 'ApproveApplication (MemberCardMail, after ApplicationApprovedMail)',
            '¡Gracias! Hemos recibido tu solicitud. La asociación la revisará y, si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte. La revisión puede tardar unos días.' => 'ApproveApplication (MemberCardMail)',
            'Qué ocurre después: la asociación revisará tu solicitud. Si se aprueba, recibirás por correo tu tarjeta de socio/a con un código QR para identificarte en la sede. La revisión puede tardar unos días.' => 'ApproveApplication (MemberCardMail)',
        ];

        $found = [];
        $finder = (new Finder)->files()->in([app_path(), resource_path('views')])->name(['*.php']);
        foreach ($finder as $file) {
            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/u", (string) file_get_contents((string) $file->getRealPath()), $m);
            foreach ($m[1] as $string) {
                $string = stripslashes($string);
                if (preg_match('/enviad[oa] (a|al|por)|te hemos enviado|enviamos|recibirás|te llegará|email en cola|\(en cola\)/iu', $string)) {
                    $found[$string] = true;
                }
            }
        }

        foreach (array_keys($found) as $string) {
            $this->assertArrayHasKey($string, $known, "A message claims an email was sent, with no known sender behind it: «{$string}». Add it here with the send that makes it true.");
        }
    }
}
