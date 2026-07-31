<?php

namespace Tests\Feature\AuditLog;

use App\Enums\MemberStatus;
use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Member;
use App\Models\TillSession;
use App\Support\AuditFieldFormatter;
use App\Support\AuditFieldLabeler;
use App\Support\Money;
use App\Support\Weight;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Prompt 40 — the audit viewer renders formatted, plain-language changes (money in euros,
 * weight in grams, enum labels, dates, Yes/No) using the fields' OWN Filament labels, never
 * raw cents/cg/JSON or snake_case column names. One generic mechanism, verified across model
 * types and on the null-auditable settings path.
 */
class AuditLogFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        AuditFieldLabeler::flush();
        AuditFieldFormatter::flush();
    }

    /** Render the real (private) diff HTML for an audit record — the production render path. */
    private function diff(AuditLog $record): string
    {
        $method = new ReflectionMethod(AuditLogInfolist::class, 'diffHtml');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $record);
    }

    public function test_till_close_cents_render_as_euros_with_plain_labels(): void
    {
        // The reported bug: expected_cents/counted_cents shown as raw JSON cents.
        $this->assertSame(Money::fromCents(1000)->formatted(), AuditFieldFormatter::format(TillSession::class, 'expected_cents', 1000));
        $this->assertSame(Money::fromCents(1000)->formatted(), AuditFieldFormatter::format(TillSession::class, 'counted_cents', 1000));
        $this->assertSame(Money::fromCents(0)->formatted(), AuditFieldFormatter::format(TillSession::class, 'variance_cents', 0));

        // Labels come from the till resource, not the raw column names.
        $this->assertSame(__('Esperado'), AuditFieldLabeler::label(TillSession::class, 'expected_cents'));
        $this->assertSame(__('Contado'), AuditFieldLabeler::label(TillSession::class, 'counted_cents'));
        $this->assertSame(__('Diferencia'), AuditFieldLabeler::label(TillSession::class, 'variance_cents'));

        // End-to-end through the diff: formatted euros + plain labels, no raw "1000" as the value.
        $html = $this->diff(new AuditLog([
            'auditable_type' => TillSession::class,
            'before' => ['status' => 'OPEN', 'counted_cents' => null, 'variance_cents' => null],
            'after' => ['status' => 'CLOSED', 'counted_cents' => 1000, 'variance_cents' => 0],
        ]));
        $this->assertStringContainsString(__('Contado'), $html);
        $this->assertStringContainsString(Money::fromCents(1000)->formatted(), $html); // €10.00
        $this->assertStringContainsString(__('Diferencia'), $html);
        $this->assertStringContainsString(Money::fromCents(0)->formatted(), $html);
    }

    public function test_a_cg_field_not_weightcast_backed_renders_as_grams_matching_the_infolist(): void
    {
        // declared_monthly_cg is cast plain `integer` on the model — a cast-first formatter would
        // print the raw integer. The suffix rule renders grams, identical to what MemberInfolist shows.
        $expected = Weight::fromCentigrams(350)->formatted();

        $this->assertSame($expected, AuditFieldFormatter::format(Member::class, 'declared_monthly_cg', 350));
        $this->assertNotSame('350', AuditFieldFormatter::format(Member::class, 'declared_monthly_cg', 350));
        $this->assertSame(__('Previsión mensual (g)'), AuditFieldLabeler::label(Member::class, 'declared_monthly_cg'));
    }

    public function test_an_enum_field_renders_its_translated_label_not_the_backing_value(): void
    {
        $this->assertSame(MemberStatus::ACTIVE->getLabel(), AuditFieldFormatter::format(Member::class, 'status', 'ACTIVE'));
        $this->assertNotSame('ACTIVE', AuditFieldFormatter::format(Member::class, 'status', 'ACTIVE'));
        $this->assertSame(__('Estado'), AuditFieldLabeler::label(Member::class, 'status'));
    }

    public function test_settings_update_null_model_resolves_labels_and_keeps_display_units(): void
    {
        // Labels resolve from ManageSettings::form() even though $auditable is null.
        $this->assertSame(__('Edad mínima'), AuditFieldLabeler::label(null, 'min_age'));
        $this->assertSame(__('Límite de deuda (€)'), AuditFieldLabeler::label(null, 'wallet_debt_limit_eur'));

        // The *_eur / *_g settings keys are ALREADY display units — never re-divided by 100.
        $this->assertSame('50', AuditFieldFormatter::format(null, 'wallet_debt_limit_eur', 50.0));
        $this->assertSame('30', AuditFieldFormatter::format(null, 'daily_limit_g', 30.0));

        // Non-money settings pass through untouched, and the null model never crashes.
        $this->assertSame('CSC', AuditFieldFormatter::format(null, 'member_number_prefix', 'CSC'));

        $html = $this->diff(new AuditLog([
            'auditable_type' => null,
            'before' => ['min_age' => 18, 'wallet_debt_limit_eur' => 50.0],
            'after' => ['min_age' => 21, 'wallet_debt_limit_eur' => 75.0],
        ]));
        $this->assertStringContainsString(__('Edad mínima'), $html);
        $this->assertStringContainsString(__('Límite de deuda (€)'), $html);
    }

    public function test_the_raw_json_view_is_present_but_collapsed_by_default(): void
    {
        $html = $this->diff(new AuditLog([
            'auditable_type' => Member::class,
            'before' => ['status' => 'ACTIVE'],
            'after' => ['status' => 'SUSPENDED'],
        ]));

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString(__('Ver datos sin procesar'), $html);
        // Collapsed: no `open` attribute on the <details>.
        $this->assertStringNotContainsString('<details open', $html);
        $this->assertStringNotContainsString('<details style="margin-top:1rem;" open', $html);
    }

    public function test_the_mechanism_generalises_across_model_types(): void
    {
        // Till close → euros + till labels.
        $till = $this->diff(new AuditLog([
            'auditable_type' => TillSession::class,
            'before' => ['counted_cents' => 5000],
            'after' => ['counted_cents' => 5500],
        ]));
        $this->assertStringContainsString(__('Contado'), $till);
        $this->assertStringContainsString(Money::fromCents(5500)->formatted(), $till);

        // Member status transition → enum labels + "Estado".
        $member = $this->diff(new AuditLog([
            'auditable_type' => Member::class,
            'before' => ['status' => 'ACTIVE'],
            'after' => ['status' => 'SUSPENDED'],
        ]));
        $this->assertStringContainsString(__('Estado'), $member);
        $this->assertStringContainsString(MemberStatus::ACTIVE->getLabel(), $member);

        // Expense amount change → euros + "Amount".
        $expense = $this->diff(new AuditLog([
            'auditable_type' => Expense::class,
            'before' => ['amount_cents' => 5000],
            'after' => ['amount_cents' => 7500],
        ]));
        $this->assertSame(__('Importe'), AuditFieldLabeler::label(Expense::class, 'amount_cents'));
        $this->assertStringContainsString(Money::fromCents(7500)->formatted(), $expense);
    }

    public function test_an_unlabeled_field_falls_back_to_headline_and_is_logged_once(): void
    {
        Log::spy();

        // A synthetic key (like members.imported's summary) with no home anywhere.
        $first = AuditFieldLabeler::label(Member::class, 'totally_made_up_field');
        $second = AuditFieldLabeler::label(Member::class, 'totally_made_up_field');

        $this->assertSame('Totally Made Up Field', $first);   // Str::headline fallback
        $this->assertSame($first, $second);

        // Logged exactly once per (model, field) per request, not once per call.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Audit log: no label found for field'
                && $context['field'] === 'totally_made_up_field'
                && $context['model'] === Member::class);
    }
}
