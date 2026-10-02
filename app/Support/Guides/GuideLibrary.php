<?php

namespace App\Support\Guides;

use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Prompt 353 — the staff and manager guides, kept in the repo as Markdown (`resources/guides/en/{slug}.md`, images in
 * `resources/guides/img/{slug}/`) and shown by the app as web pages and a PDF from the same source. A deploy updates
 * what staff read; there is no file to re-send. When a change alters a screen a guide describes, the same branch updates
 * the guide (CLAUDE.md).
 *
 * Who sees what: `audience: staff` guides are for everyone signed in; `audience: managers` ones only for a person with
 * `panel.access` and `settings.manage` or `reports.view` (owners and managers). The *For managers* sections inside
 * staff guides stay — they are labelled.
 */
final class GuideLibrary
{
    /** Front matter keys every guide must carry. */
    public const KEYS = ['title', 'summary', 'audience', 'order', 'updated'];

    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const IMAGE = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpe?g|png|webp)$/';

    public static function root(): string
    {
        return resource_path('guides');
    }

    /** @return list<Guide> every guide, in `order` */
    public static function all(?string $root = null): array
    {
        $guides = [];
        foreach (glob(($root ?? self::root()).'/en/*.md') ?: [] as $path) {
            $guide = self::load($path);
            if ($guide !== null) {
                $guides[] = $guide;
            }
        }
        usort($guides, fn (Guide $a, Guide $b): int => [$a->order, $a->slug] <=> [$b->order, $b->slug]);

        return $guides;
    }

    public static function find(string $slug): ?Guide
    {
        if (preg_match(self::SLUG, $slug) !== 1) {
            return null;
        }
        $path = self::root().'/en/'.$slug.'.md';

        return is_file($path) ? self::load($path) : null;
    }

    /** The reader's level: `managers` for owners and managers, `staff` for anyone else signed in, null for nobody. */
    public static function levelFor(?User $user): ?string
    {
        if ($user === null || ! $user->active) {
            return null;
        }

        return $user->can('panel.access') && ($user->can('settings.manage') || $user->can('reports.view')) ? Guide::MANAGERS : Guide::STAFF;
    }

    public static function canRead(?User $user, Guide $guide): bool
    {
        $level = self::levelFor($user);

        return $level === Guide::MANAGERS || ($level === Guide::STAFF && ! $guide->forManagers());
    }

    /** @return list<Guide> */
    public static function visibleTo(?User $user): array
    {
        return array_values(array_filter(self::all(), fn (Guide $guide): bool => self::canRead($user, $guide)));
    }

    /** The absolute path of a guide's image, or null when the name is not a plain image file name or it is missing. */
    public static function imagePath(string $slug, string $file): ?string
    {
        if (preg_match(self::SLUG, $slug) !== 1 || preg_match(self::IMAGE, $file) !== 1) {
            return null;
        }
        $path = self::root().'/img/'.$slug.'/'.$file;

        return is_file($path) ? $path : null;
    }

    /** A short hash of the image's bytes — in its URL, so a refreshed screenshot is never served stale from a cache. */
    public static function imageHash(string $slug, string $file): string
    {
        $path = self::imagePath($slug, $file);

        return $path === null ? 'missing' : substr((string) md5_file($path), 0, 12);
    }

    /** The authorised URL of one of a guide's images, with its hash so a refreshed screenshot is never served stale. */
    public static function imageUrl(Guide $guide, string $file): string
    {
        return route('guides.image', ['guide' => $guide->slug, 'file' => $file, 'v' => self::imageHash($guide->slug, $file)]);
    }

    /**
     * What is wrong with the guides under `$root`: a missing or invalid front matter key, an image the text shows that
     * is not there. Empty when all is well — the structural test asserts that.
     *
     * @return list<string>
     */
    public static function problems(?string $root = null): array
    {
        $root ??= self::root();
        $problems = [];
        foreach (glob($root.'/en/*.md') ?: [] as $path) {
            $slug = basename($path, '.md');
            [$meta, $body] = self::split((string) file_get_contents($path));
            foreach (self::KEYS as $key) {
                if (blank($meta[$key] ?? null)) {
                    $problems[] = "{$slug}: front matter has no {$key}";
                }
            }
            if (isset($meta['audience']) && ! in_array($meta['audience'], [Guide::STAFF, Guide::MANAGERS], true)) {
                $problems[] = "{$slug}: audience must be staff or managers";
            }
            if (isset($meta['order']) && ! ctype_digit($meta['order'])) {
                $problems[] = "{$slug}: order must be a whole number";
            }
            if (isset($meta['updated']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $meta['updated']) !== 1) {
                $problems[] = "{$slug}: updated must be a date (YYYY-MM-DD)";
            }
            foreach (self::imagesIn($body) as $image) {
                if (! str_starts_with($image, 'img/'.$slug.'/') || ! is_file($root.'/'.$image)) {
                    $problems[] = "{$slug}: image {$image} is missing";
                }
            }
        }

        return $problems;
    }

    private static function load(string $path): ?Guide
    {
        $slug = basename($path, '.md');
        if (preg_match(self::SLUG, $slug) !== 1) {
            return null;
        }
        [$meta, $body] = self::split((string) file_get_contents($path));

        try {
            return new Guide(
                slug: $slug,
                title: (string) ($meta['title'] ?? $slug),
                summary: (string) ($meta['summary'] ?? ''),
                // Anything but a clear "staff" is treated as managers-only: a typo must never widen who reads it.
                audience: ($meta['audience'] ?? '') === Guide::STAFF ? Guide::STAFF : Guide::MANAGERS,
                order: (int) ($meta['order'] ?? 99),
                updated: CarbonImmutable::parse((string) ($meta['updated'] ?? '1970-01-01')),
                body: $body,
                images: self::imagesIn($body),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The front matter (simple `key: value` lines between `---` fences) and the body after it.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m) !== 1) {
            return [[], $raw];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv) === 1) {
                $meta[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }

        return [$meta, $m[2]];
    }

    /** @return list<string> the image paths the Markdown shows, in order */
    private static function imagesIn(string $body): array
    {
        preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', $body, $m);

        return array_values(array_unique($m[1]));
    }
}
