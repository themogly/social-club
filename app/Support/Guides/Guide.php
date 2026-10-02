<?php

namespace App\Support\Guides;

use Carbon\CarbonImmutable;

/**
 * Prompt 353 — one illustrated guide (`resources/guides/en/{slug}.md`): its front matter and its Markdown body.
 * Read from the repo on each request ({@see GuideLibrary}); nothing about it is stored in the database.
 */
final class Guide
{
    public const STAFF = 'staff';

    public const MANAGERS = 'managers';

    /**
     * @param  list<string>  $images  the `img/{slug}/…` paths the body references, in order
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $audience,
        public readonly int $order,
        public readonly CarbonImmutable $updated,
        public readonly string $body,
        public readonly array $images,
    ) {}

    public function forManagers(): bool
    {
        return $this->audience === self::MANAGERS;
    }

    /** A fingerprint of the text and every image it shows — the PDF is cached under it, and regenerated when it changes. */
    public function contentHash(): string
    {
        $parts = [$this->slug, $this->title, $this->updated->toDateString(), $this->body];
        foreach ($this->images as $image) {
            $parts[] = GuideLibrary::imageHash($this->slug, basename($image));
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 16);
    }
}
