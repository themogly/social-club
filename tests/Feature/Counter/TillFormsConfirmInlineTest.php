<?php

namespace Tests\Feature\Counter;

use App\Actions\Till\OpenTill;
use App\Enums\BatchStatus;
use App\Enums\CashMovementType;
use App\Enums\ExpenseKind;
use App\Enums\Role;
use App\Livewire\Counter\TillSession;
use App\Models\Batch;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\CounterOperator;
use App\Support\Money;
use Database\Seeders\RolePermissionSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 279 (Ben's 275) — the till forms confirm where you tapped.
 *
 * Reported from the club tablet as *"Record movement not working in counter."* It was working: the row was
 * written and the screen said "Movimiento registrado: 10,00 €." — in the shared flash slot at the TOP of the
 * page. At iPad landscape the operator has scrolled down to the form, so the answer landed ~300–600px above the
 * viewport; the only visible change was the amount field emptying, and the second tap recorded it twice.
 *
 * Prompt 202's rule, applied to the till: an action's result renders INSIDE the card of the form that produced
 * it, right by its button — and only there, so there is still exactly one confirmation. These tests assert
 * POSITION (which card the message is in), not mere presence: `assertSee` was true of the top-slot version too.
 */
class TillFormsConfirmInlineTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->org = Organisation::factory()->create();
        app(ActiveScope::class)->setOrganisation($this->org->id);
        $this->location = Location::factory()->create(['organisation_id' => $this->org->id]);
        app(ActiveScope::class)->setLocation($this->location->id);
    }

    private function operator(Role $role = Role::OWNER): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);
        $user->locations()->sync([$this->location->id]);
        CounterOperator::set($user);
        $this->actingAs($user);

        return $user;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::factory()->create([
            'organisation_id' => $this->org->id, 'default_kind' => ExpenseKind::TILL, 'active' => true,
        ]);
    }

    // --- reading the page ---------------------------------------------------------------------------------

    private function xpath(string $html): DOMXPath
    {
        // Livewire's snapshot carries every public property, flash included — strip it so a count is what a
        // person sees. `@click` is not a valid attribute name for DOMDocument; normalise it (196's idiom).
        $html = (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);
        $html = str_replace('@click', 'x-on:click', $html);

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** The text of the card (section) holding the form that submits to `$action`. */
    private function cardText(string $html, string $action): string
    {
        $card = $this->xpath($html)->query("//form[@*[name()='wire:submit']='{$action}']/ancestor::section[1]")->item(0);
        $this->assertInstanceOf(DOMElement::class, $card, "the {$action} form did not render inside a card");

        return (string) preg_replace('/\s+/', ' ', $card->textContent);
    }

    /** The live region answering `$slot` — null when none rendered there. */
    private function feedback(string $html, string $slot): ?string
    {
        $node = $this->xpath($html)->query("//*[@data-till-feedback='{$slot}']")->item(0);

        return $node === null ? null : trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }

    private function topSlot(string $html): ?string
    {
        $node = $this->xpath($html)->query('//*[@data-commit-feedback]')->item(0);

        return $node?->textContent;
    }

    private function occurrences(string $html, string $needle): int
    {
        return substr_count((string) preg_replace('/wire:snapshot="[^"]*"/', '', $html), $needle);
    }

    // --- (1) the movement succeeds, and says so beside its button ------------------------------------------

    public function test_a_recorded_movement_confirms_inside_its_own_card_with_amount_and_type(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $component = Livewire::test(TillSession::class)
            ->set('movementType', 'IN')
            ->set('movementAmount', '10,00')
            ->call('recordMovement')
            ->assertOk();

        // The row is real — 10,00 € typed, 1000 cents stored.
        $this->assertSame(1000, CashMovement::query()->withoutGlobalScopes()->where('type', CashMovementType::IN->value)->sole()->amount_cents->cents);

        $expected = __('Movimiento registrado: :amount (:type).', [
            'amount' => Money::fromCents(1000)->formatted(),
            'type' => CashMovementType::IN->shortLabel(),
        ]);
        $html = $component->html();

        // The field it names has been cleared — which is exactly why the type rides in the message.
        $this->assertSame('', $component->get('movementAmount'));
        $this->assertStringContainsString($expected, (string) $this->feedback($html, 'movement'));
        $this->assertStringContainsString($expected, $this->cardText($html, 'recordMovement'));
        $this->assertNull($this->topSlot($html), 'the top slot is suppressed — one confirmation, where it happened');
        $this->assertSame(1, $this->occurrences($html, $expected), 'exactly one confirmation on screen');
    }

    public function test_each_movement_type_is_named_as_the_select_names_it(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        foreach ([CashMovementType::OUT, CashMovementType::BANKED] as $type) {
            $html = Livewire::test(TillSession::class)
                ->set('movementType', $type->value)
                ->set('movementAmount', '5')
                ->call('recordMovement')
                ->html();

            $this->assertStringContainsString('('.$type->shortLabel().')', (string) $this->feedback($html, 'movement'));
            // …and the select offers the same words, so what the operator picked is what they read back.
            $this->assertStringContainsString('>'.$type->shortLabel().'</option>', $html);
        }
    }

    /**
     * The same movement twice must confirm twice. The shared flash was keyed on its message alone, so the second
     * identical confirmation morphed onto the first one's element — already faded by its 6s timer — and nothing
     * appeared: measured in the browser before this fix. A fresh key per flash forces a fresh element.
     */
    public function test_the_same_confirmation_twice_renders_as_a_new_message(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $key = fn (string $html): string => (string) $this->xpath($html)->query("//*[@data-till-feedback='movement']")->item(0)?->getAttribute('wire:key');

        $component = Livewire::test(TillSession::class)->set('movementAmount', '10')->call('recordMovement');
        $first = $key($component->html());
        $second = $key($component->set('movementAmount', '10')->call('recordMovement')->html());

        $this->assertSame(2, CashMovement::query()->withoutGlobalScopes()->count());
        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second, 'an identical second confirmation reused the first one\'s element');
    }

    // --- (2) refusals render inside the movement card ------------------------------------------------------

    public function test_an_invalid_movement_amount_is_refused_inside_the_movement_card(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->set('movementAmount', '1.000') // ambiguous — prompt 271's strict parse refuses it
            ->call('recordMovement')
            ->html();

        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString(__('El importe no es válido.'), (string) $this->feedback($html, 'movement'));
        $this->assertStringContainsString(__('El importe no es válido.'), $this->cardText($html, 'recordMovement'));
        $this->assertNull($this->topSlot($html));
    }

    public function test_the_bank_deposit_permission_refusal_renders_inside_the_movement_card(): void
    {
        $this->operator(Role::STAFF); // till.open, never cash.bank
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->set('movementType', 'BANKED')
            ->set('movementAmount', '50')
            ->call('recordMovement')
            ->html();

        $this->assertStringContainsString(__('Ingresar efectivo en el banco requiere permiso.'), (string) $this->feedback($html, 'movement'));
        $this->assertNull($this->topSlot($html));
    }

    public function test_with_nobody_identified_the_refusal_renders_inside_the_movement_card(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $component = Livewire::test(TillSession::class);
        CounterOperator::clear();

        $html = $component->set('movementAmount', '10')->call('recordMovement')->html();

        $this->assertSame(0, CashMovement::query()->withoutGlobalScopes()->count());
        $this->assertStringContainsString(__('Identifícate con tu PIN antes de continuar.'), (string) $this->feedback($html, 'movement'));
    }

    // --- (3) the expense form: the same rule ---------------------------------------------------------------

    public function test_a_recorded_expense_confirms_inside_its_own_card(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->set('expenseCategoryId', $this->category()->id)
            ->set('expenseAmount', '2,50')
            ->call('recordExpense')
            ->assertOk()
            ->html();

        $expected = __('Gasto de caja registrado: :amount.', ['amount' => Money::fromCents(250)->formatted()]);
        $this->assertStringContainsString($expected, (string) $this->feedback($html, 'expense'));
        $this->assertStringContainsString($expected, $this->cardText($html, 'recordExpense'));
        $this->assertNull($this->topSlot($html));
        $this->assertSame(1, $this->occurrences($html, $expected));
    }

    public function test_an_invalid_expense_amount_is_refused_inside_the_expense_card(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->set('expenseCategoryId', $this->category()->id)
            ->set('expenseAmount', 'abc')
            ->call('recordExpense')
            ->html();

        $this->assertStringContainsString(__('El importe no es válido.'), (string) $this->feedback($html, 'expense'));
        $this->assertStringContainsString(__('El importe no es válido.'), $this->cardText($html, 'recordExpense'));
        $this->assertNull($this->feedback($html, 'movement'), 'the refusal is not echoed in the neighbouring card');
        $this->assertNull($this->topSlot($html));
    }

    // --- (4) the double-tap guard --------------------------------------------------------------------------

    public function test_both_buttons_disable_and_say_registrando_while_their_own_request_is_in_flight(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $this->category();

        $xpath = $this->xpath(Livewire::test(TillSession::class)->html());

        foreach (['recordMovement', 'recordExpense'] as $action) {
            $button = $xpath->query("//form[@*[name()='wire:submit']='{$action}']//button[@type='submit']")->item(0);
            $this->assertInstanceOf(DOMElement::class, $button, "no submit button on {$action}");
            $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'), "{$action}: not disabled while in flight");
            $this->assertSame($action, $button->getAttribute('wire:target'), "{$action}: the loading state is not targeted at its own action");

            $busy = $xpath->query(".//*[@*[name()='wire:loading'] and @*[name()='wire:target']='{$action}']", $button)->item(0);
            $this->assertInstanceOf(DOMElement::class, $busy, "{$action}: no in-flight label");
            $this->assertSame(__('Registrando…'), trim($busy->textContent));
        }
    }

    // --- the sweep: every other till action whose button sits low on the page ------------------------------

    public function test_a_handover_refusal_renders_inside_the_handover_card(): void
    {
        $this->operator(Role::STAFF);
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->call('toggleHandover')
            ->set('handoverCounted', 'nope')
            ->call('handOver')
            ->html();

        $card = $this->xpath($html)->query('//*[@data-handover]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $card);
        $this->assertStringContainsString(__('El recuento no es válido.'), $card->textContent);
        $this->assertNotNull($this->feedback($html, 'handover'));
        $this->assertNull($this->topSlot($html));
    }

    public function test_a_blind_count_refusal_renders_inside_the_count_form(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->call('startClose')
            ->set('countInput', '1.250') // ambiguous — refused, never closed at €1,25
            ->call('submitCount')
            ->html();

        $this->assertStringContainsString(__('El importe contado no es válido.'), (string) $this->feedback($html, 'count'));
        $this->assertStringContainsString(__('El importe contado no es válido.'), $this->cardText($html, 'submitCount'));
        $this->assertNull($this->topSlot($html));
    }

    public function test_a_flower_recount_refusal_renders_inside_the_recount_form(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);
        $genetic = Genetic::factory()->create(['organisation_id' => $this->org->id]);
        Batch::factory()->create([
            'organisation_id' => $this->org->id, 'genetic_id' => $genetic->id, 'location_id' => $this->location->id,
            'initial_cg' => 100000, 'remaining_cg' => 90000, 'status' => BatchStatus::OPEN,
        ]);

        $html = Livewire::test(TillSession::class)
            ->call('startClose')
            ->assertSet('reweighing', true)
            ->call('submitReweigh') // nothing weighed
            ->html();

        $refusal = __('Introduce el peso contado de cada lote, o márcalo como no contado.');
        $this->assertStringContainsString($refusal, (string) $this->feedback($html, 'reweigh'));
        $this->assertStringContainsString($refusal, $this->cardText($html, 'submitReweigh'));
        $this->assertNull($this->topSlot($html));
    }

    /**
     * What stays at the top, deliberately: a message that answers a whole-screen change. "Caja cerrada." sits
     * directly above the revealed arqueo it announces — the count form it came from no longer exists.
     */
    public function test_a_whole_screen_outcome_still_uses_the_top_slot(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $html = Livewire::test(TillSession::class)
            ->call('startClose')
            ->set('countInput', '100,00')
            ->call('submitCount')
            ->assertSet('countSubmitted', true)
            ->html();

        $this->assertStringContainsString(__('Caja cerrada.'), (string) $this->topSlot($html));
    }

    /** A flash raised by the next, unrelated action goes back to the top — the slot never sticks to a form. */
    public function test_the_inline_slot_does_not_outlive_the_action_that_set_it(): void
    {
        $this->operator();
        (new OpenTill)->handle($this->location, 'POS-1', 10000);

        $component = Livewire::test(TillSession::class)
            ->set('movementAmount', 'x')
            ->call('recordMovement')
            ->call('startClose')
            ->set('countInput', '100,00')
            ->call('submitCount');

        $html = $component->html();
        $this->assertNull($this->feedback($html, 'movement'));
        $this->assertStringContainsString(__('Caja cerrada.'), (string) $this->topSlot($html));
    }
}
