<?php

namespace App\Filament\Resources\BreachLogs\Schemas;

use App\Filament\Resources\BreachLogs\BreachLogResource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class BreachLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Incidente'))
                    ->description(__('Registra la brecha lo antes posible. La hora de descubrimiento inicia el plazo de 72 horas para notificar a la AEPD.'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('Descripción del incidente'))
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('scope')
                            ->label(__('Alcance y categorías de datos afectadas'))
                            ->helperText(__('P. ej. "Datos de contacto y de consumo de 3 socios" — marca si incluye datos de salud (Art. 9).'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                        DateTimePicker::make('discovered_at')
                            ->label(__('Descubierta el'))
                            ->seconds(false)
                            ->live()
                            ->default(now())
                            ->required(),
                        Select::make('status')
                            ->label(__('Estado'))
                            ->options(fn (): array => BreachLogResource::statusOptions())
                            ->default('OPEN')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(__('Notificación a la AEPD (72 h)'))
                    ->description(__('Artículo 33 RGPD. Nada de esto constituye asesoramiento legal.'))
                    ->schema([
                        DateTimePicker::make('aepd_notified_at')
                            ->label(__('Notificada a la AEPD el'))
                            ->seconds(false)
                            ->live()
                            ->helperText(__('Déjalo en blanco hasta notificar.')),
                        Placeholder::make('deadline')
                            ->label(__('Estado del plazo'))
                            ->content(function (Get $get): HtmlString {
                                $status = BreachLogResource::deadlineStatus(
                                    self::parse($get('discovered_at')),
                                    self::parse($get('aepd_notified_at')),
                                );
                                $color = match ($status['tone']) {
                                    'success' => '#16a34a',
                                    'warning' => '#d97706',
                                    'danger' => '#dc2626',
                                    default => '#475569',
                                };

                                return new HtmlString('<span style="font-weight:600;color:'.$color.';">'.e($status['label']).'</span>');
                            }),
                        Placeholder::make('runbook')
                            ->label(__('Protocolo de actuación'))
                            ->columnSpanFull()
                            ->content(new HtmlString(self::runbookHtml())),
                    ])
                    ->columns(2),
            ]);
    }

    /** The designed 72-hour breach checklist panel (a runbook, not free text). */
    private static function runbookHtml(): string
    {
        $steps = [
            __('Contener la brecha y preservar evidencias (registros de acceso, copias).'),
            __('Evaluar el alcance y las categorías de datos — señalar si hay datos de salud/consumo (Art. 9).'),
            __('Valorar el riesgo para los derechos de las personas afectadas.'),
            __('Notificar a la AEPD en un plazo máximo de 72 h si hay riesgo (Art. 33).'),
            __('Comunicar a los socios afectados si el riesgo es alto (Art. 34).'),
            __('Documentar todo aquí y en el registro de auditoría; cerrar cuando esté resuelto.'),
        ];
        $items = implode('', array_map(static fn (string $s): string => '<li style="margin:.15rem 0;">'.e($s).'</li>', $steps));

        return '<ol style="margin:.25rem 0 .5rem 1.1rem;padding:0;font-size:.85rem;color:#0f172a;line-height:1.35;">'.$items.'</ol>'
            .'<div style="font-size:.8rem;color:#475569;">'
            .e(__('Sede electrónica de la AEPD para notificar brechas:'))
            .' <a href="https://sedeagpd.gob.es/" target="_blank" rel="noopener" style="color:#2563eb;">sedeagpd.gob.es</a>'
            .' · '.e(__('Runbook interno: ubicación por definir (config del club).'))
            .'</div>';
    }

    private static function parse(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse(is_string($value) ? $value : (string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
