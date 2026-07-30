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
use Livewire\Component;

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
     * Ver — issue a short-lived signed URL (logging the access) and open it in a new tab.
     * Reused by the resource table and the member document-vault relation manager.
     */
    public static function viewDocumentAction(): Action
    {
        return Action::make('view')
            ->label(__('Ver'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (): bool => Auth::user()?->can('member.documents.view') ?? false)
            ->action(function (MemberDocument $record, Component $livewire): void {
                /** @var User $actor */
                $actor = Auth::user();
                $url = (new IssueDocumentUrl)->handle($record, $actor);

                $livewire->js('window.open('.json_encode($url, JSON_THROW_ON_ERROR).", '_blank')");
            });
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
