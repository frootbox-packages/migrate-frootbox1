<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\MigrateFrootbox1\Source;

/**
 * An address of the old website (aliases): a page or object, or a
 * redirect with status 301.
 */
final readonly class Alias
{
    public function __construct(
        public string $path,
        public int $itemId,
        /** The last part of the repository, e.g. Pages or Teasers. */
        public string $owner,
        public string $language,
        public int $status,
        public string $target,
        /** The title and description for search engines, set in the editor of Frootbox 1. */
        public string $seoTitle = '',
        public string $seoDescription = '',
        /** The page the address belongs to; for an object the page that shows it. */
        public int $pageId = 0,
    ) {}

    /**
     * The page this address leads to: the page itself, or for an object
     * (a contact person, an article) the page that shows it.
     */
    public function page(): ?int
    {
        $page = 'Pages' === $this->owner ? $this->itemId : $this->pageId;

        return $page > 0 ? $page : null;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }
}
