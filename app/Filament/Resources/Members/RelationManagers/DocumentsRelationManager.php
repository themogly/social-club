<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Documents\GenerateMemberDocument;
use App\Enums\MemberDocumentType;
use App\Filament\Resources\MemberDocuments\MemberDocumentResource;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The member's document vault — every immutable, versioned MemberDocument on the private
 * disk. Generation routes through GenerateMemberDocument (documents.generate); opening a
 * document routes through IssueDocumentUrl, which writes a DocumentAccessLog for EVERY
 * view and returns a short-lived signed URL (member.documents.view). The files are never
 * served from a guessable path.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documentos');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (MemberDocumentType $state): string => MemberDocumentResource::typeLabel($state)),
                TextColumn::make('version')->label(__('Versión'))->sortable(),
                TextColumn::make('created_at')->label(__('Generado'))->dateTime()->sortable(),
                TextColumn::make('uploadedBy.name')->label(__('Por'))->placeholder('—'),
            ])
            ->headerActions([
                $this->generateAction(),
            ])
            ->recordActions([
                MemberDocumentResource::viewDocumentAction(),
            ]);
    }

    /** Generar documento for the owner socio (documents.generate). */
    protected function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('Generar documento'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->visible(fn (): bool => Auth::user()?->can('documents.generate') ?? false)
            ->schema([
                Select::make('type')
                    ->label(__('Tipo de documento'))
                    ->options([
                        MemberDocumentType::REGISTRATION_FORM->value => __('Solicitud de alta'),
                        MemberDocumentType::DECLARATION->value => __('Previsión de consumo'),
                        MemberDocumentType::SANCTION_ACT->value => __('Acta de sanción'),
                    ])
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                /** @var User $actor */
                $actor = Auth::user();

                (new GenerateMemberDocument)->handle($member, MemberDocumentType::from((string) $data['type']), $actor);

                Notification::make()->title(__('Documento generado'))->success()->send();
            });
    }
}
