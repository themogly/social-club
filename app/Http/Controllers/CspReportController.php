<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where the browser reports Content-Security-Policy violations (prompt 270).
 *
 * The policy shipped report-only with nowhere to report to, so violations only reached each device's console and
 * nobody could ever tell whether it was safe to enforce. This gives it a stream: one log line per report, with the
 * page and the blocked resource reduced to their path (a signed document URL's query carries its signature — never
 * logged). Bounded (8 KB), throttled on the route, no session, no CSRF (browsers send these without a token), and it
 * always answers 204 so a malformed report is never an error page.
 */
class CspReportController extends Controller
{
    private const MAX_BYTES = 8192;

    public function __invoke(Request $request): Response
    {
        $raw = substr((string) $request->getContent(), 0, self::MAX_BYTES);
        $payload = json_decode($raw, true);

        if (is_array($payload)) {
            // `report-uri` sends {"csp-report": {...}}; the Reporting API sends a list of {type, body}.
            $report = $payload['csp-report'] ?? ($payload[0]['body'] ?? null);

            if (is_array($report)) {
                Log::warning('csp.violation', [
                    'page' => $this->pathOnly($report['document-uri'] ?? $report['documentURL'] ?? null),
                    'directive' => Str::limit((string) ($report['effective-directive'] ?? $report['violated-directive'] ?? $report['effectiveDirective'] ?? ''), 100),
                    'blocked' => $this->pathOnly($report['blocked-uri'] ?? $report['blockedURL'] ?? null),
                ]);
            }
        }

        return response()->noContent();
    }

    private function pathOnly(mixed $url): string
    {
        if (! is_string($url) || $url === '') {
            return '';
        }

        $parts = parse_url($url);

        return Str::limit(is_array($parts) ? ($parts['scheme'] ?? '').(isset($parts['host']) ? '://'.$parts['host'] : '').($parts['path'] ?? '') : $url, 300);
    }
}
