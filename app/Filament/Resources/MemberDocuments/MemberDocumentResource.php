<?php

namespace App\Filament\Resources\MemberDocuments;

use App\Actions\Members\IssueDocumentUrl;
use App\Enums\MemberDocumentType;
use App\Filament\Resources\MemberDocuments\Pages\ListMemberDocuments;
use App\Filament\Resources\MemberDocuments\Tables\MemberDocumentsTable;
use App\Models\MemberDocument;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Documentos generados — the org-wide, read-only vault of every generated member document.
 * Documents are immutable artifacts on the private disk; there is no create/edit/delete
 * here (generation happens from the member "Generar documento" action). Opening one goes
 * through IssueDocumentUrl (short-lived signed URL + access log). Gated by
 * MemberDocumentPolicy on `member.documents.view`.
 */
class MemberDocumentResource extends Resource
{
    protected static ?string $model = MemberDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return __('Documentos generados');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public static function getModelLabel(): string
    {
        return __('documento');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documentos generados');
    }

    public static function table(Table $table): Table
    {
        return MemberDocumentsTable::configure($table);
    }

    /** Only documents of members in the active organisation (member carries the org scope). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('member')->with(['member', 'uploadedBy']);
    }

    /**
     * Ver — issue a short-lived signed URL (logging the access, on modal open exactly as the click used to) and
     * show it in a MODAL, never a new tab (prompt 252). Reused by the resource table and the member
     * document-vault relation manager. Images render inline; a PDF gets an iframe + a same-tab fallback.
     */
    public static function viewDocumentAction(): Action
    {
        return Action::make('view')
            ->label(__('Ver'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (): bool => Auth::user()?->can('member.documents.view') ?? false)
            ->modalHeading(__('Documento'))
            ->modalContent(function (MemberDocument $record) {
                /** @var User $actor */
                $actor = Auth::user();

                return view('filament.documents.viewer', [
                    'url' => (new IssueDocumentUrl)->handle($record, $actor),
                    'isPdf' => self::isPdf($record),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Cerrar'));
    }

    /** A stored document is a PDF when its path says so; everything else (photo, ID image) renders as an image. */
    private static function isPdf(MemberDocument $record): bool
    {
        return str_ends_with(strtolower((string) $record->path), '.pdf');
    }

    public static function typeLabel(MemberDocumentType $type): string
    {
        return $type->label();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberDocuments::route('/'),
        ];
    }
}
