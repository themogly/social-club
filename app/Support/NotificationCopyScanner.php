<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The second, complementary localization gate (prompt 25). Where {@see LangKeys}
 * proves every translation KEY reaches both locale files, this proves no user-facing
 * alert / notification message SKIPS the translation layer entirely — a raw
 * natural-language string literal handed straight to a message sink (a Filament
 * notification `->title()` / `->body()`, or a counter `->flash()`) instead of
 * `__()` / `trans_choice()`.
 *
 * This is a different bug CLASS from the parity test, which by construction can only
 * see strings that already became keys — it can never see a string that was never
 * wrapped at all. That blind spot is exactly how the dashboard alerts shipped
 * Spanish-only. Tokeniser-based (not regex) so `->body($e->getMessage())`, closures,
 * concatenations and `__()` wrappers are told apart from a bare literal reliably.
 */
class NotificationCopyScanner
{
    /** Method calls whose first string argument is user-facing alert / notification copy. */
    public const SINKS = ['title', 'body', 'flash'];

    /**
     * Raw-literal violations across a root directory (defaults to app/).
     *
     * @return list<array{file: string, line: int, method: string, literal: string}>
     */
    public static function violations(?string $root = null): array
    {
        $root ??= app_path();
        $out = [];

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // The scanner's own source names the sink methods as strings — never scan it.
            if ($file->getFilename() === 'NotificationCopyScanner.php') {
                continue;
            }
            foreach (self::scan((string) File::get($file->getPathname())) as $hit) {
                $out[] = ['file' => $file->getPathname()] + $hit;
            }
        }

        return $out;
    }

    /**
     * Scan a single PHP source string. Public so a test can feed it a deliberate
     * regression snippet and prove the scan catches it.
     *
     * @return list<array{line: int, method: string, literal: string}>
     */
    public static function scan(string $php): array
    {
        $tokens = token_get_all($php);
        $out = [];

        foreach ($tokens as $i => $token) {
            // The shape we flag:  -> <sink> (  '<natural-language literal>'
            if (! is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $method = self::nextSignificant($tokens, $i);
            if ($method === null || ! is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
                continue;
            }
            $name = $tokens[$method][1];
            if (! in_array($name, self::SINKS, true)) {
                continue;
            }

            $paren = self::nextSignificant($tokens, $method);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }

            $arg = self::nextSignificant($tokens, $paren);
            // A variable ($e->getMessage()), a __()/trans_choice() call, a closure … all
            // present as something other than a bare quoted string here → not a violation.
            if ($arg === null || ! is_array($tokens[$arg]) || $tokens[$arg][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = self::unquote($tokens[$arg][1]);
            if (self::isNaturalLanguage($literal)) {
                $out[] = ['line' => $tokens[$arg][2], 'method' => $name, 'literal' => $literal];
            }
        }

        return $out;
    }

    /**
     * Index of the next non-trivia token after $from, or null at end of stream.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** A sentence, not a key/flag/format token: contains whitespace AND a letter. */
    private static function isNaturalLanguage(string $s): bool
    {
        return preg_match('/\s/', trim($s)) === 1 && preg_match('/\p{L}/u', $s) === 1;
    }

    /** Strip the surrounding quotes the tokeniser keeps, and unescape. */
    private static function unquote(string $raw): string
    {
        $inner = substr($raw, 1, -1);

        return $raw[0] === '"'
            ? stripcslashes($inner)
            : str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
    }
}
