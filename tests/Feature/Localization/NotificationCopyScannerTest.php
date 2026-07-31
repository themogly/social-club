<?php

namespace Tests\Feature\Localization;

use App\Support\NotificationCopyScanner;
use Tests\TestCase;

/**
 * The second, complementary localization gate (prompt 25). Prompt 19's key-parity test
 * structurally cannot catch a string that never became a key — this scan can. Wired into
 * `composer check` by living in the suite: the first test fails the build if any raw
 * natural-language literal is handed straight to a notification/alert sink.
 */
class NotificationCopyScannerTest extends TestCase
{
    public function test_no_alert_or_notification_copy_is_hardcoded_in_the_app(): void
    {
        $violations = NotificationCopyScanner::violations();

        $this->assertSame([], $violations, 'Hardcoded alert/notification copy (wrap in __()/trans_choice()): '.implode(' | ', array_map(
            fn (array $v): string => basename($v['file']).':'.$v['line'].' ->'.$v['method'].'("'.$v['literal'].'")',
            $violations,
        )));
    }

    public function test_the_scanner_catches_a_reintroduced_hardcoded_string(): void
    {
        // The exact regression this prompt exists to prevent: a raw sentence handed
        // straight to a notification title and a counter flash, bypassing __().
        $bad = <<<'PHP'
<?php
Notification::make()->title('Solicitud aprobada correctamente')->send();
$this->flash('No se pudo registrar la dispensación', 'error');
PHP;

        $violations = NotificationCopyScanner::scan($bad);

        $this->assertCount(2, $violations);
        $this->assertSame(['title', 'flash'], array_column($violations, 'method'));
        $this->assertSame('Solicitud aprobada correctamente', $violations[0]['literal']);
    }

    public function test_the_scanner_does_not_flag_translated_or_dynamic_copy(): void
    {
        // Every legitimate form must pass: __() / trans_choice() wrappers, an exception
        // message variable, a bare accessor, a session flash key (no space), a
        // parameterized __(), and a single-word label.
        $good = <<<'PHP'
<?php
Notification::make()->title(__('Guardado'))->body($e->getMessage())->send();
$this->flash(trans_choice(':count socio|:count socios', $n), 'ok');
$response->body();
session()->flash('status', $x);
Notification::make()->title(__('Efectivo :cash', ['cash' => $c]))->send();
$field->title('PIN');
PHP;

        $this->assertSame([], NotificationCopyScanner::scan($good));
    }
}
