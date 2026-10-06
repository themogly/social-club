<?php

namespace Tests\Feature\Guides;

use App\Filament\Pages\Inventario;
use App\Support\Help;
use Tests\TestCase;

/**
 * Prompt 360 — everything a person reads about the stock check says the same thing: sealed bags are a reserve (staff tap
 * «Rellenar» when they open one), the end-of-day count weighs the jars only, a forgotten Rellenar is handled for them and
 * noted for the manager, and the reserve is checked in Inventario.
 */
class ReserveInTheGuidesTest extends TestCase
{
    private function guide(string $slug): string
    {
        return (string) file_get_contents(resource_path("guides/en/{$slug}.md"));
    }

    /** The section of a guide from its heading to the next heading of the same level. */
    private function section(string $markdown, string $heading): string
    {
        $start = (int) strpos($markdown, $heading);
        $next = strpos($markdown, "\n## ", $start + strlen($heading));

        return substr($markdown, $start, $next === false ? null : $next - $start);
    }

    public function test_every_guide_names_the_reserve_and_rellenar(): void
    {
        foreach (['counter-quick-start', 'manager-guide'] as $slug) {
            $text = $this->guide($slug);
            $this->assertStringContainsString('Rellenar', $text, "{$slug}: the Spanish word staff see");
            $this->assertStringContainsString('Reserva', $text, "{$slug}: the Spanish word staff see");
            $this->assertStringContainsString('Top up', $text, "{$slug}: the English label");
        }
    }

    public function test_the_close_recount_weighs_the_jars_only_and_asks_one_reason(): void
    {
        $close = $this->section($this->guide('counter-quick-start'), '## 8. Closing the till');
        $this->assertStringNotContainsString('Weigh each batch listed', $close, 'no longer "weigh everything"');
        $this->assertStringNotContainsString('give a reason', $close, 'no reason per jar');
        $this->assertStringContainsString('Weigh the jars only', $close);
        $this->assertStringContainsString("The count doesn't match — what happened?", $close);

        $cash = $this->section($this->guide('cash-at-the-counter'), '## 6. Closing and differences');
        $this->assertStringContainsString('Weigh the jars only', $cash);
        $this->assertStringContainsString('The one reason box', $cash);
        $this->assertStringContainsString('One answer covers the whole count', $cash);
    }

    public function test_the_manager_guide_has_the_two_columns_the_toggle_the_shared_reason_and_the_go_live_checklist(): void
    {
        $text = $this->guide('manager-guide');
        foreach (['Jar (g)', 'Sealed reserve (g)', 'Include empty batches', 'Use one reason for every difference', 'What are you correcting?',
            'Unrecorded top-up', 'Setting up the reserve (go-live)', 'Of which in reserve (sealed)', 'The **Reserve** column'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_the_help_and_the_glossary_say_it_in_both_languages(): void
    {
        $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
        $es = json_decode((string) file_get_contents(lang_path('es.json')), true);

        foreach (['Reserva (sellada)', 'Rellenar', 'Rellenado sin registrar'] as $term) {
            $this->assertArrayHasKey($term, Help::GLOSSARY);
            $this->assertArrayHasKey($term, $es);
            $this->assertArrayHasKey($term, $en);
            $this->assertArrayHasKey(Help::GLOSSARY[$term], $en, "{$term}'s explanation has its English");
        }
        $this->assertStringContainsString('solo pesa los botes', implode(' ', Help::PAGE_TOPICS[Inventario::class]['body']));
        $close = implode(' ', array_merge(...array_column(Help::GUIDES['till-day']['steps'], 'body')));
        $this->assertStringContainsString('El recuento no cuadra — ¿qué ha pasado?', $close);
        $this->assertStringContainsString('Pesa solo los botes', $close);
        $this->assertStringNotContainsString('pesa todo', Help::GLOSSARY['Arqueo']);
    }
}
