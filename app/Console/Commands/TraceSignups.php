<?php

namespace App\Console\Commands;

use App\Models\MemberApplication;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prompt 348 — "Aaron signed up a user and it didn't save": what happened to sign-ups in a window, with NO personal data —
 * ids, timestamps, operators, outcomes, validation keys and error classes only.
 *   1. applications created or submitted in the window (id, status, times);
 *   2. the sign-up trace (`storage/logs/signup*.log`, written by App\Support\SignupTrace): submissions, refusals (which
 *      keys), staff approvals and refusals (which error class);
 *   3. exceptions in the main log (`storage/logs/laravel*.log`) raised from a sign-up class — the exception's class and
 *      where, never its message (a message can quote what was typed).
 * Read-only.
 */
class TraceSignups extends Command
{
    protected $signature = 'csc:signup-trace {--since= : Start of the window, e.g. "2026-10-01 14:00"} {--until= : End (default now)}';

    protected $description = 'List sign-up submissions, refusals and errors in a time window, with no personal data (read-only)';

    private const CLASSES = ['ApplicationController', 'SubmitApplication', 'ApproveApplication', 'EnrolMembership', 'SignsUpMembers', 'MembershipCounter'];

    public function handle(): int
    {
        $since = filled($this->option('since')) ? CarbonImmutable::parse((string) $this->option('since')) : CarbonImmutable::now()->subDay();
        $until = filled($this->option('until')) ? CarbonImmutable::parse((string) $this->option('until')) : CarbonImmutable::now();
        $this->info("Ventana: {$since->format('Y-m-d H:i')} → {$until->format('Y-m-d H:i')}");

        $applications = MemberApplication::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereBetween('created_at', [$since, $until])->orWhereBetween('submitted_at', [$since, $until]))
            ->orderBy('created_at')->get(['id', 'status', 'created_at', 'submitted_at', 'location_id']);
        $this->line('Solicitudes: '.$applications->count());
        $this->table(['Solicitud', 'Estado', 'Creada', 'Enviada', 'Sede'], $applications->map(fn (MemberApplication $a): array => [
            $a->id, $a->status->value, (string) $a->created_at, (string) ($a->submitted_at ?? '—'), (string) $a->location_id,
        ])->all());

        $events = $this->logLines('signup', $since, $until);
        $this->line('Registro de altas: '.count($events));
        $this->table(['Hora', 'Evento', 'Detalle'], array_map(fn (array $e): array => [$e['at'], $e['event'], $e['detail']], $events));

        $errors = array_values(array_filter($this->logLines('laravel', $since, $until, errorsOnly: true),
            fn (array $e): bool => collect(self::CLASSES)->contains(fn (string $class): bool => str_contains($e['raw'], $class))));
        $this->line('Errores en el código de altas: '.count($errors));
        $this->table(['Hora', 'Error', 'Dónde'], array_map(fn (array $e): array => [$e['at'], $e['class'], $e['where']], $errors));

        return self::SUCCESS;
    }

    /**
     * Log entries in the window. Each entry is one "[timestamp] env.LEVEL: message {json}" header plus any continuation
     * lines (a stack trace). Only the event name and the JSON's ids/keys/classes are shown — never a free-text message.
     *
     * @return list<array{at: string, event: string, detail: string, class: string, where: string, raw: string}>
     */
    private function logLines(string $prefix, CarbonImmutable $since, CarbonImmutable $until, bool $errorsOnly = false): array
    {
        $entries = [];
        // Where the configured channels write (signup → its own file, the main log → `single`/`daily`'s path).
        $base = $prefix === 'signup' ? (string) config('logging.channels.signup.path') : (string) config('logging.channels.single.path');
        foreach (File::glob(dirname($base).'/'.$prefix.'*.log') ?: [] as $file) {
            $current = null;
            foreach (preg_split('/\R/', (string) File::get($file)) ?: [] as $line) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(\w+): (.*)$/', $line, $m)) {
                    if ($current !== null) {
                        $entries[] = $current;
                    }
                    $current = ['at' => $m[1], 'level' => $m[2], 'head' => $m[3], 'raw' => $line];

                    continue;
                }
                if ($current !== null) {
                    $current['raw'] .= "\n".$line;
                }
            }
            if ($current !== null) {
                $entries[] = $current;
            }
        }

        $out = [];
        foreach ($entries as $entry) {
            $at = CarbonImmutable::parse($entry['at']);
            if ($at->lt($since) || $at->gt($until) || ($errorsOnly && ! in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true))) {
                continue;
            }
            $event = (string) strtok($entry['head'], ' {');
            // Monolog writes "message {context} [extra]" — the context is the first JSON object.
            $decoded = preg_match('/^\S+ (\{.*?\})(?: \[.*\])?\s*$/', $entry['head'], $j) ? json_decode($j[1], true) : null;
            $json = is_array($decoded) ? $decoded : [];
            $detail = [];
            foreach (['application_id', 'member_id', 'route', 'keys', 'error', 'operator_id'] as $key) {
                if (array_key_exists($key, $json)) {
                    $detail[] = $key.'='.(is_array($json[$key]) ? implode(',', array_map('strval', $json[$key])) : (string) $json[$key]);
                }
            }
            preg_match('/"exception":"\[object\] \(([A-Za-z0-9_\\\\]+)\(code: \d+\): .* at ([^)]+)\)/', $entry['raw'], $ex);
            $out[] = [
                'at' => $entry['at'],
                'event' => $event,
                'detail' => implode(' ', $detail),
                'class' => isset($ex[1]) ? class_basename(str_replace('\\\\', '\\', $ex[1])) : $event,
                'where' => isset($ex[2]) ? basename((string) preg_replace('/:\d+$/', '', $ex[2])).':'.(preg_match('/:(\d+)$/', $ex[2], $l) ? $l[1] : '') : '',
                'raw' => $entry['raw'],
            ];
        }

        return $out;
    }
}
