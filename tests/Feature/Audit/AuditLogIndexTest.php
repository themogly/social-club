<?php

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Prompt 79 — the audit log list is org-scoped and ordered by created_at desc. It needs an index led by
 * organisation_id then created_at to serve that; the pre-existing (action, created_at) index, led by action,
 * cannot (leftmost-prefix). This guards the added index so the slow ordering can't silently return.
 */
class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_has_an_organisation_then_created_at_index(): void
    {
        $indexes = collect(Schema::getIndexes('audit_logs'))->map(
            fn (array $index): array => array_map('strtolower', $index['columns'])
        );

        $this->assertTrue(
            $indexes->contains(fn (array $columns): bool => $columns === ['organisation_id', 'created_at']),
            'audit_logs needs a (organisation_id, created_at) index to serve the org-scoped, created_at-ordered list.'
        );
    }
}
