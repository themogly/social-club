<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prompt 288 — does mail actually work on this box? Sends ONE plain message SYNCHRONOUSLY through the configured
 * mailer and prints "Enviado" or the transport's own error. Club mail is queued, so a broken transport otherwise fails
 * silently in the worker; this is the one tool a person needs on a live server. The address is not recorded anywhere.
 */
class MailTest extends Command
{
    protected $signature = 'csc:mail-test {email : Where to send the test message}';

    protected $description = 'Send one test email synchronously through the configured mailer and report the result';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $mailer = (string) config('mail.default');

        try {
            Mail::raw(__('Mensaje de prueba de :app. Si lo recibes, el correo funciona.', ['app' => config('app.name')]), function ($message) use ($email): void {
                $message->to($email)->subject(__('Prueba de correo'));
            });
        } catch (Throwable $e) {
            $this->error(__('No enviado (:mailer): :error', ['mailer' => $mailer, 'error' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->info(__('Enviado (:mailer).', ['mailer' => $mailer]));

        return self::SUCCESS;
    }
}
