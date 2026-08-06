<?php

namespace Tests\Feature\Forms;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Convocatorias\ConvocatoriaResource;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Filament\Resources\Discounts\DiscountResource;
use App\Filament\Resources\Dispensations\DispensationResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use App\Filament\Resources\MessageThreads\MessageThreadResource;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
use Tests\TestCase;

/**
 * The repeatable form-completeness gate (prompt 20). Every fillable field on every
 * Filament resource model must be either PRESENT in the create/edit form OR listed in
 * a documented per-resource allowlist with a reason (system-computed, scope-filled,
 * entered at a money/weight edge, or managed by a domain action). A new column that
 * forgets its form field — and is not deliberately excluded — fails CI here.
 *
 * `organisation_id` is excluded globally: it is always scope-filled by
 * BelongsToOrganisation / ActiveScope, never a user-entered field.
 */
class FormCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** Read-only oversight resources with no create/edit form by design. */
    private const FORMLESS = [
        AuditLogResource::class => 'Append-only audit trail — entries are written by RecordAuditLog, never a form.',
        MemberDocumentResource::class => 'Read-only document vault — documents are generated/uploaded via domain actions.',
        TillSessionResource::class => 'Read-only till oversight — sessions open/close at the counter terminal, not a form.',
        OrderResource::class => 'Read-only bar-sales oversight — orders are committed/voided at the bar POS, never a form (prompt 43).',
        DispensationResource::class => 'Read-only dispensation oversight — withdrawals happen at the dispensary POS; the only action is the refund modal (prompt 71).',
        MessageThreadResource::class => 'Read-only messaging oversight — threads are started by members from the PWA; staff only reply/close/convert via actions, never a form (prompt 136).',
    ];

    /**
     * Fillable fields deliberately absent from a resource form, each with the reason.
     * `organisation_id` is handled globally and never listed here.
     *
     * @var array<class-string, array<string, string>>
     */
    private const ALLOWLIST = [
        AnnouncementResource::class => [
            'author_id' => 'system: set to the authenticated author on create (mutateFormDataBeforeCreate).',
        ],
        ConvocatoriaResource::class => [
            'created_by' => 'system: set to the authenticated creator on create (mutateFormDataBeforeCreate).',
            'issued_at' => 'system: set by IssueConvocatoria when the notice is issued (immutable thereafter).',
            'issued_by' => 'system: set to the issuing user by IssueConvocatoria.',
            'notice_days' => 'system: notice period frozen from settings at issue by IssueConvocatoria.',
            'quorum_required' => 'system: first-call quorum computed from the frozen roll at issue.',
            'roll_count' => 'system: size of the frozen recipient roll, set at issue.',
        ],
        ArticleResource::class => [
            'location_id' => 'scope: active location (ScopedToLocation).',
            'price_cents' => 'entered as euros via price_eur (MoneyCast at the edge).',
        ],
        BatchResource::class => [
            'location_id' => 'scope: active location.',
            'batch_no' => 'system: generated batch number.',
            'initial_cg' => 'entered as grams; converted on intake by IntakeBatch (WEIGHT genetics).',
            'remaining_cg' => 'system-computed: maintained by the stock-movement ledger.',
            'initial_units' => 'entered as units; recorded on intake by IntakeBatch (UNIT genetics).',
            'remaining_units' => 'system-computed: maintained by the stock-movement ledger.',
            'cost_per_gram_cents' => 'entered as euros via cost_per_gram_eur.',
            'status' => 'lifecycle: system-managed batch status.',
        ],
        DataRequestResource::class => [
            'completed_at' => 'system: stamped when the request is fulfilled.',
            'handled_by' => 'system: set to the handling user on completion.',
        ],
        DiscountResource::class => [
            'value_bp' => 'entered as percent via value_pct (basis points at the edge).',
            'value_cents' => 'legacy fixed-amount column; no longer authorable, kept so old rows still price (prompt 168).',
            'mode' => 'stamped PERCENT on create (prompt 168) — a fixed amount cannot be ranked against a percentage, so it is not offered.',
            'applies_to' => 'stamped GENETIC on create (prompt 168) — discounts apply to flower; existing rows keep their scope.',
        ],
        DocumentTemplateResource::class => [
            'version' => 'system: auto-incremented per generated template version.',
        ],
        EventResource::class => [
            'reminder_sent_at' => 'system: per-event marker set by the events:remind sweep (prompt 56).',
        ],
        ExpenseResource::class => [
            'amount_cents' => 'entered as euros via amount_eur.',
            'kind' => 'derived from the chosen category / paid_from source.',
            'till_session_id' => 'system: petty-cash expenses attach to the open till session.',
            'recurrence' => 'assembled from recurrence_frequency into the recurrence array.',
            'recorded_by' => 'system: set to the recording user.',
            'approved_by' => 'system: set by the ApproveExpense action.',
            'approved_at' => 'system: set by the ApproveExpense action.',
        ],
        GeneticResource::class => [
            'thc_bp' => 'entered as percent via thc_pct (basis points at the edge).',
            'cbd_bp' => 'entered as percent via cbd_pct (basis points at the edge).',
            'grams_per_unit_cg' => 'entered as grams via grams_per_unit_g (centigrams at the edge).',
        ],
        MemberApplicationResource::class => [
            'invite_token_hash' => 'system: set by the tokenised invite flow.',
            'invite_token' => 'system: the encrypted raw token, set at invite generation for re-copy (prompt 29).',
            'invited_by' => 'system: set to the issuing user at invite generation.',
            'applicant_email' => 'set on the invite-generation action (optional email invite), not the edit form (prompt 45).',
            'applicant_reference' => 'set on the invite-generation action (the hand-over path identifier), not the edit form (prompt 149).',
            'invite_expires_at' => 'system: computed from the invite_expiry_days setting at generation.',
            'opened_at' => 'system: stamped when the prospect first opens the form.',
            'submitted_at' => 'system: stamped when the prospect submits the application.',
            'revoked_at' => 'system: set by the Revoke invite action.',
            'payload' => 'system: the applicant-submitted data (public pre-registration form).',
            'reviewed_by' => 'system: set by the approve/reject review action.',
            'reviewed_at' => 'system: set by the approve/reject review action.',
            'resulting_member_id' => 'system: set when an application is approved.',
        ],
        LocationResource::class => [
            // 'terminals' is now managed on the form (prompt 102 — terminal CRUD moved to admin), so it is no
            // longer allowlisted; OpenTill still registers any first-seen terminal on top of the configured set.
        ],
        MemberResource::class => [
            'member_no' => 'system: generated by MemberNumber::next().',
            'document_hash' => 'system: blind index kept in sync with document_number.',
            'status' => 'lifecycle: managed by the TransitionMemberStatus actions.',
            'joined_at' => 'system: stamped at approval.',
            'left_at' => 'system: stamped on departure.',
            'carencia_ends_at' => 'system: computed from joined_at + carencia_days.',
            'daily_limit_cg' => 'per-member override set via the member.limits.set action, not onboarding.',
            'monthly_limit_cg' => 'per-member override set via the member.limits.set action, not onboarding.',
            'anonymised_at' => 'system: stamped by AnonymiseMember (RGPD erasure).',
            'push_opt_outs' => 'member self-service PWA notification preference.',
            'locale' => 'member self-service: set by the member via the PWA language switcher, not the admin form (prompt 96).',
            'kind' => 'set via the "Socio temporal" toggle at enrolment (a virtual field mapped in the create hook), prompt 31.',
            'temporary_expires_at' => 'system: computed from joined_at + temporary_window_days for temporary members, prompt 31.',
            'temporary_reminder_sent_at' => 'system: idempotency marker stamped by the members:remove-temporary sweep when the expiry reminder is sent, prompt 111.',
            'declared_monthly_cg' => 'a signed legal figure: sole writer is the UpdateDeclaredForecast record action, never the generic form, prompt 72.',
        ],
        MembershipTierResource::class => [
            'default_fee_cents' => 'entered as euros via default_fee_eur.',
            'daily_limit_cg' => 'entered as grams via daily_limit_g.',
            'monthly_limit_cg' => 'entered as grams via monthly_limit_g.',
        ],
        MinuteResource::class => [
            'number' => 'system: sequential per (organisation, book), assigned under a row lock.',
            'quorum_present' => 'system-computed from attendees at the meeting date.',
            'quorum_required' => 'system-computed from active members at the meeting date.',
            'signed_at' => 'system: set by the SignMinute action (immutable thereafter).',
            'signed_by' => 'system: the signatory, set to the acting user by the SignMinute action (never user-entered; immutable thereafter).',
        ],
        PurchaseResource::class => [
            'amount_cents' => 'entered as euros via amount_eur.',
            'paid_cents' => 'entered as euros via paid_eur.',
            'batch_id' => 'linked by the RecordPurchase action at stock intake.',
        ],
        UserResource::class => [
            'locale' => 'per-user self-service preference (topbar switcher); null follows the org default.',
        ],
    ];

    public function test_every_fillable_field_is_in_the_form_or_the_documented_allowlist(): void
    {
        foreach ($this->resourceClasses() as $resource) {
            $model = $resource::getModel();
            $fillable = (new $model)->getFillable();
            $pages = $resource::getPages();

            if (array_key_exists($resource, self::FORMLESS)) {
                $this->assertArrayNotHasKey('create', $pages, "{$resource} is documented form-less but now has a create page — re-audit it.");

                continue;
            }

            $this->assertArrayHasKey('create', $pages, "{$resource} has no create page and is not documented as form-less in FORMLESS.");

            $formFields = $this->formFieldNames($resource);
            $allowed = self::ALLOWLIST[$resource] ?? [];

            $gaps = [];
            foreach ($fillable as $field) {
                if ($field === 'organisation_id') {
                    continue; // scope-filled everywhere, never user-entered
                }
                if ($this->covered($field, $formFields)) {
                    continue;
                }
                if (array_key_exists($field, $allowed)) {
                    continue;
                }
                $gaps[] = $field;
            }

            $this->assertSame([], $gaps, "{$resource}: fillable field(s) absent from BOTH the form and the allowlist: ".implode(', ', $gaps).'. Add the field to the form, or document it in the FormCompletenessTest allowlist with a reason.');
        }
    }

    public function test_the_allowlist_stays_honest(): void
    {
        foreach (self::ALLOWLIST as $resource => $fields) {
            $model = $resource::getModel();
            $fillable = (new $model)->getFillable();
            $formFields = $this->formFieldNames($resource);

            foreach ($fields as $field => $reason) {
                $this->assertContains($field, $fillable, "{$resource} allowlists '{$field}', which is not a fillable field (stale entry).");
                $this->assertFalse($this->covered($field, $formFields), "{$resource} allowlists '{$field}', but it IS in the form — remove the allowlist entry.");
                $this->assertNotSame('', trim($reason), "{$resource} allowlist entry '{$field}' needs a reason.");
            }
        }
    }

    /**
     * A fillable field is covered if the form has it by name, or expands it via nested `field.*` keys (JSON columns).
     *
     * @param  list<string>  $formFields
     */
    private function covered(string $field, array $formFields): bool
    {
        foreach ($formFields as $name) {
            if ($name === $field || Str::startsWith($name, $field.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function formFieldNames(string $resource): array
    {
        $harness = new class extends LivewireComponent implements HasSchemas
        {
            use InteractsWithSchemas;

            public function render(): string
            {
                return '<div></div>';
            }
        };

        $schema = $resource::form(Schema::make($harness));

        return array_values(array_unique($this->collectFields($schema)));
    }

    /**
     * @return list<string>
     */
    private function collectFields(Schema $schema): array
    {
        $names = [];
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if ($component instanceof Field) {
                $names[] = $component->getName();
            }
            if ($component instanceof SchemaComponent) {
                foreach ($component->getChildSchemas() as $child) {
                    $names = array_merge($names, $this->collectFields($child));
                }
            }
        }

        return $names;
    }

    /**
     * @return list<class-string>
     */
    private function resourceClasses(): array
    {
        $classes = [];
        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $file) {
            $relative = Str::of($file)->after(app_path().'/')->beforeLast('.php')->replace('/', '\\');
            $class = 'App\\'.$relative;
            if (class_exists($class) && is_subclass_of($class, \Filament\Resources\Resource::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        // A model is required to diff against; skip any resource that has none.
        return array_values(array_filter($classes, fn (string $class): bool => is_subclass_of($class::getModel(), Model::class)));
    }
}
