<?php

namespace App\Filament\Resources\DocumentTemplates;

use App\Enums\MemberDocumentType;
use App\Filament\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Resources\DocumentTemplates\Schemas\DocumentTemplateForm;
use App\Filament\Resources\DocumentTemplates\Tables\DocumentTemplatesTable;
use App\Models\DocumentTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Plantillas de documentos — the per-org, versioned bodies that member documents render
 * from. Versioning is append-only: saving a template creates a NEW version row and never
 * mutates history, so an already-generated document (which froze its own snapshot at
 * generation) is never retroactively changed. Gated on `documents.generate`.
 */
class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Plantillas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public static function getModelLabel(): string
    {
        return __('plantilla');
    }

    public static function getPluralModelLabel(): string
    {
        return __('plantillas');
    }

    /**
     * The document types a template can back (aligned with the member-document titles).
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            MemberDocumentType::REGISTRATION_FORM->value => __('Solicitud de alta'),
            MemberDocumentType::DECLARATION->value => __('Previsión de consumo'),
            MemberDocumentType::SANCTION_ACT->value => __('Acta de sanción'),
            MemberDocumentType::CONSENT->value => __('Consentimiento'),
        ];
    }

    /**
     * Persist a new version of a template type, deactivating any prior active version of
     * the same type (so exactly one is active). Versions are never reused — the next
     * number counts trashed rows too — and history is never mutated in place.
     */
    public static function persistVersion(string $type, string $body, bool $active): DocumentTemplate
    {
        return DB::transaction(function () use ($type, $body, $active): DocumentTemplate {
            $next = (int) DocumentTemplate::query()->withTrashed()
                ->where('type', $type)->max('version') + 1;

            if ($active) {
                DocumentTemplate::query()->where('type', $type)->where('active', true)
                    ->update(['active' => false]);
            }

            return DocumentTemplate::create([
                'type' => $type,
                'body' => $body,
                'version' => $next,
                'active' => $active,
            ]);
        });
    }

    public static function form(Schema $schema): Schema
    {
        return DocumentTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }
}
