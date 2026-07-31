<?php

namespace App\Actions\Documents;

use App\Actions\RecordAuditLog;
use App\Enums\MemberDocumentType;
use App\Models\ConsentRecord;
use App\Models\DocumentTemplate;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Models\User;
use App\Support\DocumentVault;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Generate an IMMUTABLE member document (registration form / consumption declaration
 * / sanction act) from the member's data + the org's active template + the consent
 * version in force AT generation. The rendered PDF is written to the PRIVATE disk;
 * a FROZEN snapshot of the inputs is stored on the row. Later edits to the member or
 * the template never change an issued document — regeneration produces a NEW version.
 */
class GenerateMemberDocument
{
    public function handle(Member $member, MemberDocumentType $type, User $actor): MemberDocument
    {
        if (! $actor->can('documents.generate')) {
            throw new AuthorizationException('Generating a document requires the documents.generate permission.');
        }

        $template = DocumentTemplate::query()->withoutGlobalScopes()
            ->where('organisation_id', $member->organisation_id)
            ->where('type', $type->value)->where('active', true)
            ->latest('version')->first();

        $consentVersion = ConsentRecord::query()
            ->where('member_id', $member->id)->whereNotNull('granted_at')
            ->latest('granted_at')->value('consent_text_version')
            ?? (string) Settings::get('consent_text_version', '1.0');

        // The frozen record of exactly what was rendered.
        $snapshot = [
            'member_no' => $member->member_no,
            'nombre' => $member->fullName(),
            'documento' => $member->document_number,
            'consent_version' => $consentVersion,
            'template_version' => $template?->version,
            'declared_monthly_cg' => $member->declared_monthly_cg,
            'generated_at' => now()->toIso8601String(),
        ];

        $html = view('documents.member', [
            'type' => $type,
            'member' => $member,
            'snapshot' => $snapshot,
            'templateBody' => $template?->body,
        ])->render();

        $path = 'generated/'.Str::ulid().'.pdf';
        // Encrypted at rest (prompt 32) — the PDF embeds the ID number; decrypted only when
        // streamed through the authorised, access-logged signed-URL endpoint.
        DocumentVault::put($path, Pdf::loadHTML($html)->output());

        $version = (int) MemberDocument::query()
            ->where('member_id', $member->id)->where('type', $type->value)->max('version') + 1;

        $document = MemberDocument::create([
            'member_id' => $member->id,
            'type' => $type,
            'path' => $path,
            'uploaded_by' => $actor->id,
            'version' => $version,
            'generated_from' => $template?->id,
            'snapshot' => $snapshot,
        ]);

        (new RecordAuditLog)->handle('document.generated', $member, null, [
            'document_id' => $document->id,
            'type' => $type->value,
            'version' => $version,
            'consent_version' => $consentVersion,
        ]);

        return $document;
    }
}
