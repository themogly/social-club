<?php

namespace App\Filament\Resources\MessageThreads;

use App\Actions\Messaging\CloseThread;
use App\Actions\Messaging\ConvertThreadToDataRequest;
use App\Actions\Messaging\ReplyToThread;
use App\Enums\DataRequestType;
use App\Enums\MessageAuthor;
use App\Enums\MessageThreadStatus;
use App\Filament\Resources\MessageThreads\Pages\ListMessageThreads;
use App\Filament\Resources\MessageThreads\Pages\ViewMessageThread;
use App\Filament\Resources\MessageThreads\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\MessageThreads\Schemas\MessageThreadInfolist;
use App\Filament\Resources\MessageThreads\Tables\MessageThreadsTable;
use App\Models\MessageThread;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Mensajes — the member↔club threads (prompt 136). Read + act, never raw-edit: staff REPLY, CLOSE, or CONVERT
 * a thread to an RGPD data request, each through its domain Action (comms.manage). Members originate threads
 * from the PWA; there is no create/edit/delete here. Org-scoped by the BelongsToOrganisation global scope;
 * gated by MessageThreadPolicy. Lives in the "Comunicaciones" group beside announcements and events.
 */
class MessageThreadResource extends Resource
{
    protected static ?string $model = MessageThread::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 15;

    public static function getNavigationLabel(): string
    {
        return __('Mensajes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Comunicaciones');
    }

    public static function getModelLabel(): string
    {
        return __('mensaje');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mensajes');
    }

    /** Live count of threads with an unread member message — the club's actual inbox. Never cached. */
    public static function getNavigationBadge(): ?string
    {
        $count = MessageThread::query()
            ->whereHas('messages', fn ($q) => $q->where('author', MessageAuthor::MEMBER->value)->whereNull('read_at'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return MessageThreadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MessageThreadsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    /**
     * Actions on a single thread (view header + table row).
     *
     * @return array<int, Action>
     */
    public static function recordActions(): array
    {
        return [
            self::replyAction(),
            self::convertAction(),
            self::closeAction(),
        ];
    }

    /** Reply to the member — appends a club message, optionally closes, and pushes the member a notification. */
    public static function replyAction(): Action
    {
        return Action::make('reply')
            ->label(__('Responder'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('primary')
            ->visible(fn (): bool => Auth::user()?->can('comms.manage') ?? false)
            ->schema([
                Textarea::make('body')->label(__('Respuesta'))->rows(4)->required(),
                Toggle::make('close')->label(__('Cerrar la conversación al responder'))->default(false),
            ])
            ->action(function (MessageThread $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                try {
                    (new ReplyToThread)->handle($record, $actor, (string) $data['body'], (bool) ($data['close'] ?? false));
                    Notification::make()->title(__('Respuesta enviada'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('No se pudo responder'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Convert the thread to a formal RGPD data request (logs the obligation; the owner still fulfils it). */
    public static function convertAction(): Action
    {
        return Action::make('convert')
            ->label(__('Convertir en solicitud RGPD'))
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('warning')
            ->visible(fn (MessageThread $record): bool => $record->data_request_id === null
                && (Auth::user()?->can('comms.manage') ?? false))
            ->schema([
                Select::make('type')
                    ->label(__('Tipo de solicitud'))
                    ->options(collect(DataRequestType::cases())->mapWithKeys(fn (DataRequestType $t): array => [$t->value => $t->label()])->all())
                    ->required(),
                Textarea::make('notes')->label(__('Notas'))->rows(2),
            ])
            ->action(function (MessageThread $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                $notes = is_string($data['notes'] ?? null) ? $data['notes'] : null;
                try {
                    (new ConvertThreadToDataRequest)->handle($record, $actor, DataRequestType::from((string) $data['type']), $notes);
                    Notification::make()->title(__('Solicitud RGPD registrada'))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title(__('No se pudo convertir'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Close a thread without replying. */
    public static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Cerrar'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (MessageThread $record): bool => $record->status === MessageThreadStatus::OPEN
                && (Auth::user()?->can('comms.manage') ?? false))
            ->action(function (MessageThread $record): void {
                /** @var User $actor */
                $actor = Auth::user();
                (new CloseThread)->handle($record, $actor);
                Notification::make()->title(__('Conversación cerrada'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageThreads::route('/'),
            'view' => ViewMessageThread::route('/{record}'),
        ];
    }
}
