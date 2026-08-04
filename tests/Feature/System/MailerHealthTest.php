<?php

namespace Tests\Feature\System;

use App\ViewModels\SystemHealth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Prompt 145 — production mail must actually work, and a missing credential must not fail silently. Two
 * things are proven here: the first-party Resend transport can be CONSTRUCTED (the SDK is installed), and the
 * health check reports a credential-needing mailer whose key is absent — the failure this branch existed to
 * make visible. No real email is ever sent.
 */
class MailerHealthTest extends TestCase
{
    public function test_the_resend_transport_resolves_when_a_key_is_present(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 'test-resend-key']);

        // Building the mailer constructs the Resend transport — this needs resend/resend-php installed and
        // would throw if the SDK were merely suggested. It does not send anything.
        $mailer = Mail::mailer('resend');

        $this->assertNotNull($mailer);
    }

    public function test_the_health_check_flags_resend_without_a_key(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => null]);

        $mailer = (new SystemHealth)->mailer();

        $this->assertSame('resend', $mailer['mailer']);
        $this->assertTrue($mailer['needs_credential']);
        $this->assertFalse($mailer['configured']);
    }

    public function test_resend_with_a_key_reports_configured(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_a_real_looking_key']);

        $mailer = (new SystemHealth)->mailer();

        $this->assertTrue($mailer['needs_credential']);
        $this->assertTrue($mailer['configured']);
    }

    public function test_the_log_mailer_needs_no_credential_and_never_false_alarms(): void
    {
        config(['mail.default' => 'log']);

        $mailer = (new SystemHealth)->mailer();

        $this->assertFalse($mailer['needs_credential']);
        $this->assertTrue($mailer['configured']);
    }
}
