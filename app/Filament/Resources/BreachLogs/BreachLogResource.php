<?php

namespace App\Filament\Resources\BreachLogs;

use App\Enums\BreachStatus;
use App\Filament\Resources\BreachLogs\Pages\CreateBreachLog;
use App\Filament\Resources\BreachLogs\Pages\EditBreachLog;
use App\Filament\Resources\BreachLogs\Pages\ListBreachLogs;
use App\Filament\Resources\BreachLogs\Schemas\BreachLogForm;
use App\Filament\Resources\BreachLogs\Tables\BreachLogsTable;
use App\Models\BreachLog;
use App\Support\ActiveScope;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brechas de seguridad — the data-breach incident register (RGPD Art. 33). Each incident
 * records its scope, affected data categories, the discovery time (which starts the
 * 72-hour AEPD notification clock) and the notification status. OWNER only (BreachLogPolicy)
 * — a breach is an organisation-level matter. Incidents are recorded and updated as the
 * response progresses, never deleted (the register is evidence).
 */
class BreachLogResource extends Resource
{
    protected static ?string $model = BreachLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 70;

    /** The AEPD notification window (Art. 33) — 72 hours from becoming aware. */
    public const NOTIFICATION_HOURS = 72;

    public static function getNavigationLabel(): string
    {
        return __('Brechas de seguridad');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public static function getModelLabel(): string
    {
        return __('brecha');
    }

    public static function getPluralModelLabel(): string
    {
        return __('brechas de seguridad');
    }

    public static function form(Schema $schema): Schema
    {
        return BreachLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BreachLogsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organisation_id', app(ActiveScope::class)->organisationId());
    }

    public static function statusLabel(BreachStatus $status): string
    {
        return match ($status) {
            BreachStatus::OPEN => __('Abierta'),
            BreachStatus::NOTIFIED => __('Notificada'),
            BreachStatus::CLOSED => __('Cerrada'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return collect(BreachStatus::cases())
            ->mapWithKeys(fn (BreachStatus $status): array => [$status->value => self::statusLabel($status)])
            ->all();
    }

    /**
     * The 72-hour AEPD deadline status derived from the discovery and notification times.
     *
     * @return array{label: string, tone: string}
     */
    public static function deadlineStatus(?CarbonInterface $discovered, ?CarbonInterface $notified): array
    {
        if ($discovered === null) {
            return ['label' => __('Sin fecha de descubrimiento'), 'tone' => 'gray'];
        }

        $deadline = $discovered->copy()->addHours(self::NOTIFICATION_HOURS);

        if ($notified !== null) {
            return $notified->lessThanOrEqualTo($deadline)
                ? ['label' => __('Notificada en plazo'), 'tone' => 'success']
                : ['label' => __('Notificada fuera de plazo'), 'tone' => 'danger'];
        }

        if (now()->greaterThan($deadline)) {
            return ['label' => __('Plazo vencido — notificar ya'), 'tone' => 'danger'];
        }

        $hoursLeft = (int) ceil(now()->diffInHours($deadline, false));

        return ['label' => __('Quedan :h h para notificar', ['h' => max($hoursLeft, 0)]), 'tone' => 'warning'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBreachLogs::route('/'),
            'create' => CreateBreachLog::route('/create'),
            'edit' => EditBreachLog::route('/{record}/edit'),
        ];
    }
}
