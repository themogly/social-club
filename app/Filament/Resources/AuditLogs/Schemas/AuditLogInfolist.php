<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use App\Support\AuditFieldFormatter;
use App\Support\AuditFieldLabeler;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A single audit entry, read-only, with a computed before/after diff. Changed keys are
 * listed antes → después; the full before/after payloads are shown as pretty JSON below so
 * nothing is hidden. Rendering is escaped HTML — audit payloads are attacker-influenced
 * (an actor's own input can land in `before`/`after`), so every value goes through e().
 */
class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Entrada'))
                    ->schema([
                        TextEntry::make('created_at')->label(__('Fecha'))->dateTime(),
                        TextEntry::make('actor.name')->label(__('Actor'))->placeholder(__('Sistema')),
                        TextEntry::make('action')->label(__('Acción'))->badge(),
                        TextEntry::make('auditable_type')
                            ->label(__('Tipo de sujeto'))
                            ->formatStateUsing(fn (?string $state): string => $state !== null ? class_basename($state) : '—')
                            ->placeholder('—'),
                        TextEntry::make('auditable_id')->label(__('ID del sujeto'))->placeholder('—'),
                        TextEntry::make('ip')->label(__('IP'))->placeholder('—'),
                        TextEntry::make('user_agent')->label(__('Agente'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('Cambios'))
                    ->schema([
                        TextEntry::make('diff')
                            ->hiddenLabel()
                            ->state(fn (AuditLog $record): string => self::diffHtml($record))
                            ->html()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /** The computed diff (plain-language labels + formatted values) plus the raw payloads, escaped. */
    private static function diffHtml(AuditLog $record): string
    {
        $before = is_array($record->before) ? $record->before : [];
        $after = is_array($record->after) ? $record->after : [];
        $modelClass = self::modelClass($record);

        $keys = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $rows = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            $old = AuditFieldFormatter::format($modelClass, $key, $before[$key] ?? null);
            $new = AuditFieldFormatter::format($modelClass, $key, $after[$key] ?? null);
            if ($old === $new) {
                continue;
            }
            $rows[] = '<tr>'
                .'<td style="padding:2px 10px 2px 0;font-weight:600;">'.e(AuditFieldLabeler::label($modelClass, $key)).'</td>'
                .'<td style="padding:2px 10px;color:#dc2626;text-decoration:line-through;">'.e($old).'</td>'
                .'<td style="padding:2px 10px;color:#16a34a;">'.e($new).'</td>'
                .'</tr>';
        }

        $diff = $rows === []
            ? '<p style="color:#475569;font-style:italic;">'.e(__('Sin cambios de campo registrados.')).'</p>'
            : '<table style="border-collapse:collapse;font-size:.85rem;"><thead><tr>'
                .'<th style="text-align:left;padding-right:10px;">'.e(__('Campo')).'</th>'
                .'<th style="text-align:left;padding:0 10px;">'.e(__('Antes')).'</th>'
                .'<th style="text-align:left;padding:0 10px;">'.e(__('Después')).'</th>'
                .'</tr></thead><tbody>'.implode('', $rows).'</tbody></table>';

        // The raw before/after JSON is DEMOTED (not removed) into a native, collapsed <details> —
        // available for forensics, out of the way by default. No JS/Alpine, no new dependency.
        return $diff
            .'<details style="margin-top:1rem;">'
            .'<summary style="cursor:pointer;color:#475569;font-size:.8rem;">'.e(__('Ver datos sin procesar')).'</summary>'
            .'<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.5rem;">'
            .self::payloadBlock(__('Antes'), $before)
            .self::payloadBlock(__('Después'), $after)
            .'</div>'
            .'</details>';
    }

    /** The audited model's FQCN (resolving a morph alias if one is in use); null for settings/imports. */
    private static function modelClass(AuditLog $record): ?string
    {
        $type = $record->auditable_type;

        if ($type === null || $type === '') {
            return null;
        }

        return class_exists($type) ? $type : (Relation::getMorphedModel($type) ?? $type);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function payloadBlock(string $title, array $payload): string
    {
        $json = $payload === []
            ? '—'
            : (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<div style="flex:1;min-width:220px;">'
            .'<div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#475569;margin-bottom:.25rem;">'.e($title).'</div>'
            .'<pre style="margin:0;padding:.6rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;overflow:auto;font-size:.75rem;color:#0f172a;">'.e($json).'</pre>'
            .'</div>';
    }
}
