<?php

namespace App\Console\Commands;

use App\Support\Mrz\MrzParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Prompt 128 — the measurement that must come FIRST. Point it at a folder of REAL ID/NIE photos and it reports
 * the MRZ read rate: what fraction OCR + {@see MrzParser} turns into a check-digit-VALID document. The prompt is
 * explicit that a feature reading "four times in ten" is worse than none, so this number gates the build — if it
 * is poor, the fix is capture UX (a framing guide, autocapture when sharp), NOT a better parser, and NOT a cloud
 * API (that would add a processor, an international transfer and a RAT entry for the most sensitive data the club
 * holds).
 *
 * Offline only: it shells out to a locally-installed `tesseract`. No image leaves the machine. It intentionally
 * does nothing with the photos beyond measuring — no storage, no prefill — because prefill is not to be built
 * until this number justifies it.
 */
class MeasureMrzReadRate extends Command
{
    protected $signature = 'id:mrz-read-rate {dir : Folder of real ID photos to measure against}';

    protected $description = 'Measure the offline MRZ read rate on a folder of real ID photos (prompt 128 gate)';

    public function handle(): int
    {
        $dir = (string) $this->argument('dir');

        if (! is_dir($dir)) {
            $this->error("Not a directory: {$dir}");

            return self::FAILURE;
        }

        $tesseract = trim((string) (Process::run('command -v tesseract')->output()));
        if ($tesseract === '') {
            $this->error('tesseract is not installed. Install it locally (brew install tesseract) — do NOT reach for a cloud OCR API (prompt 128: no processor / transfer / RAT for Article-9 data).');

            return self::FAILURE;
        }

        $images = collect(glob(rtrim($dir, '/').'/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) ?: []);
        if ($images->isEmpty()) {
            $this->error("No images (.jpg/.jpeg/.png) in {$dir}.");

            return self::FAILURE;
        }

        $parser = new MrzParser;
        $read = 0;
        $failures = [];

        foreach ($images as $image) {
            $text = trim((string) Process::run(['tesseract', $image, 'stdout', '--psm', '6'])->output());
            $result = $parser->parse($this->isolateMrz($text));

            if ($result !== null && $result['valid']) {
                $read++;
            } else {
                $failures[] = basename((string) $image);
            }
        }

        $total = $images->count();
        $rate = round($read / $total * 100, 1);

        $this->info("MRZ read rate: {$read}/{$total} ({$rate}%)");
        $this->line('A rate below ~90% means the answer is capture UX, not a better parser (prompt 128).');
        if ($failures !== []) {
            $this->line('Not read: '.implode(', ', array_slice($failures, 0, 20)).(count($failures) > 20 ? ' …' : ''));
        }

        return self::SUCCESS;
    }

    /** Keep only the lines that look like an MRZ (long runs of A–Z/0–9/<), the bottom of the document. */
    private function isolateMrz(string $text): string
    {
        $lines = array_filter(
            preg_split('/\r\n|\r|\n/', $text) ?: [],
            fn (string $l): bool => (bool) preg_match('/^[A-Z0-9<]{28,}$/', strtoupper(str_replace(' ', '', trim($l)))),
        );

        return implode("\n", array_map(fn (string $l): string => strtoupper(str_replace(' ', '', trim($l))), $lines));
    }
}
